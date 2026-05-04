/**
 * @file
 * dkan_drupal_ai_query chat widget.
 *
 * Network protocol: POST /api/dkan-ai-query/start (long-blocking), parallel
 * GET /api/dkan-ai-query/poll/{thread_id} every 500 ms. Status events drive
 * the progress pill, debug panel, and assistant text. Artifacts (tables,
 * chart specs) drive in-bubble rendering.
 *
 * Settings (via drupalSettings.dkanAiQuery): showModelSelector, showExamples,
 * showDebugPanel, saveChatHistory, showFollowUpSuggestions, showTableToggle,
 * showApiCall, showSql, showProvenance, showDownloadCsv, showCopyButtons.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const POLL_INTERVAL_MS = 500;

  // History sidebar pagination — server returns N at a time and a "Load more"
  // button at the foot of the list fetches the next page. Matches the
  // controller's default/cap (1..100) in ConversationController::list().
  const SIDEBAR_PAGE_SIZE = 25;

  // localStorage key for the collapsed/expanded state of the history sidebar.
  // Per-browser only — not synced server-side.
  const SIDEBAR_COLLAPSED_KEY = 'dkanAiQuery.sidebarCollapsed';

  // localStorage key for the collapsed/expanded state of the API playground
  // sidebar. Per-browser only — not synced server-side. Independent of the
  // playground "open" state (× still tears the playground down entirely).
  const PLAYGROUND_COLLAPSED_KEY = 'dkanAiQuery.playgroundCollapsed';

  // Tool names whose data artifact should render as a plain table+CSV with
  // simplified provenance — no API call / SQL / preview panels (those only
  // make sense for the two datastore-query tools).
  const SIMPLE_TABLE_TOOLS = new Set([
    'sample_rows',
    'distinct_values',
    'search_columns',
    'list_datasets',
    'list_distributions',
    'get_datastore_schema',
  ]);

  // Tools that can be replayed and edited in the right-side REST playground
  // sidebar. Each must have a `buildApiEquivalent()` branch that maps the
  // tool's input to a real DKAN REST endpoint. Tools without a public REST
  // analog (search_columns, compute_stats) or that are terminal/render-only
  // (refuse, create_chart) stay out.
  const PLAYGROUND_ELIGIBLE_TOOLS = new Set([
    'query_datastore',
    'query_datastore_join',
    'query_datastore_raw',
    'search_datasets',
    'list_datasets',
    'list_distributions',
    'sample_rows',
    'distinct_values',
    'get_datastore_schema',
    'get_datastore_stats',
    'get_data_dictionary',
  ]);

  // Cap displayed response body so a large datastore response (10k rows) doesn't
  // freeze the browser by stuffing 50 MB into a single <pre>.
  const PLAYGROUND_RESPONSE_DISPLAY_CAP = 500 * 1024;

  // Cap on per-widget run history. Five gives users enough to flip back
  // through a recent iteration cycle without dominating the sidebar.
  const PLAYGROUND_HISTORY_CAP = 5;

  // Languages offered in the playground's Code tab. cURL stays first as the
  // universal baseline; the rest are ordered by rough usage frequency among
  // DKAN's data-integration audience.
  const PLAYGROUND_CODE_LANGUAGES = [
    { id: 'curl',   label: 'cURL',       copyLabel: 'Copy cURL' },
    { id: 'httpie', label: 'HTTPie',     copyLabel: 'Copy HTTPie' },
    { id: 'python', label: 'Python',     copyLabel: 'Copy Python' },
    { id: 'js',     label: 'JavaScript', copyLabel: 'Copy JavaScript' },
    { id: 'php',    label: 'PHP',        copyLabel: 'Copy PHP' },
  ];

  // Cell text past this length is truncated in the table view; click expands.
  const CELL_TRUNCATE_LEN = 80;

  /**
   * Build a small down-chevron SVG node, sized to live inline beside button
   * text. Uses currentColor so it inherits the button's text color (and
   * automatically inverts when the button enters its .is-open active state).
   * The SVG is aria-hidden — state for assistive tech is conveyed through
   * the button's aria-expanded attribute, which the click handlers toggle.
   */
  function makeChevron() {
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 12 12');
    svg.setAttribute('width', '10');
    svg.setAttribute('height', '10');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('class', 'dkan-aiq-btn-chevron');
    const path = document.createElementNS(NS, 'path');
    path.setAttribute('d', 'M3 4.5l3 3 3-3');
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', 'currentColor');
    path.setAttribute('stroke-width', '1.5');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('stroke-linejoin', 'round');
    svg.appendChild(path);
    return svg;
  }

  // Friendly labels and gloss strings for the provenance panel. Untranslated
  // — the widget already ships untranslated UI strings ("Show table" etc.);
  // an i18n pass would be a separate phase.
  const TOOL_FRIENDLY_NAMES = {
    query_datastore: 'Datastore query',
    query_datastore_join: 'Datastore join query',
    query_datastore_raw: 'Datastore query (raw)',
    sample_rows: 'Sample of rows',
    distinct_values: 'Distinct values',
    search_columns: 'Column search',
    search_datasets: 'Dataset search',
    list_datasets: 'Dataset list',
    list_distributions: 'Distribution list',
    get_datastore_schema: 'Schema',
  };

  // One-sentence explainer per tool, rendered at the top of the result-details
  // panel so non-technical users understand where the data came from. Phrased
  // for an analyst audience: what the tool reads, what it returns, and any
  // important constraint (e.g. "in the database").
  const TOOL_DESCRIPTIONS = {
    query_datastore: 'Runs a structured query against one resource’s datastore table. Filters, sorts, and aggregations happen in the database; only matching rows come back.',
    query_datastore_join: 'Runs a structured query that joins two datastore tables on a shared column. The join happens in the database; only matching rows come back.',
    query_datastore_raw: 'Submits a raw DKAN DatastoreQuery payload — the agent reaches for this when the flat query tools can\'t express the shape (nested OR groups, three-way joins, compound expressions). Response shape matches the REST endpoint verbatim.',
    sample_rows: 'Returns a small random sample of rows from the resource so you can eyeball the data.',
    distinct_values: 'Lists the unique values that appear in one column of the resource.',
    search_columns: 'Searches column names (and optionally descriptions) across all dataset resources to find columns matching your keyword.',
    search_datasets: 'Searches the catalog by keyword and returns matching datasets with their identifier, title, description, and distribution count.',
    list_datasets: 'Returns a page of datasets from the catalog so you can browse what is available.',
    list_distributions: 'Lists the distributions — downloadable files or API endpoints — that belong to one dataset.',
    get_datastore_schema: 'Returns the column definitions for one resource: name, type, and (when available) a description from the data dictionary.',
  };

  // Friendly labels and intro blurbs for non-table tool calls surfaced in
  // the "Behind the scenes" disclosure.
  const AUX_TOOL_FRIENDLY_NAMES = {
    compute_stats: 'Statistics computed',
    get_data_dictionary: 'Data dictionary',
    get_datastore_stats: 'Column-level stats',
    get_datastore_schema: 'Schema',
    distinct_values: 'Distinct values',
  };

  const AUX_TOOL_DESCRIPTIONS = {
    compute_stats: 'The agent computed these statistics from the query results above (median, stddev, quartiles, etc.).',
    get_data_dictionary: 'Publisher-supplied field definitions for the resources the agent looked up.',
    get_datastore_stats: 'Per-column stats the agent peeked at while planning the query — null counts, distinct values, min/max.',
    get_datastore_schema: 'Column definitions the agent retrieved while planning the query — name, type, and (when available) description.',
    distinct_values: 'Unique values for a single column the agent looked up while filtering or exploring.',
  };

  const PROV_LABELS = {
    executed_at: 'When this ran',
    tool: 'What was queried',
    rows: 'Rows returned',
    sanity_flags: 'Things to know',
    query_summary: 'Query details',
  };

  const SANITY_GLOSS = {
    zero_rows: 'No matching rows.',
    row_cap_hit: 'Result was capped at the row limit you requested.',
    all_null_columns: 'These columns came back fully empty: ',
    coverage_warning: 'Coverage warning: ',
  };

  // [singular, plural] phrases per primary tool — used by the method-summary
  // line above the tables so phrasing reads naturally ("2 datastore queries"
  // not "2 query_datastores").
  const TOOL_PLURAL_NAMES = {
    query_datastore: ['datastore query', 'datastore queries'],
    query_datastore_join: ['datastore join', 'datastore joins'],
    query_datastore_raw: ['raw datastore query', 'raw datastore queries'],
    sample_rows: ['row sample', 'row samples'],
    search_columns: ['column search', 'column searches'],
    list_datasets: ['dataset list', 'dataset lists'],
    list_distributions: ['distribution list', 'distribution lists'],
  };

  /**
   * Render a one-line method summary from a bubble's accumulated artifacts:
   * "Answered using 2 datastore queries and 1 supporting lookup."
   *
   * Returns null when there are no countable artifacts (e.g. only a refusal
   * card or only debug snapshots), in which case the caller suppresses the
   * banner entirely.
   */
  function buildMethodSummary(artifacts) {
    const counts = {};
    let auxCount = 0;
    let chartCount = 0;
    artifacts.forEach((a) => {
      if (!a) return;
      if (a.type === 'data') {
        const t = a.tool || 'query_datastore';
        counts[t] = (counts[t] || 0) + 1;
      }
      else if (a.type === 'chart') {
        chartCount++;
      }
      else if (a.type === 'aux_tool') {
        auxCount++;
      }
    });
    const phrases = [];
    Object.keys(counts).forEach((tool) => {
      const n = counts[tool];
      const pair = TOOL_PLURAL_NAMES[tool] || [tool, tool + 's'];
      phrases.push(n + ' ' + (n === 1 ? pair[0] : pair[1]));
    });
    if (chartCount) {
      phrases.push(chartCount + ' chart' + (chartCount === 1 ? '' : 's'));
    }
    if (auxCount) {
      phrases.push(auxCount + ' supporting lookup' + (auxCount === 1 ? '' : 's'));
    }
    if (!phrases.length) return null;
    let joined;
    if (phrases.length === 1) joined = phrases[0];
    else if (phrases.length === 2) joined = phrases[0] + ' and ' + phrases[1];
    else joined = phrases.slice(0, -1).join(', ') + ', and ' + phrases[phrases.length - 1];
    return 'Answered using ' + joined + '.';
  }

  Drupal.behaviors.dkanAiQueryWidget = {
    attach: function (context) {
      once('dkan-aiq-widget', '.dkan-aiq-widget', context).forEach(function (root) {
        new Widget(root).init();
      });
    },
  };

  /**
   * One widget instance per .dkan-aiq-widget element.
   */
  function Widget(root) {
    this.root = root;
    const settings = drupalSettings.dkanAiQuery || {};
    this.settings = settings;
    this.datasetId = root.getAttribute('data-dataset-id') || settings.datasetId || '';
    this.historyEnabled = !!settings.saveChatHistory && !!settings.userAuthenticated;
    this.currentConversationId = null;
    this.activeRun = null;
    this.datasetMap = {};
    this.cachedConversations = [];
    this.sidebarOffset = 0;
    this.sidebarTotal = 0;
    this.sidebarHasMore = false;
    this.followUpPlaceholder = 'Ask a follow-up...';

    // Right-side REST API playground sidebar. Lazily created on first
    // open from a "Try in API playground" button on a datastore result.
    // Single-tab playground: opening a new request from a different bubble
    // replaces the editor (with a confirm-dirty prompt). Closing preserves
    // the last view so re-opening doesn't lose work.
    this.playground = {
      open: false,
      el: null,
      editor: null,
      responseHost: null,
      runBtn: null,
      resetBtn: null,
      openTabBtn: null,
      errorEl: null,
      methodEl: null,
      urlEl: null,
      historyEl: null,
      historyListEl: null,
      historySummaryEl: null,
      current: null,
      dirty: false,
    };

    // Last-picked language in the playground's Code tab. Persists across
    // playground open/close cycles within this widget instance only — not
    // across page reloads. Lives on the Widget root (not nested in
    // this.playground) so it survives if that state is ever reset.
    this.playgroundCodeLang = 'curl';

    // Last N runs in the playground, LRU. Persists across playground
    // close/reopen within this widget instance only — not across page
    // reloads. Each entry: {request, result, csrfToken, timestamp}.
    this.playgroundHistory = [];

    this.dom = {
      sidebar: root.querySelector('.dkan-aiq-sidebar'),
      sidebarList: root.querySelector('.dkan-aiq-sidebar-list'),
      sidebarSearch: root.querySelector('.dkan-aiq-sidebar-search-input'),
      sidebarFooter: root.querySelector('.dkan-aiq-sidebar-footer'),
      sidebarToggle: root.querySelector('.dkan-aiq-sidebar-toggle'),
      sidebarRailSummary: root.querySelector('.dkan-aiq-sidebar-rail-summary'),
      sidebarRailCount: root.querySelector('.dkan-aiq-sidebar-rail-count'),
      threadHeader: root.querySelector('.dkan-aiq-thread-header'),
      newBtn: root.querySelector('.dkan-aiq-new-conversation'),
      thread: root.querySelector('.dkan-aiq-thread'),
      status: root.querySelector('.dkan-aiq-status'),
      statusText: root.querySelector('.dkan-aiq-status-text'),
      error: root.querySelector('.dkan-aiq-error'),
      form: root.querySelector('.dkan-aiq-form'),
      input: root.querySelector('.dkan-aiq-input'),
      submit: root.querySelector('.dkan-aiq-submit'),
      datasetSelector: root.querySelector('.dkan-aiq-dataset-selector'),
      datasetSelect: root.querySelector('.dkan-aiq-dataset-select'),
      examples: root.querySelectorAll('.dkan-aiq-example'),
      examplesContainer: root.querySelector('.dkan-aiq-examples'),
      modelSelector: root.querySelector('.dkan-aiq-model-selector'),
      modelSelect: root.querySelector('.dkan-aiq-model-select'),
      debugPanel: root.querySelector('.dkan-aiq-debug'),
      debugLog: root.querySelector('.dkan-aiq-debug-log'),
    };
  }

  Widget.prototype.init = function () {
    this.applyVisibilityToggles();
    this.defaultPlaceholder = this.dom.input ? (this.dom.input.placeholder || '') : '';
    this.defaultExamplesHtml = this.dom.examplesContainer
      ? this.dom.examplesContainer.innerHTML
      : '';
    this.populateModelSelector();
    this.bindForm();
    this.bindExamples();
    this.bindNewConversation();
    // Prime the CSRF token so the first /start, /pin, or DELETE doesn't pay
    // an extra serialized round trip. Cached as a thenable so concurrent
    // submits share the same in-flight fetch.
    this.csrfToken = ensureCsrfToken();
    if (this.historyEnabled) {
      this.root.classList.add('dkan-aiq-widget--with-sidebar');
      this.dom.sidebar.hidden = false;
      this.bindSidebar();
      this.bindSidebarToggle();
      this.applySidebarCollapsedState();
      this.refreshSidebar();
    }
    this.updateThreadHeader();
    if (!this.datasetId) {
      this.populateDatasetSelector();
    }
    else if (this.historyEnabled) {
      // Single-dataset mode: still need the dataset map for sidebar labels.
      this.fetchDatasetMap();
    }
  };

  Widget.prototype.fetchDatasetMap = function () {
    fetchAllDatasets()
      .then((datasets) => {
        datasets.forEach((ds) => {
          if (ds && ds.identifier) {
            this.datasetMap[ds.identifier] = ds.title || ds.identifier;
          }
        });
        if (this.cachedConversations.length) {
          // Re-render with already-loaded conversations + current pagination
          // total so the dataset labels (now in datasetMap) appear.
          this.renderSidebar({ items: this.cachedConversations.slice(), total: this.sidebarTotal }, false);
        }
      });
  };

  Widget.prototype.applyVisibilityToggles = function () {
    const s = this.settings;
    if (!s.showExamples && this.dom.examples.length) {
      const wrap = this.root.querySelector('.dkan-aiq-examples');
      if (wrap) {
        wrap.hidden = true;
      }
    }
    if (!s.showModelSelector && this.dom.modelSelector) {
      this.dom.modelSelector.hidden = true;
    }
    if (!s.showDebugPanel && this.dom.debugPanel) {
      this.dom.debugPanel.hidden = true;
    }
  };

  Widget.prototype.populateModelSelector = function () {
    if (!this.dom.modelSelect) {
      return;
    }
    // settings.models is the live `{provider__model: "Provider - Model"}`
    // map produced by AiProviderPluginManager::getSimpleProviderModelOptions.
    const models = this.settings.models || {};
    const keys = Object.keys(models);
    if (!keys.length) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'No models available — configure a provider';
      this.dom.modelSelect.appendChild(opt);
      this.dom.modelSelect.disabled = true;
      return;
    }
    const groups = {};
    keys.forEach((value) => {
      const idx = value.indexOf('__');
      const providerId = idx > -1 ? value.slice(0, idx) : 'other';
      if (!groups[providerId]) {
        groups[providerId] = [];
      }
      groups[providerId].push(value);
    });
    const defaultValue = this.settings.defaultModel || '';
    Object.keys(groups).sort().forEach((providerId) => {
      const group = document.createElement('optgroup');
      group.label = providerId.charAt(0).toUpperCase() + providerId.slice(1);
      groups[providerId].forEach((value) => {
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = models[value].replace(providerId.charAt(0).toUpperCase() + providerId.slice(1) + ' - ', '');
        if (value === defaultValue) {
          opt.selected = true;
        }
        group.appendChild(opt);
      });
      this.dom.modelSelect.appendChild(group);
    });
  };

  Widget.prototype.populateDatasetSelector = function () {
    if (!this.dom.datasetSelect) {
      return;
    }
    fetchAllDatasets()
      .then((datasets) => {
        datasets.forEach((ds) => {
          if (!ds || !ds.identifier) {
            return;
          }
          this.datasetMap[ds.identifier] = ds.title || ds.identifier;
          const opt = document.createElement('option');
          opt.value = ds.identifier;
          opt.textContent = ds.title || ds.identifier;
          this.dom.datasetSelect.appendChild(opt);
        });
        if (this.cachedConversations.length) {
          // Re-render with already-loaded conversations + current pagination
          // total so the dataset labels (now in datasetMap) appear.
          this.renderSidebar({ items: this.cachedConversations.slice(), total: this.sidebarTotal }, false);
        }
      });
  };

  /**
   * Cache for the Drupal session token, fetched once per page load.
   *
   * @var {Promise<string>|null}
   */
  let csrfTokenPromise = null;

  /**
   * Resolve a CSRF token via /session/token. Cached for the page lifetime.
   */
  function ensureCsrfToken() {
    if (csrfTokenPromise) {
      return csrfTokenPromise;
    }
    csrfTokenPromise = fetch('/session/token', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .catch(function () { return ''; });
    return csrfTokenPromise;
  }

  /**
   * Page through the metastore catalog endpoint until exhausted.
   *
   * Returns a flat array of dataset summaries. Caps at MAX_DATASETS to
   * avoid runaway requests on misbehaving sites.
   */
  function fetchAllDatasets() {
    const MAX_DATASETS = 2000;
    const PAGE_SIZE = 100;
    const out = [];
    function loadPage(offset) {
      return fetch('/api/1/metastore/schemas/dataset/items?limit=' + PAGE_SIZE + '&offset=' + offset, {
        credentials: 'same-origin',
      })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (page) {
          if (!Array.isArray(page) || page.length === 0) {
            return out;
          }
          for (let i = 0; i < page.length; i++) {
            out.push(page[i]);
          }
          if (page.length < PAGE_SIZE || out.length >= MAX_DATASETS) {
            return out;
          }
          return loadPage(offset + page.length);
        })
        .catch(function () { return out; });
    }
    return loadPage(0);
  }

  Widget.prototype.bindForm = function () {
    this.dom.form.addEventListener('submit', (evt) => {
      evt.preventDefault();
      const question = this.dom.input.value.trim();
      if (!question) {
        return;
      }
      this.askQuestion(question);
    });
    // Auto-resize textarea.
    this.dom.input.addEventListener('input', () => {
      this.dom.input.style.height = 'auto';
      this.dom.input.style.height = Math.min(this.dom.input.scrollHeight, 200) + 'px';
    });
  };

  Widget.prototype.bindExamples = function () {
    if (!this.dom.examplesContainer) {
      return;
    }
    this.dom.examplesContainer.querySelectorAll('.dkan-aiq-example').forEach((btn) => {
      btn.addEventListener('click', () => {
        const q = btn.getAttribute('data-question') || btn.textContent;
        this.askQuestion(q);
      });
    });
  };

  Widget.prototype.resetExamplesToDefault = function () {
    if (!this.dom.examplesContainer) {
      return;
    }
    this.dom.examplesContainer.innerHTML = this.defaultExamplesHtml;
    this.dom.examplesContainer.hidden = false;
    this.bindExamples();
  };

  Widget.prototype.updateThreadHeader = function () {
    if (!this.dom.threadHeader) {
      return;
    }
    this.dom.threadHeader.hidden = this.dom.thread.children.length === 0;
  };

  Widget.prototype.bindNewConversation = function () {
    if (!this.dom.newBtn) {
      return;
    }
    this.dom.newBtn.addEventListener('click', () => {
      this.currentConversationId = null;
      this.dom.thread.innerHTML = '';
      this.dom.error.hidden = true;
      this.dom.input.value = '';
      this.dom.input.placeholder = this.defaultPlaceholder;
      this.resetExamplesToDefault();
      this.updateThreadHeader();
      // Clear debug panel so the prior conversation's tool calls don't
      // appear next to the first response of this new conversation.
      this.debugReset();
      if (this.dom.sidebarList) {
        this.dom.sidebarList
          .querySelectorAll('.dkan-aiq-sidebar-entry--active')
          .forEach((e) => e.classList.remove('dkan-aiq-sidebar-entry--active'));
      }
      this.dom.input.focus();
    });
  };

  Widget.prototype.bindSidebar = function () {
    if (!this.dom.sidebarSearch) {
      return;
    }
    // Filter operates only over conversations already loaded into the DOM.
    // Pages fetched after a search begins are appended unfiltered; users can
    // re-type to re-apply. Server-side search is intentionally out of scope.
    this.dom.sidebarSearch.addEventListener('input', () => {
      const term = this.dom.sidebarSearch.value.toLowerCase();
      this.dom.sidebarList.querySelectorAll('.dkan-aiq-sidebar-entry').forEach((item) => {
        const title = item.getAttribute('data-title') || '';
        item.style.display = title.toLowerCase().includes(term) ? '' : 'none';
      });
    });
  };

  Widget.prototype.bindSidebarToggle = function () {
    if (!this.dom.sidebarToggle) {
      return;
    }
    const toggle = () => {
      const collapsed = !this.dom.sidebar.classList.contains('dkan-aiq-sidebar--collapsed');
      this.setSidebarCollapsed(collapsed);
      try {
        localStorage.setItem(SIDEBAR_COLLAPSED_KEY, collapsed ? '1' : '0');
      }
      catch (e) {
        // Safari private mode can throw QuotaExceededError; collapse still works
        // for the current session, just not persisted.
      }
    };
    this.dom.sidebarToggle.addEventListener('click', toggle);
    // Rail summary (icon + count badge) is only visible when collapsed and
    // shares the same toggle behavior so the whole rail acts as one expand
    // target.
    if (this.dom.sidebarRailSummary) {
      this.dom.sidebarRailSummary.addEventListener('click', toggle);
    }
  };

  Widget.prototype.applySidebarCollapsedState = function () {
    let collapsed = false;
    try {
      collapsed = localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1';
    }
    catch (e) {
      // localStorage may be unavailable; default to expanded.
    }
    this.setSidebarCollapsed(collapsed);
  };

  Widget.prototype.setSidebarCollapsed = function (collapsed) {
    if (!this.dom.sidebar) {
      return;
    }
    this.dom.sidebar.classList.toggle('dkan-aiq-sidebar--collapsed', collapsed);
    if (this.dom.sidebarToggle) {
      this.dom.sidebarToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      this.dom.sidebarToggle.setAttribute(
        'aria-label',
        collapsed ? 'Expand history sidebar' : 'Collapse history sidebar'
      );
    }
  };

  Widget.prototype.refreshSidebar = function () {
    if (!this.historyEnabled) {
      return;
    }
    this.sidebarOffset = 0;
    fetch('/api/dkan-ai-query/conversations?offset=0&limit=' + SIDEBAR_PAGE_SIZE, {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then((payload) => { this.renderSidebar(payload, false); })
      .catch(() => {});
  };

  Widget.prototype.loadMoreSidebar = function () {
    if (!this.historyEnabled || !this.sidebarHasMore) {
      return;
    }
    const nextOffset = this.sidebarOffset + SIDEBAR_PAGE_SIZE;
    fetch('/api/dkan-ai-query/conversations?offset=' + nextOffset + '&limit=' + SIDEBAR_PAGE_SIZE, {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then((payload) => {
        this.sidebarOffset = nextOffset;
        this.renderSidebar(payload, true);
      })
      .catch(() => {});
  };

  Widget.prototype.renderSidebar = function (payload, append) {
    const items = (payload && Array.isArray(payload.items)) ? payload.items : [];
    this.sidebarTotal = (payload && typeof payload.total === 'number') ? payload.total : items.length;

    const oldLoadMore = this.dom.sidebarList.querySelector('.dkan-aiq-sidebar-load-more');
    if (oldLoadMore) {
      oldLoadMore.remove();
    }

    if (!append) {
      this.cachedConversations = items.slice();
      this.dom.sidebarList.innerHTML = '';
    }
    else {
      const empty = this.dom.sidebarList.querySelector('.dkan-aiq-sidebar-empty');
      if (empty) {
        empty.remove();
      }
      this.cachedConversations = this.cachedConversations.concat(items);
    }

    if (!this.cachedConversations.length) {
      const empty = document.createElement('div');
      empty.className = 'dkan-aiq-sidebar-empty';
      empty.textContent = 'No conversations yet.';
      this.dom.sidebarList.appendChild(empty);
    }
    else {
      items.forEach((conv) => {
        this.dom.sidebarList.appendChild(this.buildSidebarEntry(conv));
      });
    }

    this.sidebarHasMore = this.cachedConversations.length < this.sidebarTotal;
    if (this.sidebarHasMore) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'dkan-aiq-sidebar-load-more';
      btn.textContent = 'Load more';
      btn.addEventListener('click', () => { this.loadMoreSidebar(); });
      this.dom.sidebarList.appendChild(btn);
    }

    this.updateSidebarFooter();
  };

  Widget.prototype.updateSidebarFooter = function () {
    const loaded = this.cachedConversations.length;
    const total = this.sidebarTotal;

    if (this.dom.sidebarRailCount) {
      this.dom.sidebarRailCount.textContent = String(total);
    }
    if (this.dom.sidebarRailSummary) {
      this.dom.sidebarRailSummary.setAttribute('data-count', String(total));
      this.dom.sidebarRailSummary.setAttribute(
        'aria-label',
        total
          ? 'Expand history sidebar (' + total + ' conversation' + (total !== 1 ? 's' : '') + ')'
          : 'Expand history sidebar'
      );
    }

    if (!this.dom.sidebarFooter) {
      return;
    }
    if (!total) {
      this.dom.sidebarFooter.textContent = '';
      return;
    }
    if (loaded >= total) {
      this.dom.sidebarFooter.textContent = total + ' conversation' + (total !== 1 ? 's' : '');
    }
    else {
      this.dom.sidebarFooter.textContent = loaded + ' of ' + total + ' conversations';
    }
  };

  Widget.prototype.buildSidebarEntry = function (conv) {
    const entry = document.createElement('div');
    let classes = 'dkan-aiq-sidebar-entry';
    if (conv.pinned) {
      classes += ' dkan-aiq-sidebar-entry--pinned';
    }
    if (this.currentConversationId && conv.id === this.currentConversationId) {
      classes += ' dkan-aiq-sidebar-entry--active';
    }
    entry.className = classes;
    entry.setAttribute('data-id', String(conv.id));
    entry.setAttribute('data-title', conv.title || '');

    const title = document.createElement('div');
    title.className = 'dkan-aiq-sidebar-title';
    title.textContent = conv.title || '(untitled)';
    entry.appendChild(title);

    if (conv.dataset_id) {
      const dsLabel = document.createElement('div');
      dsLabel.className = 'dkan-aiq-sidebar-dataset';
      dsLabel.textContent = this.datasetMap[conv.dataset_id] || conv.dataset_id;
      entry.appendChild(dsLabel);
    }

    const meta = document.createElement('div');
    meta.className = 'dkan-aiq-sidebar-meta';

    const date = new Date(conv.changed * 1000);
    const dateStr = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    const dateSpan = document.createElement('span');
    dateSpan.textContent = dateStr;
    meta.appendChild(dateSpan);

    const actions = document.createElement('span');
    actions.className = 'dkan-aiq-sidebar-actions';

    const pinBtn = document.createElement('button');
    pinBtn.type = 'button';
    pinBtn.className = 'dkan-aiq-sidebar-pin';
    pinBtn.textContent = conv.pinned ? '★' : '☆';
    pinBtn.title = conv.pinned ? 'Unpin' : 'Pin';
    pinBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      ensureCsrfToken().then((token) => fetch('/api/dkan-ai-query/conversations/' + conv.id + '/pin', {
        method: 'POST',
        headers: { 'X-CSRF-Token': token },
        credentials: 'same-origin',
      }))
        .then((r) => r.json())
        .then((result) => {
          conv.pinned = !!result.pinned;
          pinBtn.textContent = conv.pinned ? '★' : '☆';
          pinBtn.title = conv.pinned ? 'Unpin' : 'Pin';
          entry.classList.toggle('dkan-aiq-sidebar-entry--pinned', conv.pinned);
        });
    });
    actions.appendChild(pinBtn);

    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'dkan-aiq-sidebar-delete';
    delBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
    delBtn.title = 'Delete';
    delBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (!window.confirm('Delete this conversation?')) {
        return;
      }
      ensureCsrfToken().then((token) => fetch('/api/dkan-ai-query/conversations/' + conv.id, {
        method: 'DELETE',
        headers: { 'X-CSRF-Token': token },
        credentials: 'same-origin',
      })).then(() => {
        this.cachedConversations = this.cachedConversations.filter((c) => c.id !== conv.id);
        entry.remove();
        if (this.currentConversationId === conv.id) {
          this.currentConversationId = null;
          this.dom.thread.innerHTML = '';
          this.dom.input.placeholder = this.defaultPlaceholder;
        }
        if (this.sidebarTotal > 0) {
          this.sidebarTotal -= 1;
        }
        this.sidebarHasMore = this.cachedConversations.length < this.sidebarTotal;
        if (!this.sidebarHasMore) {
          const btn = this.dom.sidebarList.querySelector('.dkan-aiq-sidebar-load-more');
          if (btn) {
            btn.remove();
          }
        }
        this.updateSidebarFooter();
      });
    });
    actions.appendChild(delBtn);

    meta.appendChild(actions);
    entry.appendChild(meta);

    entry.addEventListener('click', () => { this.loadConversation(conv.id); });

    return entry;
  };

  Widget.prototype.loadConversation = function (id) {
    fetch('/api/dkan-ai-query/conversations/' + id, {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then((full) => {
        this.currentConversationId = full.id;
        this.dom.thread.innerHTML = '';
        this.dom.error.hidden = true;
        this.dom.input.placeholder = this.followUpPlaceholder;
        if (this.dom.examplesContainer) {
          this.dom.examplesContainer.innerHTML = '';
          this.dom.examplesContainer.hidden = true;
        }
        // Sync the dataset selector so the next /start in this conversation
        // is scoped to the dataset the conversation was originally about.
        // No-op in single-dataset block placements (no selector rendered).
        if (this.dom.datasetSelect) {
          this.dom.datasetSelect.value = full.dataset_id || '';
        }
        // Reset the debug panel so stale entries from a prior question or
        // conversation don't bleed into the replay below.
        this.debugReset();
        const messages = full.messages || [];
        let lastAssistantArtifacts = null;
        messages.forEach((m) => {
          if (m.role === 'user') {
            this.appendUserBubble(m.content);
          }
          else if (m.role === 'assistant') {
            const bubble = this.appendAssistantBubble();
            this.renderAssistantText(bubble, m.content);
            (m.artifacts || []).forEach((a) => this.renderArtifactInBubble(bubble, a));
            lastAssistantArtifacts = m.artifacts || [];
          }
        });
        // Repopulate the global debug panel from the most recent assistant
        // turn's persisted tool_call artifacts. Earlier turns' tool calls
        // are intentionally not replayed — the panel mirrors the latest
        // result, matching the live-question UX.
        if (lastAssistantArtifacts) {
          this.replayDebugFromArtifacts(lastAssistantArtifacts);
        }
        this.updateThreadHeader();
        this.refreshSidebar();
      });
  };

  /**
   * Repopulate the debug panel from a message's persisted tool_call records.
   *
   * Each `tool_call` artifact is run through the same DOM that
   * `debugToolStarted` + `debugToolFinished` build for live events, so the
   * replayed view is visually indistinguishable from the live one.
   */
  Widget.prototype.replayDebugFromArtifacts = function (artifacts) {
    if (!this.dom.debugLog || this.dom.debugPanel.hidden) {
      return;
    }
    artifacts.forEach((a) => {
      if (!a || a.type !== 'tool_call') {
        return;
      }
      this.debugToolStarted({
        tool_name: a.tool_name || '',
        tool_input: a.tool_input || '',
        tool_id: 'replay-' + this.debugToolCount,
      });
      this.debugToolFinished({
        tool_name: a.tool_name || '',
        tool_results: a.tool_results || '',
        tool_id: 'replay-' + (this.debugToolCount),
      });
    });
    this.debugFooter();
  };

  Widget.prototype.askQuestion = function (question) {
    if (this.activeRun) {
      return;
    }
    this.dom.error.hidden = true;
    this.dom.thread
      .querySelectorAll('.dkan-aiq-refusal:not(.dkan-aiq-refusal--historical)')
      .forEach((el) => el.classList.add('dkan-aiq-refusal--historical'));
    this.appendUserBubble(question);
    const bubble = this.appendAssistantBubble();
    const threadId = 'aiq-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
    this.dom.input.value = '';
    this.dom.input.placeholder = this.followUpPlaceholder;
    this.dom.submit.disabled = true;
    this.setStatus('Thinking…');
    this.debugReset();

    const seen = { events: 0, artifacts: 0 };
    let lastIterationText = '';
    const renderText = () => this.renderAssistantText(bubble, lastIterationText);

    const handleEvent = (ev) => {
      const t = ev.type || '';
      if (t === 'tool_started') {
        const fb = ev.tool_feedback_message || ('Calling ' + (ev.tool_name || 'tool') + '…');
        this.setStatus(fb);
        this.debugToolStarted(ev);
      }
      else if (t === 'tool_finished') {
        this.setStatus('Reading results…');
        this.debugToolFinished(ev);
      }
      else if (t === 'text_generated') {
        lastIterationText = ev.text_response || '';
        renderText();
      }
      else if (t === 'agent_iteration') {
        this.debugIteration(ev);
      }
      else if (t === 'ai_provider_response') {
        this.debugProviderResponse(ev);
      }
    };

    const dataset = this.datasetId || (this.dom.datasetSelect ? this.dom.datasetSelect.value : '');
    const model = this.dom.modelSelect ? this.dom.modelSelect.value : '';
    const body = JSON.stringify({
      question: question,
      thread_id: threadId,
      dataset_id: dataset || null,
      model: model || null,
      conversation_id: this.currentConversationId || null,
    });

    const pollHandle = setInterval(() => {
      fetch('/api/dkan-ai-query/poll/' + encodeURIComponent(threadId), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
      })
        .then(function (r) { return r.json(); })
        .then((json) => {
          const events = json.events || [];
          for (let i = seen.events; i < events.length; i++) {
            handleEvent(events[i]);
          }
          seen.events = events.length;

          const artifacts = json.artifacts || [];
          for (let i = seen.artifacts; i < artifacts.length; i++) {
            this.renderArtifactInBubble(bubble, artifacts[i]);
          }
          seen.artifacts = artifacts.length;
        })
        .catch(() => {});
    }, POLL_INTERVAL_MS);
    this.activeRun = pollHandle;

    ensureCsrfToken().then((token) => fetch('/api/dkan-ai-query/start', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': token,
      },
      body: body,
      credentials: 'same-origin',
    }))
      .then(function (r) {
        return r.json().then(function (j) { return { ok: r.ok, body: j }; });
      })
      .then((resp) => {
        clearInterval(pollHandle);
        this.activeRun = null;
        if (resp.ok) {
          // Safety net: if the assistant bubble was detached mid-request (race
          // with sidebar / new-conversation reset, or a layout reflow), the
          // user would otherwise see no answer despite the start succeeding.
          // Force a reload of the saved conversation so the result is visible.
          if (!bubble.isConnected && resp.body.conversation_id) {
            this.currentConversationId = resp.body.conversation_id;
            this.refreshSidebar();
            this.loadConversation(resp.body.conversation_id);
            this.debugFooter();
            this.setStatus('');
            this.dom.submit.disabled = false;
            this.dom.input.focus();
            return;
          }
          // Refusal artifacts already render their own card via polling, so
          // don't show "(no answer)" on top of one.
          const hasRefusal = !!bubble.querySelector('.dkan-aiq-refusal');
          this.renderAssistantText(bubble, resp.body.answer || lastIterationText || (hasRefusal ? '' : '(no answer)'));
          if (resp.body.conversation_id) {
            this.currentConversationId = resp.body.conversation_id;
            this.refreshSidebar();
          }
          if (this.settings.showFollowUpSuggestions !== false && Array.isArray(resp.body.suggestions)) {
            this.renderFollowUpSuggestions(resp.body.suggestions);
          }
        }
        else {
          this.dom.error.textContent = resp.body.error || 'An error occurred.';
          this.dom.error.hidden = false;
          if (bubble.parentElement) {
            bubble.parentElement.remove();
          }
          this.updateThreadHeader();
        }
        this.debugFooter();
        this.setStatus('');
        this.dom.submit.disabled = false;
        this.dom.input.focus();
      })
      .catch((err) => {
        clearInterval(pollHandle);
        this.activeRun = null;
        this.dom.error.textContent = err.message;
        this.dom.error.hidden = false;
        if (bubble.parentElement) {
          bubble.parentElement.remove();
        }
        this.updateThreadHeader();
        this.setStatus('');
        this.dom.submit.disabled = false;
      });
  };

  Widget.prototype.setStatus = function (msg) {
    if (msg) {
      this.dom.statusText.textContent = msg;
      this.dom.status.hidden = false;
    }
    else {
      this.dom.status.hidden = true;
      this.dom.statusText.textContent = '';
    }
  };

  Widget.prototype.appendUserBubble = function (text) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-message dkan-aiq-message-user';
    const bubble = document.createElement('div');
    bubble.className = 'dkan-aiq-bubble';
    bubble.textContent = text;
    wrap.appendChild(bubble);
    this.dom.thread.appendChild(wrap);
    this.updateThreadHeader();
    this.scrollToBottom();
    return bubble;
  };

  Widget.prototype.appendAssistantBubble = function () {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-message dkan-aiq-message-assistant';
    const bubble = document.createElement('div');
    bubble.className = 'dkan-aiq-bubble';
    const textEl = document.createElement('div');
    textEl.className = 'dkan-aiq-bubble-text';
    bubble.appendChild(textEl);
    wrap.appendChild(bubble);
    this.dom.thread.appendChild(wrap);
    this.scrollToBottom();
    return bubble;
  };

  Widget.prototype.renderAssistantText = function (bubble, text) {
    const textEl = bubble.querySelector('.dkan-aiq-bubble-text');
    if (!textEl) {
      return;
    }
    if (typeof window.marked !== 'undefined') {
      try {
        textEl.innerHTML = window.marked.parse(text || '');
        return;
      }
      catch (e) {
        // fall through
      }
    }
    textEl.textContent = text || '';
  };

  Widget.prototype.renderArtifactInBubble = function (bubble, artifact) {
    if (artifact.type === 'data') {
      this.renderTableInBubble(bubble, artifact);
    }
    else if (artifact.type === 'chart') {
      this.renderChartInBubble(bubble, artifact);
    }
    else if (artifact.type === 'refusal') {
      this.renderRefusalInBubble(bubble, artifact);
    }
    else if (artifact.type === 'aux_tool') {
      // Admin-gated; defaults to off in QueryWidgetBlock so public widgets
      // stay clean. When on, the agent's behind-the-scenes tool calls
      // (compute_stats, data dictionary, column stats) get a collapsed
      // disclosure under the main answer.
      if ((this.settings || {}).showAuxToolCalls === false) {
        return;
      }
      this.renderAuxToolInBubble(bubble, artifact);
    }
    // type === 'tool_call' is debug-panel-only; handled by replayDebugFromMessage.

    // Track every domain-relevant artifact so the method-summary line above
    // the tables can recompute its phrasing as artifacts stream in.
    if (artifact.type === 'data' || artifact.type === 'chart' || artifact.type === 'aux_tool') {
      if (!bubble.__methodArtifacts) bubble.__methodArtifacts = [];
      bubble.__methodArtifacts.push(artifact);
      this.updateMethodSummary(bubble);
    }
  };

  /**
   * Find-or-create a single `.dkan-aiq-method-summary` line on the bubble
   * (placed right after the prose text, before any tables) and refresh its
   * text from the bubble's accumulated artifacts. Suppressed when the
   * summary collapses to nothing (e.g. only a refusal card).
   */
  Widget.prototype.updateMethodSummary = function (bubble) {
    const artifacts = bubble.__methodArtifacts || [];
    const text = (this.settings || {}).showMethodSummary === false
      ? null
      : buildMethodSummary(artifacts);
    let line = bubble.querySelector(':scope > .dkan-aiq-method-summary');
    if (!text) {
      if (line) line.remove();
      return;
    }
    if (!line) {
      line = document.createElement('div');
      line.className = 'dkan-aiq-method-summary';
      // Insert directly after the prose text node so the summary is the
      // visual divider between answer and tables.
      const textEl = bubble.querySelector(':scope > .dkan-aiq-bubble-text');
      if (textEl && textEl.nextSibling) {
        bubble.insertBefore(line, textEl.nextSibling);
      }
      else {
        bubble.appendChild(line);
      }
    }
    line.textContent = text;
  };

  Widget.prototype.renderTableInBubble = function (bubble, artifact) {
    const rows = artifact.rows || [];
    const provenance = artifact.provenance || null;
    if (rows.length === 0 && !provenance) {
      return;
    }
    const toolName = artifact.tool || 'query_datastore';
    const isSimpleTool = SIMPLE_TABLE_TOOLS.has(toolName);

    // Admin escape hatch: when the umbrella toggle is off, suppress the
    // table for the simple-tool family entirely. Datastore queries always
    // render — they're the headline feature.
    if (isSimpleTool && (this.settings || {}).showSimpleTableArtifacts === false) {
      return;
    }
    // Honor the capture-time column hint (search_columns, list_datasets, etc.)
    // so columns appear in a stable order regardless of map iteration. Falls
    // back to the first row's keys for tools that don't supply a hint.
    let cols = [];
    if (Array.isArray(artifact.columns_hint) && artifact.columns_hint.length) {
      cols = artifact.columns_hint.slice();
    }
    else if (rows.length) {
      cols = Object.keys(rows[0] || {});
    }

    // Build a column-name => type map. For datastore queries the schema
    // travels alongside the rows; for get_datastore_schema each row IS a
    // column descriptor, so we read type from the row itself. Other tools
    // simply have no type info and the chip is suppressed.
    const colTypes = {};
    if (artifact.schema && typeof artifact.schema === 'object') {
      Object.keys(artifact.schema).forEach((k) => {
        const entry = artifact.schema[k];
        if (entry && typeof entry === 'object' && entry.type) {
          colTypes[k] = entry.type;
        }
      });
    }
    if (toolName === 'get_datastore_schema') {
      rows.forEach((row) => {
        if (row && row.name && row.type) {
          colTypes[row.name] = row.type;
        }
      });
    }

    const input = artifact.input || null;

    // SQL preview and the inline "Show API call" panel only apply to the two
    // datastore-query tools. The structured `apiCall` itself is built for
    // every playground-eligible tool, so the playground sidebar can replay
    // simple-table tools (sample_rows, list_datasets, etc.) too.
    const apiPrimary = input ? (input.distribution_uuid || input.resolved_resource_id || input.resource_id || '') : '';
    const apiJoin = input ? (input.join_distribution_uuid || input.resolved_join_resource_id || input.join_resource_id || '') : '';
    const sqlPrimary = (!isSimpleTool && input) ? (input.resolved_resource_id || input.resource_id || '') : '';
    const sqlJoin = (!isSimpleTool && input) ? (input.resolved_join_resource_id || input.join_resource_id || '') : '';
    const apiCall = (PLAYGROUND_ELIGIBLE_TOOLS.has(toolName) && input)
      ? buildApiEquivalent(toolName, input, apiPrimary, apiJoin)
      : null;
    // Inline text-panel preview only renders for the two query tools — for
    // other eligible tools the `apiCall` is consumed by the playground only.
    const apiText = (!isSimpleTool && apiCall) ? formatApiEquivalent(apiCall) : null;
    const sqlText = (!isSimpleTool && input) ? buildSqlEquivalent(toolName, input, sqlPrimary, sqlJoin) : null;

    const container = document.createElement('div');
    container.className = 'dkan-aiq-table-container';
    bubble.appendChild(container);

    // Summary bar (always visible).
    const summary = document.createElement('div');
    summary.className = 'dkan-aiq-table-summary';

    // Friendly tool name (e.g. "Datastore query · ", "Sample of rows · ")
    // sits to the left of the row count so users immediately know which
    // call produced the table.
    const toolLabel = document.createElement('span');
    toolLabel.className = 'dkan-aiq-table-tool';
    toolLabel.textContent = TOOL_FRIENDLY_NAMES[toolName] || toolName;
    summary.appendChild(toolLabel);

    const meta = document.createElement('span');
    meta.className = 'dkan-aiq-table-meta';
    let countText = rows.length + ' row' + (rows.length !== 1 ? 's' : '');
    if (artifact.count != null && artifact.count > rows.length) {
      countText += ' of ' + artifact.count + ' total';
    }
    meta.textContent = countText;
    summary.appendChild(meta);

    if (cols.length) {
      const shape = document.createElement('span');
      shape.className = 'dkan-aiq-table-shape';
      shape.textContent = cols.length + ' column' + (cols.length !== 1 ? 's' : '');
      summary.appendChild(shape);
    }

    const actions = document.createElement('span');
    actions.className = 'dkan-aiq-table-actions';

    const s = this.settings || {};

    let toggleBtn = null;
    let toggleLabel = null;
    if (rows.length && s.showTableToggle !== false) {
      toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'dkan-aiq-table-toggle';
      toggleBtn.setAttribute('aria-expanded', 'false');
      toggleBtn.appendChild(makeChevron());
      // Keep a ref to the trailing text node so the click handler can swap
      // "Show" / "Hide" without wiping out the chevron SVG sibling.
      toggleLabel = document.createTextNode(' Show table');
      toggleBtn.appendChild(toggleLabel);
      actions.appendChild(toggleBtn);
    }

    // API call and SQL panels were standalone summary-bar buttons. They now
    // live nested under "Query details" inside the result-details panel, so
    // we just pre-build the DOM nodes here (when the corresponding setting
    // is on) and hand them off to renderProvenancePanel.
    const showCopy = s.showCopyButtons !== false;
    const apiNode = (apiText && s.showApiCall !== false)
      ? buildApiPanelNode(apiText, apiPrimary, sqlPrimary, showCopy)
      : null;
    const sqlNode = (sqlText && s.showSql !== false)
      ? buildSqlPanelNode(sqlText, apiPrimary, sqlPrimary, showCopy)
      : null;

    let provBtn = null;
    let provLabel = null;
    if (provenance && s.showProvenance !== false) {
      provBtn = document.createElement('button');
      provBtn.type = 'button';
      provBtn.className = 'dkan-aiq-prov-btn';
      provBtn.setAttribute('aria-expanded', 'false');
      provBtn.appendChild(makeChevron());
      provLabel = document.createTextNode(' Show result details');
      provBtn.appendChild(provLabel);
      actions.appendChild(provBtn);
    }

    if (rows.length && s.showDownloadCsv !== false) {
      const csvBtn = document.createElement('button');
      csvBtn.type = 'button';
      csvBtn.className = 'dkan-aiq-csv-btn';
      csvBtn.textContent = 'Download CSV';
      csvBtn.addEventListener('click', () => { downloadCsv(cols, rows); });
      actions.appendChild(csvBtn);
    }

    // Playground trigger: only on datastore-query tools (the rest don't have a
    // public REST equivalent that's worth tinkering with), only when the
    // admin toggle is on, and only when we have the captured input the
    // playground would replay. The button reuses the structured api-call
    // shape we already built above for the "Show API call" panel.
    if (apiCall && s.showRestPlaygroundSidebar !== false && PLAYGROUND_ELIGIBLE_TOOLS.has(toolName)) {
      const playgroundBtn = document.createElement('button');
      playgroundBtn.type = 'button';
      playgroundBtn.className = 'dkan-aiq-playground-btn';
      playgroundBtn.textContent = 'API playground';
      playgroundBtn.addEventListener('click', () => {
        this.openPlayground({
          tool: toolName,
          method: apiCall.method,
          url: apiCall.url,
          body: apiCall.body,
          note: apiCall.note || null,
        });
      });
      actions.appendChild(playgroundBtn);
    }

    summary.appendChild(actions);
    container.appendChild(summary);

    // Result details panel — single hub for everything that's not "the
    // table itself". The API call and SQL nodes (datastore queries only)
    // get nested inside its "Query details" disclosure; for simple-table
    // tools both extras are null and only the friendly summary + raw tool
    // input are shown.
    if (provBtn && provenance) {
      const provWrap = document.createElement('div');
      provWrap.className = 'dkan-aiq-prov-wrapper';
      provWrap.hidden = true;
      renderProvenancePanel(provWrap, provenance, { apiNode, sqlNode });
      container.appendChild(provWrap);
      provBtn.addEventListener('click', () => {
        const isHidden = provWrap.hidden;
        provWrap.hidden = !isHidden;
        provLabel.nodeValue = isHidden ? ' Hide result details' : ' Show result details';
        provBtn.classList.toggle('is-open', isHidden);
        provBtn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        this.scrollToBottom();
      });
    }

    // Table wrapper (collapsed by default; built lazily on first reveal).
    let tableWrap = null;
    if (rows.length) {
      tableWrap = document.createElement('div');
      tableWrap.className = 'dkan-aiq-table-wrapper';
      tableWrap.hidden = true;
      container.appendChild(tableWrap);
    }

    let sortCol = null;
    let sortAsc = true;
    const buildTable = (currentRows) => {
      const table = document.createElement('table');
      table.className = 'dkan-aiq-table';
      const thead = document.createElement('thead');
      const trh = document.createElement('tr');
      cols.forEach((col) => {
        const th = document.createElement('th');
        th.dataset.col = col;
        th.textContent = col;
        if (colTypes[col]) {
          const typeChip = document.createElement('span');
          typeChip.className = 'dkan-aiq-col-type';
          typeChip.textContent = colTypes[col];
          th.appendChild(typeChip);
        }
        if (col === sortCol) {
          const ind = document.createElement('span');
          ind.className = 'sort-indicator';
          ind.textContent = sortAsc ? '▲' : '▼';
          th.appendChild(ind);
        }
        th.addEventListener('click', () => {
          if (sortCol === col) {
            sortAsc = !sortAsc;
          }
          else {
            sortCol = col;
            sortAsc = true;
          }
          const sorted = currentRows.slice().sort((a, b) => {
            const va = a[col] == null ? '' : a[col];
            const vb = b[col] == null ? '' : b[col];
            const na = Number(va);
            const nb = Number(vb);
            if (!isNaN(na) && !isNaN(nb)) {
              return sortAsc ? na - nb : nb - na;
            }
            return sortAsc ? String(va).localeCompare(String(vb)) : String(vb).localeCompare(String(va));
          });
          tableWrap.innerHTML = '';
          buildTable(sorted);
        });
        trh.appendChild(th);
      });
      thead.appendChild(trh);
      table.appendChild(thead);
      const tbody = document.createElement('tbody');
      currentRows.forEach((row) => {
        const tr = document.createElement('tr');
        cols.forEach((col) => {
          const td = document.createElement('td');
          const v = row[col];
          const text = v === null || v === undefined ? '' : String(v);
          if (text.length > CELL_TRUNCATE_LEN) {
            // Show a shortened preview in-place. The full value is one click
            // (or hover for the title tooltip) away.
            td.classList.add('dkan-aiq-cell-truncated');
            td.title = text;
            td.textContent = text.slice(0, CELL_TRUNCATE_LEN) + '…';
            td.addEventListener('click', () => {
              const expanded = td.classList.toggle('dkan-aiq-cell-expanded');
              td.textContent = expanded ? text : (text.slice(0, CELL_TRUNCATE_LEN) + '…');
            });
          }
          else {
            td.textContent = text;
          }
          tr.appendChild(td);
        });
        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      tableWrap.appendChild(table);
    };

    if (toggleBtn && tableWrap) {
      toggleBtn.addEventListener('click', () => {
        const isHidden = tableWrap.hidden;
        tableWrap.hidden = !isHidden;
        toggleLabel.nodeValue = isHidden ? ' Hide table' : ' Show table';
        toggleBtn.classList.toggle('is-open', isHidden);
        toggleBtn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        if (isHidden && !tableWrap.hasChildNodes()) {
          buildTable(rows);
        }
        this.scrollToBottom();
      });
    }

    this.scrollToBottom();
  };

  function parseQualifiedField(field, defaultResource) {
    const trimmed = (field || '').trim();
    if (trimmed.indexOf('.') !== -1) {
      const parts = trimmed.split('.');
      return { resource: parts[0], property: parts[1] };
    }
    return { resource: defaultResource, property: trimmed };
  }

  /**
   * Return the structured REST equivalent of a datastore tool call.
   *
   * Returns `{method, url, body}` where `url` is a relative path (so the
   * playground can pass it straight to fetch()) and `body` is the JSON
   * payload as a JS object. The display panel and the playground both
   * consume this — render with `formatApiEquivalent()` for the existing
   * text-panel UI; pass straight to fetch() for the playground.
   */
  function buildApiEquivalent(toolName, input, resolvedResourceId, resolvedJoinId) {
    // query_datastore_raw — translate the agent's payload into a
    // REST-faithful payload. Both the collection endpoint and the per-resource
    // endpoint expect distribution UUIDs in resources[].id (not the internal
    // {hash}__{version} form the agent uses). PHP captureData attaches
    // input.distribution_uuid_map so we can rewrite each id at render time.
    if (toolName === 'query_datastore_raw') {
      let body = {};
      const raw = (input && typeof input.payload === 'string') ? input.payload : '';
      if (raw) {
        try { body = JSON.parse(raw); }
        catch (e) { body = { _parseError: e.message, _raw: raw }; }
      }
      const uuidMap = (input && input.distribution_uuid_map && typeof input.distribution_uuid_map === 'object') ? input.distribution_uuid_map : {};
      if (body && Array.isArray(body.resources)) {
        body.resources = body.resources.map((r) => {
          if (!r || typeof r !== 'object') return r;
          const id = r.id;
          if (typeof id === 'string' && uuidMap[id]) {
            return Object.assign({}, r, { id: uuidMap[id] });
          }
          return r;
        });
      }
      // Always route to the multi-resource collection endpoint when the body
      // carries a `resources` array; the per-resource endpoint rejects bodies
      // that explicitly pass resources ("Joins are not available and resources
      // should not be explicitly passed when using the resource query endpoint").
      const url = '/api/1/datastore/query';
      return { method: 'POST', url: url, body: body };
    }

    // search_datasets maps to /api/1/search (GET with query string params),
    // not the datastore-query POST. Body is the params object; runPlayground
    // serializes it to a query string at fetch time.
    if (toolName === 'search_datasets') {
      const params = {};
      if (input.keyword != null && input.keyword !== '') {
        params.fulltext = input.keyword;
      }
      if (input.page) {
        params.page = input.page;
      }
      // DKAN's REST API uses page-size (hyphen); the FunctionCall input uses
      // page_size (underscore). Translate here so the playground shows the
      // wire format.
      if (input.page_size) {
        params['page-size'] = input.page_size;
      }
      return { method: 'GET', url: '/api/1/search', body: params };
    }

    // Metastore list/get tools — straightforward GET equivalents. Body is
    // the query string params object; runPlayground serializes for GET.
    if (toolName === 'list_datasets') {
      const params = {};
      if (input.offset) params.offset = input.offset;
      if (input.limit) params.limit = input.limit;
      return { method: 'GET', url: '/api/1/metastore/schemas/dataset/items', body: params };
    }
    if (toolName === 'list_distributions') {
      // No direct REST endpoint; the tool walks distributions client-side
      // after fetching the dataset. Show the dataset GET as the first hop.
      const datasetId = input.dataset_id || '';
      return {
        method: 'GET',
        url: '/api/1/metastore/schemas/dataset/items/' + datasetId,
        body: { 'show-reference-ids': true },
        note: 'First hop only. The tool walks the dataset’s distribution references client-side; this REST call returns the parent dataset.',
      };
    }
    if (toolName === 'get_data_dictionary') {
      // The tool resolves a data-dictionary identifier from a dataset or
      // distribution. The playground call shows the metastore GET for the
      // dictionary item — only valid when the tool already resolved one.
      const dictId = (input && input.dictionary_identifier) || '';
      return {
        method: 'GET',
        url: '/api/1/metastore/schemas/data-dictionary/items/' + dictId,
        body: {},
        note: 'First hop only. The tool resolves the dictionary identifier from the dataset/distribution refs; this REST call returns the resolved data-dictionary item.',
      };
    }

    // Datastore-derived tools that map to /api/1/datastore/query/{id} with a
    // narrow body. Each has its own translation; the JSON editor in the
    // playground lets users tweak limits, columns, etc. before re-running.
    if (toolName === 'sample_rows') {
      const sampleId = resolvedResourceId || input.resource_id || '';
      const n = Math.max(1, Math.min(parseInt(input.n, 10) || 5, 50));
      return {
        method: 'POST',
        url: '/api/1/datastore/query/' + sampleId,
        body: {
          sorts: [{ property: 'record_number', order: 'asc' }],
          limit: n,
          results: true,
          count: false,
          keys: true,
        },
        note: 'Approximate. The tool also strips the synthetic record_number column from the response; this REST call returns it.',
      };
    }
    if (toolName === 'distinct_values') {
      const dvId = resolvedResourceId || input.resource_id || '';
      const col = input.column || '';
      const dvLimit = Math.max(1, Math.min(parseInt(input.limit, 10) || 50, 500));
      return {
        method: 'POST',
        url: '/api/1/datastore/query/' + dvId,
        body: {
          properties: col ? [col] : [],
          groupings: col ? [col] : [],
          limit: dvLimit,
          count: false,
          keys: true,
        },
      };
    }
    if (toolName === 'get_datastore_schema') {
      const schId = resolvedResourceId || input.resource_id || '';
      return {
        method: 'POST',
        url: '/api/1/datastore/query/' + schId,
        // DKAN's REST schema enforces limit ≥ 1, so even a schema-only
        // payload needs limit=1 (results:false suppresses the row anyway).
        body: {
          schema: true,
          keys: true,
          limit: 1,
          results: false,
          count: false,
        },
        note: 'REST returns datastore column types only. The tool also merges per-column data-dictionary metadata (title, description, declared type) into its response.',
      };
    }
    if (toolName === 'get_datastore_stats') {
      const stId = resolvedResourceId || input.resource_id || '';
      // Build one min/max/count expression per requested column. Total-row
      // count comes from the top-level `count:true` flag — DKAN's REST DSL
      // rejects `count(*)` ("Column not found") because operands must name
      // a real column. Exact null/distinct-count parity with the full tool
      // would require multiple round trips; that's the trade-off.
      const cols = (input.columns || '').split(',').map(s => s.trim()).filter(Boolean);
      const exprs = [];
      cols.forEach((c) => {
        exprs.push({ expression: { operator: 'min', operands: [c] }, alias: c + '_min' });
        exprs.push({ expression: { operator: 'max', operands: [c] }, alias: c + '_max' });
        exprs.push({ expression: { operator: 'count', operands: [c] }, alias: c + '_count' });
      });
      return {
        method: 'POST',
        url: '/api/1/datastore/query/' + stId,
        body: {
          properties: exprs,
          limit: 1,
          count: true,
          keys: true,
        },
        note: 'Approximate. The full tool also computes null_count and distinct_count via direct DB queries; those don’t round-trip through the REST DSL.',
      };
    }

    const resourceId = resolvedResourceId || input.resource_id || '';
    const joinId = resolvedJoinId || input.join_resource_id || '';
    const isJoin = toolName === 'query_datastore_join' && joinId;
    const body = {};

    if (isJoin) {
      body.resources = [
        { id: resourceId, alias: 't' },
        { id: joinId, alias: 'j' },
      ];
    }

    const properties = [];
    if (input.columns) {
      input.columns.split(',').forEach((c) => {
        const col = c.trim();
        if (isJoin && col.indexOf('.') !== -1) {
          const parts = col.split('.');
          properties.push({ resource: parts[0], property: parts[1] });
        }
        else {
          properties.push(col);
        }
      });
    }

    if (input.groupings) {
      body.groupings = input.groupings.split(',').map((c) => {
        const col = c.trim();
        if (isJoin && col.indexOf('.') !== -1) {
          const parts = col.split('.');
          return { resource: parts[0], property: parts[1] };
        }
        return col;
      });
    }

    if (input.expressions) {
      try {
        // The tool's `expressions` param is the flat shape
        // {operator, operands, alias}; the REST schema requires the wrapped
        // {expression: {operator, operands}, alias} shape, so wrap here to
        // match what DatastoreTools::validateAndBuildExpressions does in PHP.
        JSON.parse(input.expressions).forEach((expr) => {
          properties.push({
            expression: { operator: expr.operator, operands: expr.operands },
            alias: expr.alias,
          });
        });
      }
      catch (e) { /* ignore */ }
    }

    if (properties.length) {
      body.properties = properties;
    }

    if (input.conditions) {
      try {
        body.conditions = JSON.parse(input.conditions);
      }
      catch (e) {
        body.conditions = input.conditions;
      }
    }

    if (input.sort_field) {
      const sort = { order: input.sort_direction || 'asc' };
      if (isJoin && input.sort_field.indexOf('.') !== -1) {
        const sortParts = input.sort_field.split('.');
        sort.resource = sortParts[0];
        sort.property = sortParts[1];
      }
      else {
        sort.property = input.sort_field;
      }
      body.sorts = [sort];
    }

    if (isJoin && input.join_on) {
      const joinOn = input.join_on.trim();
      if (joinOn.charAt(0) === '{') {
        try {
          const parsed = JSON.parse(joinOn);
          const left = parseQualifiedField(parsed.left || '', 't');
          const right = parseQualifiedField(parsed.right || '', 'j');
          body.joins = [{ resource: right.resource, condition: { resource: left.resource, property: left.property, value: right } }];
        }
        catch (e) {
          body.joins = [{ raw: joinOn }];
        }
      }
      else if (joinOn.indexOf('=') !== -1) {
        const eqParts = joinOn.split('=');
        const leftField = parseQualifiedField(eqParts[0].trim(), 't');
        const rightField = parseQualifiedField(eqParts[1].trim(), 'j');
        body.joins = [{ resource: rightField.resource, condition: { resource: leftField.resource, property: leftField.property, value: rightField } }];
      }
    }

    body.limit = input.limit || 100;
    if (input.offset) {
      body.offset = input.offset;
    }
    body.count = true;
    body.results = true;
    body.keys = true;

    const url = isJoin
      ? '/api/1/datastore/query'
      : '/api/1/datastore/query/' + resourceId;
    return { method: 'POST', url: url, body: body };
  }

  /**
   * Render an api-equivalent object as the text shown in the "Show API call"
   * code panel. Kept separate from buildApiEquivalent() so the playground
   * can consume the structured form without re-parsing.
   */
  function formatApiEquivalent(api) {
    return api.method + ' ' + api.url + '\n' + JSON.stringify(api.body, null, 2);
  }

  /**
   * Lazy loader + matcher for DKAN's OpenAPI spec at /api/1.
   *
   * Used by the playground sidebar to render a "Parameter help" panel that
   * surfaces operation summaries and per-property descriptions from the spec.
   * Cached in localStorage with a 24-hour TTL and ETag-aware revalidation;
   * degrades silently when fetch fails so the playground keeps working.
   *
   * Public:
   *   OpenApiSpec.load() → Promise<spec | null>
   *   OpenApiSpec.describe(spec, method, url) → { summary, description,
   *     parameters: [{name,in,required,type,description}], bodyProperties: [
   *     {name,required,type,description}] } | null
   */
  const OpenApiSpec = (function () {
    const STORAGE_KEY = 'dkanAiqOpenApi:v1';
    const TTL_MS = 24 * 60 * 60 * 1000;
    const SPEC_URL = '/api/1';
    let inFlight = null;
    let memCache = null;

    function readStorage() {
      try {
        const raw = window.localStorage && localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed || !parsed.spec || !parsed.fetchedAt) return null;
        return parsed;
      }
      catch (e) { return null; }
    }

    function writeStorage(payload) {
      try {
        if (window.localStorage) localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
      }
      catch (e) { /* quota exceeded / disabled — ignore */ }
    }

    function load() {
      if (memCache && (Date.now() - memCache.fetchedAt) < TTL_MS) {
        return Promise.resolve(memCache.spec);
      }
      if (inFlight) return inFlight;
      const stored = readStorage();
      const headers = stored && stored.etag ? { 'If-None-Match': stored.etag } : {};
      inFlight = fetch(SPEC_URL, { headers: headers, credentials: 'same-origin' })
        .then((res) => {
          if (res.status === 304 && stored) {
            memCache = { spec: stored.spec, etag: stored.etag, fetchedAt: Date.now() };
            writeStorage(memCache);
            return memCache.spec;
          }
          if (!res.ok) {
            if (stored) { memCache = stored; return stored.spec; }
            return null;
          }
          const etag = res.headers.get('ETag') || null;
          return res.json().then((spec) => {
            memCache = { spec: spec, etag: etag, fetchedAt: Date.now() };
            writeStorage(memCache);
            return spec;
          });
        })
        .catch(() => {
          if (stored) { memCache = stored; return stored.spec; }
          return null;
        })
        .then((spec) => { inFlight = null; return spec; });
      return inFlight;
    }

    function deref(spec, ref) {
      if (!ref || typeof ref !== 'string' || ref.indexOf('#/') !== 0) return null;
      const parts = ref.slice(2).split('/');
      let node = spec;
      for (let i = 0; i < parts.length; i++) {
        const p = parts[i].replace(/~1/g, '/').replace(/~0/g, '~');
        if (node && typeof node === 'object' && p in node) node = node[p];
        else return null;
      }
      return node;
    }

    function resolveSchema(spec, schema, depth) {
      if (!schema) return null;
      if ((depth || 0) > 8) return schema;
      if (schema.$ref) {
        const target = deref(spec, schema.$ref);
        if (!target) return null;
        return resolveSchema(spec, target, (depth || 0) + 1);
      }
      return schema;
    }

    function resolveParameter(spec, param, depth) {
      if (!param) return null;
      if ((depth || 0) > 4) return param;
      if (param.$ref) {
        const target = deref(spec, param.$ref);
        if (!target) return null;
        return resolveParameter(spec, target, (depth || 0) + 1);
      }
      return param;
    }

    // Match a literal URL against the spec's templated paths. Prefers the
    // template with the most literal (non-{placeholder}) segments — so
    // /api/1/metastore/schemas/dataset/items/{identifier} wins over the
    // generic /api/1/metastore/schemas/{schema_id}/items/{identifier}.
    // Only considers templates that actually declare the requested method,
    // so a GET against /api/1/metastore/schemas/dataset/items falls through
    // to the generic {schema_id}/items GET when the dataset-specific path
    // only declares POST.
    function matchPath(spec, method, url) {
      if (!spec || !spec.paths) return null;
      const m = (method || '').toLowerCase();
      const cleanUrl = (url || '').split('?')[0];
      const urlParts = cleanUrl.split('/').filter(Boolean);
      let best = null;
      let bestLiterals = -1;
      for (const tmpl in spec.paths) {
        if (!Object.prototype.hasOwnProperty.call(spec.paths, tmpl)) continue;
        const ops = spec.paths[tmpl];
        if (!ops || !ops[m]) continue;
        const tmplParts = tmpl.split('/').filter(Boolean);
        if (tmplParts.length !== urlParts.length) continue;
        let ok = true;
        let literals = 0;
        for (let i = 0; i < tmplParts.length; i++) {
          const t = tmplParts[i];
          if (t.charAt(0) === '{' && t.charAt(t.length - 1) === '}') {
            if (!urlParts[i]) { ok = false; break; }
            continue;
          }
          if (t !== urlParts[i]) { ok = false; break; }
          literals++;
        }
        if (ok && literals > bestLiterals) {
          best = tmpl;
          bestLiterals = literals;
        }
      }
      return best;
    }

    function describe(spec, method, url) {
      if (!spec) return null;
      const m = (method || '').toLowerCase();
      const tmpl = matchPath(spec, m, url);
      if (!tmpl) return null;
      const op = spec.paths[tmpl] && spec.paths[tmpl][m];
      if (!op) return null;
      const out = {
        path: tmpl,
        method: (method || '').toUpperCase(),
        summary: op.summary || '',
        description: op.description || '',
        parameters: [],
        bodyProperties: [],
      };
      (op.parameters || []).forEach((p) => {
        const r = resolveParameter(spec, p);
        if (!r || !r.name) return;
        out.parameters.push({
          name: r.name,
          in: r.in || '',
          required: !!r.required,
          type: (r.schema && r.schema.type) || '',
          description: r.description || '',
        });
      });
      const body = op.requestBody;
      if (body && body.content) {
        const json = body.content['application/json'];
        if (json && json.schema) {
          const schema = resolveSchema(spec, json.schema);
          if (schema && schema.properties) {
            const required = new Set(schema.required || []);
            for (const k in schema.properties) {
              if (!Object.prototype.hasOwnProperty.call(schema.properties, k)) continue;
              const prop = schema.properties[k] || {};
              out.bodyProperties.push({
                name: k,
                required: required.has(k),
                type: prop.type || (prop.$ref ? 'object' : ''),
                description: prop.description || '',
              });
            }
          }
        }
      }
      return out;
    }

    return { load: load, describe: describe };
  }());

  function buildSqlEquivalent(toolName, input, resolvedResourceId, resolvedJoinId) {
    // SQL preview only applies to the two datastore-query tools. Anything
    // else (search, list, etc.) doesn't map to SQL, so return null and the
    // caller skips rendering the SQL panel.
    if (toolName !== 'query_datastore' && toolName !== 'query_datastore_join') {
      return null;
    }
    const resourceId = resolvedResourceId || input.resource_id || 'resource';
    const joinId = resolvedJoinId || input.join_resource_id || '';
    const isJoin = toolName === 'query_datastore_join' && joinId;
    const parts = [];

    const selectCols = [];
    if (input.columns) {
      input.columns.split(',').forEach((c) => { selectCols.push(c.trim()); });
    }
    if (input.expressions) {
      try {
        JSON.parse(input.expressions).forEach((expr) => {
          const fn = (expr.operator || 'value').toUpperCase();
          let col = expr.operands || expr.property || '*';
          if (Array.isArray(col)) {
            col = col.join(', ');
          }
          const alias = expr.alias || '';
          let exprStr = fn + '(' + col + ')';
          if (alias) {
            exprStr += ' AS ' + alias;
          }
          selectCols.push(exprStr);
        });
      }
      catch (e) { /* ignore */ }
    }
    parts.push('SELECT ' + (selectCols.length ? selectCols.join(', ') : '*'));

    // Prefer the physical table name resolved by the backend
    // (datastore_<md5>) over a name built from the resource id, which would
    // be wrong because the actual table uses md5(identifier__version__perspective).
    const tableName = input.table_name || ('datastore_' + resourceId.replace(/-/g, '_'));
    if (isJoin) {
      parts.push('FROM ' + tableName + ' AS t');
    }
    else {
      parts.push('FROM ' + tableName);
    }

    if (isJoin && input.join_on) {
      const joinTable = input.join_table_name || ('datastore_' + joinId.replace(/-/g, '_'));
      const joinOn = input.join_on.trim();
      let onClause = '';
      if (joinOn.charAt(0) === '{') {
        try {
          const parsed = JSON.parse(joinOn);
          onClause = (parsed.left || 't.id') + ' = ' + (parsed.right || 'j.id');
        }
        catch (e) {
          onClause = joinOn;
        }
      }
      else if (joinOn.indexOf('=') !== -1) {
        const eqParts = joinOn.split('=');
        let left = eqParts[0].trim();
        let right = eqParts[1].trim();
        if (left.indexOf('.') === -1) {
          left = 't.' + left;
        }
        if (right.indexOf('.') === -1) {
          right = 'j.' + right;
        }
        onClause = left + ' = ' + right;
      }
      else {
        onClause = joinOn;
      }
      parts.push('JOIN ' + joinTable + ' AS j ON ' + onClause);
    }

    if (input.conditions) {
      try {
        const conditions = JSON.parse(input.conditions);
        if (Array.isArray(conditions) && conditions.length) {
          const whereClauses = conditions.map((cond) => {
            let col = cond.property || cond.column || '?';
            if (isJoin && cond.resource) {
              col = cond.resource + '.' + col;
            }
            const op = (cond.operator || '=').toUpperCase();
            let val = cond.value;
            if (typeof val === 'string') {
              val = "'" + val.replace(/'/g, "''") + "'";
            }
            if (op === 'LIKE' || op === 'NOT LIKE') {
              return col + ' ' + op + ' ' + val;
            }
            if (op === 'IN' || op === 'NOT IN') {
              const vals = Array.isArray(cond.value) ? cond.value : [cond.value];
              const formatted = vals.map((v) => (typeof v === 'string' ? "'" + v.replace(/'/g, "''") + "'" : v));
              return col + ' ' + op + ' (' + formatted.join(', ') + ')';
            }
            if (op === 'BETWEEN') {
              return col + ' BETWEEN ' + cond.value;
            }
            return col + ' ' + op + ' ' + val;
          });
          parts.push('WHERE ' + whereClauses.join('\n  AND '));
        }
      }
      catch (e) { /* ignore */ }
    }

    if (input.groupings) {
      parts.push('GROUP BY ' + input.groupings);
    }

    if (input.sort_field) {
      const dir = (input.sort_direction || 'asc').toUpperCase();
      parts.push('ORDER BY ' + input.sort_field + ' ' + dir);
    }

    const limit = input.limit || 100;
    parts.push('LIMIT ' + limit);
    if (input.offset) {
      parts.push('OFFSET ' + input.offset);
    }

    return parts.join('\n');
  }

  function csvEscape(str) {
    if (str.indexOf(',') !== -1 || str.indexOf('"') !== -1 || str.indexOf('\n') !== -1) {
      return '"' + str.replace(/"/g, '""') + '"';
    }
    return str;
  }

  /**
   * Render the provenance block as a definition list inside `wrap`.
   *
   * Provenance is the audit trail for one query_datastore tool call:
   * when it ran, the structured query shape, total rows, and any
   * sanity flags surfaced by the datastore.
   */
  /**
   * Build the dark code panel for the public REST equivalent of a query.
   *
   * Returns a detached <div> with the pre-formatted call text, an optional
   * cross-reference note (the alternate identifier), and an optional copy
   * button. Pure DOM construction — the caller decides where to mount it
   * (today: nested under "Query details" inside the result-details panel).
   */
  function buildApiPanelNode(apiText, apiPrimary, sqlPrimary, showCopy) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-api-wrapper';
    const pre = document.createElement('pre');
    pre.className = 'dkan-aiq-api-code';
    pre.textContent = apiText;
    wrap.appendChild(pre);
    // Cross-reference: the SQL panel uses the internal {hash}__{version}
    // resource id, while this API call uses the distribution UUID. Surface
    // the alternate form so users can reconcile the two views.
    if (sqlPrimary && sqlPrimary !== apiPrimary) {
      const note = document.createElement('div');
      note.className = 'dkan-aiq-id-note';
      note.textContent = 'Internal datastore resource id: ' + sqlPrimary;
      wrap.appendChild(note);
    }
    if (showCopy) {
      const copy = document.createElement('button');
      copy.type = 'button';
      copy.className = 'dkan-aiq-api-copy';
      copy.textContent = 'Copy';
      copy.addEventListener('click', () => {
        navigator.clipboard.writeText(apiText).then(() => {
          copy.textContent = 'Copied!';
          setTimeout(() => { copy.textContent = 'Copy'; }, 1500);
        });
      });
      wrap.appendChild(copy);
    }
    return wrap;
  }

  /**
   * Build the dark code panel for the SQL equivalent of a query.
   *
   * Mirror of buildApiPanelNode: returns a detached <div> with the SQL
   * text, optional cross-reference note (the public distribution UUID),
   * and optional copy button.
   */
  function buildSqlPanelNode(sqlText, apiPrimary, sqlPrimary, showCopy) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-sql-wrapper';
    const pre = document.createElement('pre');
    pre.className = 'dkan-aiq-sql-code';
    pre.textContent = sqlText;
    wrap.appendChild(pre);
    if (apiPrimary && apiPrimary !== sqlPrimary) {
      const note = document.createElement('div');
      note.className = 'dkan-aiq-id-note';
      note.textContent = 'Public distribution UUID: ' + apiPrimary;
      wrap.appendChild(note);
    }
    if (showCopy) {
      const copy = document.createElement('button');
      copy.type = 'button';
      copy.className = 'dkan-aiq-sql-copy';
      copy.textContent = 'Copy';
      copy.addEventListener('click', () => {
        navigator.clipboard.writeText(sqlText).then(() => {
          copy.textContent = 'Copied!';
          setTimeout(() => { copy.textContent = 'Copy'; }, 1500);
        });
      });
      wrap.appendChild(copy);
    }
    return wrap;
  }

  function renderProvenancePanel(wrap, prov, extras) {
    // Lead with a one-sentence explainer of what the tool does, so users
    // can interpret the structured rows below without having to know what
    // "datastore query" or "sample_rows" means.
    const blurb = TOOL_DESCRIPTIONS[prov.tool];
    if (blurb) {
      const intro = document.createElement('p');
      intro.className = 'dkan-aiq-prov-blurb';
      intro.textContent = blurb;
      wrap.appendChild(intro);
    }

    const dl = document.createElement('dl');
    dl.className = 'dkan-aiq-prov';

    const addRow = (label, value) => {
      const dt = document.createElement('dt');
      dt.textContent = label;
      dl.appendChild(dt);
      const dd = document.createElement('dd');
      if (value instanceof Node) {
        dd.appendChild(value);
      }
      else {
        dd.textContent = value;
      }
      dl.appendChild(dd);
    };

    if (prov.executed_at) {
      const time = document.createElement('time');
      time.dateTime = prov.executed_at;
      // Best-effort localization for the headline; falls back to the raw ISO
      // string when the input isn't parseable.
      const parsed = new Date(prov.executed_at);
      time.textContent = isNaN(parsed.getTime()) ? prov.executed_at : parsed.toLocaleString();
      addRow(PROV_LABELS.executed_at, time);
    }

    const friendlyTool = TOOL_FRIENDLY_NAMES[prov.tool] || prov.tool || '(unknown)';
    addRow(PROV_LABELS.tool, friendlyTool);

    if (prov.row_count != null) {
      let countText = String(prov.row_count);
      if (prov.total_rows != null && prov.total_rows !== prov.row_count) {
        countText += ' (of ' + prov.total_rows + ' total)';
      }
      addRow(PROV_LABELS.rows, countText);
    }

    // Sanity flags rendered as full-sentence bullets driven by SANITY_GLOSS,
    // so analysts read "Result was capped…" instead of "row_cap_hit".
    const flags = prov.sanity_flags || null;
    if (flags) {
      const sentences = [];
      if (flags.zero_rows) {
        sentences.push(SANITY_GLOSS.zero_rows);
      }
      if (flags.row_cap_hit) {
        sentences.push(SANITY_GLOSS.row_cap_hit);
      }
      if (Array.isArray(flags.all_null_columns) && flags.all_null_columns.length) {
        sentences.push(SANITY_GLOSS.all_null_columns + flags.all_null_columns.join(', '));
      }
      if (flags.coverage_warning) {
        sentences.push(SANITY_GLOSS.coverage_warning + flags.coverage_warning);
      }
      if (sentences.length) {
        const ul = document.createElement('ul');
        ul.className = 'dkan-aiq-prov-flags';
        sentences.forEach((line) => {
          const li = document.createElement('li');
          li.textContent = line;
          ul.appendChild(li);
        });
        addRow(PROV_LABELS.sanity_flags, ul);
      }
    }

    wrap.appendChild(dl);

    // Power-user disclosures: each sits as its own sibling under the friendly
    // summary block so analysts can ignore them and developers can pop open
    // exactly the one they need. Skipped entirely when the section has no
    // content (e.g. simple-table tools have no API or SQL equivalent).
    extras = extras || {};

    const addDisclosure = (label, child) => {
      const details = document.createElement('details');
      details.className = 'dkan-aiq-prov-details';
      const summary = document.createElement('summary');
      summary.textContent = label;
      details.appendChild(summary);
      details.appendChild(child);
      wrap.appendChild(details);
    };

    if (prov.query_summary) {
      const pre = document.createElement('pre');
      pre.className = 'dkan-aiq-prov-query';
      pre.textContent = JSON.stringify(prov.query_summary, null, 2);
      addDisclosure(PROV_LABELS.query_summary, pre);
    }
    if (extras.apiNode) {
      addDisclosure('API call', extras.apiNode);
    }
    if (extras.sqlNode) {
      addDisclosure('SQL', extras.sqlNode);
    }
  }

  function downloadCsv(columns, rows) {
    const lines = [];
    lines.push(columns.map(csvEscape).join(','));
    rows.forEach((row) => {
      lines.push(columns.map((col) => {
        const v = row[col];
        return csvEscape(v === null || v === undefined ? '' : String(v));
      }).join(','));
    });
    const csv = lines.join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'query-results.csv';
    a.click();
    URL.revokeObjectURL(url);
  }

  Widget.prototype.renderChartInBubble = function (bubble, artifact) {
    if (!artifact.spec) {
      return;
    }
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-chart-container';
    bubble.classList.add('dkan-aiq-message-has-chart');
    bubble.appendChild(wrap);
    this.scrollToBottom();

    // Always fill the bubble width — the LLM's explicit pixel width isn't
    // meaningful in this chat context. Default the height when missing.
    const spec = Object.assign({}, artifact.spec);
    spec.width = 'container';
    if (spec.height === undefined) {
      spec.height = 360;
    }

    const tryRender = () => {
      if (typeof window.vegaEmbed === 'undefined') {
        return;
      }
      window.vegaEmbed(wrap, spec, {
        actions: { export: true, source: false, compiled: false, editor: false },
        renderer: 'svg',
      }).catch(function () { wrap.textContent = 'Chart render failed.'; });
    };
    if (typeof window.vegaEmbed !== 'undefined') {
      tryRender();
    }
    else {
      // Vega scripts are still loading; try once they finish.
      const interval = setInterval(() => {
        if (typeof window.vegaEmbed !== 'undefined') {
          clearInterval(interval);
          tryRender();
        }
      }, 100);
      setTimeout(() => clearInterval(interval), 8000);
    }
  };

  /**
   * Render a structured refusal as a distinct card (no provenance block).
   *
   * The agent calls the `refuse` tool when it cannot answer; the
   * subscriber persists a `{type: refusal, reason_category, explanation,
   * datasets_searched}` artifact. We render it here as a clearly
   * differentiated card so the user understands the agent stopped
   * deliberately.
   */
  Widget.prototype.renderRefusalInBubble = function (bubble, artifact) {
    const card = document.createElement('div');
    card.className = 'dkan-aiq-refusal';
    bubble.classList.add('dkan-aiq-message-has-refusal');

    const header = document.createElement('div');
    header.className = 'dkan-aiq-refusal-header';
    const label = document.createElement('span');
    label.className = 'dkan-aiq-refusal-label';
    label.textContent = 'Refused';
    header.appendChild(label);
    const cat = document.createElement('span');
    cat.className = 'dkan-aiq-refusal-category';
    cat.textContent = artifact.reason_category || 'other';
    header.appendChild(cat);
    card.appendChild(header);

    if (artifact.explanation) {
      const body = document.createElement('p');
      body.className = 'dkan-aiq-refusal-explanation';
      body.textContent = artifact.explanation;
      card.appendChild(body);
    }

    const searched = artifact.datasets_searched || [];
    const list = Array.isArray(searched) ? searched : [searched];
    const items = list.filter((s) => typeof s === 'string' && s.length);
    if (items.length) {
      const note = document.createElement('p');
      note.className = 'dkan-aiq-refusal-searched';
      note.textContent = 'Searched: ' + items.join(', ');
      card.appendChild(note);
    }

    bubble.appendChild(card);
    this.scrollToBottom();
  };

  /**
   * Append (or update) the bubble's "Behind the scenes" disclosure with
   * one entry per aux_tool artifact. The outer details collects all
   * non-table tool calls for the turn into a single collapsed panel so
   * the main answer + table stays prominent.
   */
  Widget.prototype.renderAuxToolInBubble = function (bubble, artifact) {
    let outer = bubble.querySelector(':scope > .dkan-aiq-aux-tools');
    let list;
    if (!outer) {
      outer = document.createElement('details');
      outer.className = 'dkan-aiq-aux-tools';
      const summary = document.createElement('summary');
      summary.className = 'dkan-aiq-aux-tools-summary';
      summary.textContent = 'Supporting data';
      outer.appendChild(summary);
      list = document.createElement('div');
      list.className = 'dkan-aiq-aux-list';
      outer.appendChild(list);
      bubble.appendChild(outer);
    }
    else {
      list = outer.querySelector('.dkan-aiq-aux-list');
    }

    // Build the per-tool apiCall when this aux tool has a REST analog and
    // the playground sidebar is enabled. We pass it down to buildAuxEntry
    // so the disclosure can render an "API playground" button alongside
    // the raw-output details.
    const s = this.settings || {};
    const playgroundCb = (
      s.showRestPlaygroundSidebar !== false
      && PLAYGROUND_ELIGIBLE_TOOLS.has(artifact.tool)
      && artifact.input
    )
      ? this.buildAuxPlaygroundCallback(artifact)
      : null;

    const entry = buildAuxEntry(artifact, playgroundCb);
    list.appendChild(entry);

    // Reflect the running count in the outer summary so the analyst sees
    // "Supporting data — 3 tool calls" without having to expand first.
    const count = list.children.length;
    outer.querySelector('.dkan-aiq-aux-tools-summary').textContent =
      'Supporting data — ' + count + ' tool call' + (count !== 1 ? 's' : '');

    this.scrollToBottom();
  };

  /**
   * Compute the playground request for an aux_tool artifact.
   *
   * Pulls input from the artifact (captured PHP-side at tool finish) and
   * for get_data_dictionary supplements it with the resolved dictionary
   * identifier read off the response. Returns a () => {} click handler
   * that the buildAuxEntry helper attaches to a "Try in API playground"
   * button. NULL when no apiCall could be built.
   */
  Widget.prototype.buildAuxPlaygroundCallback = function (artifact) {
    const inp = Object.assign({}, artifact.input || {});
    if (artifact.tool === 'get_data_dictionary' && artifact.raw && artifact.raw.dictionaries) {
      // The tool resolves a data-dictionary identifier from the dataset/
      // distribution refs. Pluck the first one off the response so the
      // playground GETs the actual item rather than a placeholder URL.
      const dicts = artifact.raw.dictionaries;
      const firstKey = Object.keys(dicts)[0];
      if (firstKey && dicts[firstKey] && dicts[firstKey].identifier) {
        inp.dictionary_identifier = dicts[firstKey].identifier;
      }
    }
    // Prefer the distribution UUID — the public datastore-query endpoint
    // expects it, not the internal {hash}__{version} resource id.
    const apiCall = buildApiEquivalent(
      artifact.tool,
      inp,
      inp.distribution_uuid || inp.resolved_resource_id || inp.resource_id || '',
      ''
    );
    if (!apiCall) {
      return null;
    }
    return () => {
      this.openPlayground({
        tool: artifact.tool,
        method: apiCall.method,
        url: apiCall.url,
        body: apiCall.body,
        note: apiCall.note || null,
      });
    };
  };

  /**
   * Build a single per-tool entry: collapsed details whose summary shows
   * the friendly tool name + one-line headline; body shows the tool-
   * specific content + a Raw output disclosure.
   *
   * `onPlayground` is an optional click handler. When provided, an
   * "API playground" button is appended to the entry's footer.
   */
  function buildAuxEntry(artifact, onPlayground) {
    const entry = document.createElement('details');
    entry.className = 'dkan-aiq-aux-entry';

    const friendly = AUX_TOOL_FRIENDLY_NAMES[artifact.tool] || artifact.tool;
    const headline = (artifact.structured && artifact.structured.headline) || '';

    const summary = document.createElement('summary');
    const name = document.createElement('span');
    name.className = 'dkan-aiq-aux-name';
    name.textContent = friendly;
    summary.appendChild(name);
    if (headline) {
      const sep = document.createElement('span');
      sep.className = 'dkan-aiq-aux-sep';
      sep.textContent = ' — ';
      summary.appendChild(sep);
      const head = document.createElement('span');
      head.className = 'dkan-aiq-aux-headline';
      head.textContent = headline;
      summary.appendChild(head);
    }
    entry.appendChild(summary);

    const blurb = AUX_TOOL_DESCRIPTIONS[artifact.tool];
    if (blurb) {
      const intro = document.createElement('p');
      intro.className = 'dkan-aiq-aux-blurb';
      intro.textContent = blurb;
      entry.appendChild(intro);
    }

    const body = renderAuxBody(artifact);
    if (body) {
      entry.appendChild(body);
    }

    // Raw output disclosure for power users.
    if (artifact.raw) {
      const raw = document.createElement('details');
      raw.className = 'dkan-aiq-aux-raw';
      const rawSum = document.createElement('summary');
      rawSum.textContent = 'Raw output';
      raw.appendChild(rawSum);
      const pre = document.createElement('pre');
      pre.className = 'dkan-aiq-prov-query';
      pre.textContent = JSON.stringify(artifact.raw, null, 2);
      raw.appendChild(pre);
      entry.appendChild(raw);
    }

    if (typeof onPlayground === 'function') {
      const actions = document.createElement('div');
      actions.className = 'dkan-aiq-aux-actions';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'dkan-aiq-playground-btn';
      btn.textContent = 'API playground';
      btn.addEventListener('click', onPlayground);
      actions.appendChild(btn);
      entry.appendChild(actions);
    }

    return entry;
  }

  function renderAuxBody(artifact) {
    const s = artifact.structured || {};
    if (artifact.tool === 'compute_stats') {
      return renderComputeStatsBody(s);
    }
    if (artifact.tool === 'get_data_dictionary') {
      return renderDataDictionaryBody(s);
    }
    if (artifact.tool === 'get_datastore_stats') {
      return renderDatastoreStatsBody(s);
    }
    if (artifact.tool === 'get_datastore_schema') {
      return renderSchemaBody(s);
    }
    if (artifact.tool === 'distinct_values') {
      return renderDistinctValuesBody(s);
    }
    return null;
  }

  function renderComputeStatsBody(s) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-aux-body';

    const warnings = Array.isArray(s.warnings) ? s.warnings : [];
    if (warnings.length) {
      const ul = document.createElement('ul');
      ul.className = 'dkan-aiq-prov-flags';
      warnings.forEach((w) => {
        const li = document.createElement('li');
        li.textContent = w;
        ul.appendChild(li);
      });
      wrap.appendChild(ul);
    }

    const rows = Array.isArray(s.rows) ? s.rows : [];
    if (rows.length) {
      wrap.appendChild(buildAuxMicroTable(
        ['Operation', 'Column', 'Value', 'Rows skipped'],
        rows.map((r) => [r.operation, r.column, r.value, r.rows_skipped])
      ));
    }
    return wrap;
  }

  function renderDataDictionaryBody(s) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-aux-body';

    const dicts = Array.isArray(s.dictionaries) ? s.dictionaries : [];
    dicts.forEach((d) => {
      const section = document.createElement('div');
      section.className = 'dkan-aiq-aux-dict';

      const heading = document.createElement('div');
      heading.className = 'dkan-aiq-aux-dict-heading';
      if (d.url) {
        const link = document.createElement('a');
        link.href = d.url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = d.title || d.resource_id;
        heading.appendChild(link);
      }
      else {
        heading.textContent = d.title || d.resource_id;
      }
      section.appendChild(heading);

      const fields = Array.isArray(d.fields) ? d.fields : [];
      if (fields.length) {
        section.appendChild(buildAuxMicroTable(
          ['Name', 'Title', 'Type', 'Description'],
          fields.map((f) => [f.name, f.title, f.type, f.description])
        ));
      }
      wrap.appendChild(section);
    });
    return wrap;
  }

  function renderDatastoreStatsBody(s) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-aux-body';
    const cols = Array.isArray(s.columns) ? s.columns : [];
    if (cols.length) {
      wrap.appendChild(buildAuxMicroTable(
        ['Column', 'Type', 'Nulls', 'Distinct', 'Min', 'Max'],
        cols.map((c) => [c.name, c.type, c.null_count, c.distinct_count, c.min, c.max])
      ));
    }
    return wrap;
  }

  function renderSchemaBody(s) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-aux-body';
    const cols = Array.isArray(s.columns) ? s.columns : [];
    if (cols.length) {
      wrap.appendChild(buildAuxMicroTable(
        ['Column', 'Type', 'Description'],
        cols.map((c) => [c.name, c.type, c.description])
      ));
    }
    return wrap;
  }

  function renderDistinctValuesBody(s) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-aux-body';
    const values = Array.isArray(s.values) ? s.values : [];
    if (values.length) {
      wrap.appendChild(buildAuxMicroTable(
        ['Value'],
        values.map((v) => [v])
      ));
    }
    if (s.truncated) {
      const note = document.createElement('p');
      note.className = 'dkan-aiq-aux-truncated';
      note.textContent = 'List truncated — there may be more values not shown.';
      wrap.appendChild(note);
    }
    return wrap;
  }

  /**
   * Tiny HTML table used inside aux entries. Reuses .dkan-aiq-table styling
   * so type / spacing / hover match the main result tables.
   */
  function buildAuxMicroTable(headers, rows) {
    const table = document.createElement('table');
    table.className = 'dkan-aiq-table dkan-aiq-aux-table';
    const thead = document.createElement('thead');
    const trh = document.createElement('tr');
    headers.forEach((h) => {
      const th = document.createElement('th');
      th.textContent = h;
      trh.appendChild(th);
    });
    thead.appendChild(trh);
    table.appendChild(thead);
    const tbody = document.createElement('tbody');
    rows.forEach((row) => {
      const tr = document.createElement('tr');
      row.forEach((cell) => {
        const td = document.createElement('td');
        td.textContent = cell === null || cell === undefined ? '' : String(cell);
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    return table;
  }

  Widget.prototype.renderFollowUpSuggestions = function (items) {
    const wrap = this.dom.examplesContainer;
    if (!wrap || !Array.isArray(items) || items.length === 0) {
      return;
    }
    wrap.innerHTML = '';
    wrap.hidden = false;
    const label = document.createElement('span');
    label.className = 'dkan-aiq-suggestions-label';
    label.textContent = 'Try next:';
    wrap.appendChild(label);
    items.forEach((text) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'dkan-aiq-suggestion-chip';
      chip.textContent = text;
      chip.addEventListener('click', () => {
        this.askQuestion(text);
      });
      wrap.appendChild(chip);
    });
  };

  /**
   * Lazy-build the right-side REST playground sidebar DOM.
   *
   * Mounted as a sibling of `.dkan-aiq-sidebar` and `.dkan-aiq-main` inside
   * `.dkan-aiq-widget`. Hidden by default; the `--with-playground` modifier
   * class on the widget root is what makes it visible.
   */
  Widget.prototype.ensurePlaygroundSidebar = function () {
    if (this.playground.el) {
      return this.playground.el;
    }
    const aside = document.createElement('aside');
    aside.className = 'dkan-aiq-playground-sidebar';
    aside.setAttribute('aria-label', 'REST API playground');
    aside.setAttribute('aria-hidden', 'true');

    // Header — friendly title, method+url chip, close button.
    const header = document.createElement('div');
    header.className = 'dkan-aiq-playground-header';
    const title = document.createElement('div');
    title.className = 'dkan-aiq-playground-title';
    title.textContent = 'API playground';
    const endpoint = document.createElement('div');
    endpoint.className = 'dkan-aiq-playground-endpoint';
    const methodEl = document.createElement('span');
    methodEl.className = 'dkan-aiq-playground-method';
    const urlEl = document.createElement('code');
    urlEl.className = 'dkan-aiq-playground-url';
    endpoint.appendChild(methodEl);
    endpoint.appendChild(urlEl);
    // Header actions: collapse-to-rail (chevron) + full close (×). The chevron
    // is additive — × still tears the playground all the way down.
    const headerActions = document.createElement('div');
    headerActions.className = 'dkan-aiq-playground-header-actions';
    const toggleBtn = document.createElement('button');
    toggleBtn.type = 'button';
    toggleBtn.className = 'dkan-aiq-playground-toggle';
    toggleBtn.setAttribute('aria-label', 'Collapse playground');
    toggleBtn.setAttribute('aria-expanded', 'true');
    toggleBtn.innerHTML =
      '<svg class="dkan-aiq-playground-toggle-icon" width="14" height="14" viewBox="0 0 24 24"' +
      ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"' +
      ' stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>';
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'dkan-aiq-playground-close';
    closeBtn.setAttribute('aria-label', 'Close playground');
    closeBtn.innerHTML = '&times;';
    closeBtn.addEventListener('click', () => { this.closePlayground(); });
    headerActions.appendChild(toggleBtn);
    headerActions.appendChild(closeBtn);
    header.appendChild(title);
    header.appendChild(headerActions);
    header.appendChild(endpoint);
    aside.appendChild(header);

    // Rail summary: code-brackets icon + recent-runs count, only visible when
    // the sidebar is collapsed. Acts as a second click target to expand.
    const railSummary = document.createElement('button');
    railSummary.type = 'button';
    railSummary.className = 'dkan-aiq-playground-rail-summary';
    railSummary.setAttribute('data-count', '0');
    railSummary.setAttribute('aria-label', 'Expand API playground');
    railSummary.innerHTML =
      '<svg class="dkan-aiq-playground-rail-icon" width="20" height="20" viewBox="0 0 24 24"' +
      ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"' +
      ' stroke-linejoin="round" aria-hidden="true">' +
      '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>' +
      '<span class="dkan-aiq-playground-rail-count">0</span>';
    aside.appendChild(railSummary);

    // Editor — JSON textarea + Run/Reset + inline error slot.
    const editorWrap = document.createElement('div');
    editorWrap.className = 'dkan-aiq-playground-editor-wrap';
    // Optional note rendered above the editor — used by composite tools
    // (list_distributions, get_data_dictionary, ...) to flag that the
    // shown REST call is only the first hop, or by approximating tools
    // (sample_rows, get_datastore_stats, get_datastore_schema) to flag
    // that the tool computes additional things client-side.
    const noteEl = document.createElement('div');
    noteEl.className = 'dkan-aiq-playground-note';
    noteEl.hidden = true;
    editorWrap.appendChild(noteEl);
    // Collapsible "Parameter help" panel populated from DKAN's OpenAPI spec
    // at /api/1 (cached in localStorage). Hidden until openPlayground()
    // resolves a matching operation; if the spec fetch fails, this stays
    // hidden and the playground continues to work.
    const helpEl = document.createElement('details');
    helpEl.className = 'dkan-aiq-playground-help';
    helpEl.hidden = true;
    const helpSummary = document.createElement('summary');
    helpSummary.className = 'dkan-aiq-playground-help-summary';
    helpSummary.textContent = 'Parameter help';
    helpEl.appendChild(helpSummary);
    const helpBody = document.createElement('div');
    helpBody.className = 'dkan-aiq-playground-help-body';
    helpEl.appendChild(helpBody);
    editorWrap.appendChild(helpEl);
    const editorLabel = document.createElement('label');
    editorLabel.className = 'dkan-aiq-playground-editor-label';
    editorLabel.textContent = 'Request body (JSON)';
    const textareaId = 'dkan-aiq-playground-body-' + Math.random().toString(36).slice(2, 8);
    editorLabel.setAttribute('for', textareaId);
    // Editor box wraps the textarea + a syntax-highlighted overlay <pre>.
    // The textarea text is rendered transparent (caret stays visible), and
    // the overlay sits behind it showing the colored tokens. They must
    // share font / padding / line-height pixel-perfectly so the colors
    // line up with the actual characters.
    const editorBox = document.createElement('div');
    editorBox.className = 'dkan-aiq-playground-editor-box';
    const overlay = document.createElement('pre');
    overlay.className = 'dkan-aiq-playground-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    const textarea = document.createElement('textarea');
    textarea.id = textareaId;
    textarea.className = 'dkan-aiq-playground-body';
    textarea.setAttribute('spellcheck', 'false');
    textarea.setAttribute('autocomplete', 'off');
    editorBox.appendChild(overlay);
    editorBox.appendChild(textarea);
    // Validity indicator — debounced JSON.parse status, separate from the
    // run-time errorEl below it which only fires on Run failures.
    const validityEl = document.createElement('div');
    validityEl.className = 'dkan-aiq-playground-validity';
    validityEl.hidden = true;
    let validityTimer = null;
    const refreshHighlight = () => {
      overlay.innerHTML = highlightJson(textarea.value);
    };
    const syncScroll = () => {
      overlay.scrollTop = textarea.scrollTop;
      overlay.scrollLeft = textarea.scrollLeft;
    };
    const refreshValidity = () => {
      if (validityTimer) clearTimeout(validityTimer);
      validityTimer = setTimeout(() => {
        const v = validateJson(textarea.value);
        if (v.status === 'empty') {
          validityEl.hidden = true;
          return;
        }
        validityEl.hidden = false;
        if (v.ok) {
          validityEl.textContent = '✓ Valid JSON';
          validityEl.classList.remove('is-invalid');
          validityEl.classList.add('is-valid');
        }
        else {
          const where = (v.line >= 0) ? ('Line ' + v.line + ', col ' + v.col + ': ') : '';
          validityEl.textContent = where + v.message;
          validityEl.classList.remove('is-valid');
          validityEl.classList.add('is-invalid');
        }
      }, 150);
    };
    textarea.addEventListener('input', () => {
      this.playground.dirty = true;
      refreshHighlight();
      refreshValidity();
    });
    textarea.addEventListener('scroll', syncScroll);
    const actions = document.createElement('div');
    actions.className = 'dkan-aiq-playground-actions';
    const runBtn = document.createElement('button');
    runBtn.type = 'button';
    runBtn.className = 'dkan-aiq-playground-run';
    runBtn.textContent = 'Run';
    runBtn.addEventListener('click', () => { this.runPlayground(); });
    const resetBtn = document.createElement('button');
    resetBtn.type = 'button';
    resetBtn.className = 'dkan-aiq-playground-reset';
    resetBtn.textContent = 'Reset';
    resetBtn.addEventListener('click', () => {
      const cur = this.playground.current;
      if (!cur || cur.originalBodyJson == null) return;
      this.setPlaygroundEditorValue(cur.originalBodyJson);
      this.playground.dirty = false;
      errorEl.hidden = true;
      this.playground.responseHost.hidden = true;
      this.playground.responseHost.innerHTML = '';
    });
    actions.appendChild(runBtn);
    actions.appendChild(resetBtn);
    // Open-URL button: GET requests can be re-issued from a new browser tab
    // (handy for sharing, bookmarking, or running outside the playground).
    // Hidden for POST since browsers can't address-bar-submit a body.
    const openTabBtn = document.createElement('button');
    openTabBtn.type = 'button';
    openTabBtn.className = 'dkan-aiq-playground-open-tab';
    openTabBtn.textContent = 'Open URL ↗';
    openTabBtn.title = 'Open this GET request in a new browser tab';
    openTabBtn.hidden = true;
    openTabBtn.addEventListener('click', () => { this.openPlaygroundInNewTab(); });
    actions.appendChild(openTabBtn);
    const errorEl = document.createElement('div');
    errorEl.className = 'dkan-aiq-playground-error';
    errorEl.hidden = true;
    editorWrap.appendChild(editorLabel);
    editorWrap.appendChild(editorBox);
    editorWrap.appendChild(validityEl);
    editorWrap.appendChild(actions);
    editorWrap.appendChild(errorEl);
    aside.appendChild(editorWrap);

    // History dropdown — hidden until the first run. Click an entry to
    // restore body + cached response without re-fetching.
    const historyWrap = document.createElement('details');
    historyWrap.className = 'dkan-aiq-playground-history';
    historyWrap.hidden = true;
    const historySummary = document.createElement('summary');
    historySummary.className = 'dkan-aiq-playground-history-summary';
    historySummary.textContent = 'Recent runs';
    historyWrap.appendChild(historySummary);
    const historyList = document.createElement('ol');
    historyList.className = 'dkan-aiq-playground-history-list';
    historyWrap.appendChild(historyList);
    aside.appendChild(historyWrap);

    // Response area — populated by renderPlaygroundResponse on each Run.
    const responseHost = document.createElement('div');
    responseHost.className = 'dkan-aiq-playground-response';
    responseHost.hidden = true;
    aside.appendChild(responseHost);

    this.root.appendChild(aside);
    this.playground.el = aside;
    this.playground.editor = textarea;
    this.playground.overlay = overlay;
    this.playground.validityEl = validityEl;
    this.playground.refreshHighlight = refreshHighlight;
    this.playground.refreshValidity = refreshValidity;
    this.playground.responseHost = responseHost;
    this.playground.runBtn = runBtn;
    this.playground.resetBtn = resetBtn;
    this.playground.openTabBtn = openTabBtn;
    this.playground.errorEl = errorEl;
    this.playground.methodEl = methodEl;
    this.playground.urlEl = urlEl;
    this.playground.historyEl = historyWrap;
    this.playground.historyListEl = historyList;
    this.playground.historySummaryEl = historySummary;
    this.playground.noteEl = noteEl;
    this.playground.helpEl = helpEl;
    this.playground.helpBodyEl = helpBody;
    this.playground.toggleBtn = toggleBtn;
    this.playground.railSummary = railSummary;
    this.playground.railCount = railSummary.querySelector('.dkan-aiq-playground-rail-count');
    this.bindPlaygroundToggle();
    this.applyPlaygroundCollapsedState();
    // History UI may already be populated from a previous open in the same
    // widget instance — render it now so re-opening preserves the dropdown.
    this.refreshPlaygroundHistoryUI();
    return aside;
  };

  /**
   * Single setter for the playground editor value. Keeps the textarea
   * (source of truth), the syntax-highlighted overlay, and the validity
   * indicator in sync. All callers that programmatically replace the body
   * (openPlayground, Reset, restorePlaygroundRun) go through here.
   */
  Widget.prototype.setPlaygroundEditorValue = function (value) {
    this.playground.editor.value = value;
    if (this.playground.refreshHighlight) this.playground.refreshHighlight();
    if (this.playground.refreshValidity) this.playground.refreshValidity();
  };

  Widget.prototype.openPlayground = function (request) {
    this.ensurePlaygroundSidebar();
    if (this.playground.dirty && !window.confirm('Discard your unsaved edits in the API playground?')) {
      return;
    }
    const bodyJson = JSON.stringify(request.body, null, 2);
    this.playground.current = {
      tool: request.tool,
      method: request.method,
      url: request.url,
      body: request.body,
      note: request.note || null,
      originalBodyJson: bodyJson,
    };
    this.setPlaygroundEditorValue(bodyJson);
    this.playground.dirty = false;
    this.playground.errorEl.hidden = true;
    this.playground.errorEl.textContent = '';
    this.playground.responseHost.hidden = true;
    this.playground.responseHost.innerHTML = '';
    this.playground.methodEl.textContent = request.method;
    this.playground.methodEl.className = 'dkan-aiq-playground-method is-' + request.method.toLowerCase();
    this.playground.urlEl.textContent = request.url;
    this.playground.openTabBtn.hidden = (request.method !== 'GET');
    if (this.playground.noteEl) {
      if (request.note) {
        this.playground.noteEl.textContent = request.note;
        this.playground.noteEl.hidden = false;
      }
      else {
        this.playground.noteEl.hidden = true;
        this.playground.noteEl.textContent = '';
      }
    }
    this.refreshPlaygroundHelp(request.method, request.url);
    this.root.classList.add('dkan-aiq-widget--with-playground');
    this.playground.el.setAttribute('aria-hidden', 'false');
    this.playground.open = true;
    // A new bubble click implies the user wants to see the new request, so
    // override any persisted collapsed state. Don't rewrite localStorage —
    // the user's last explicit chevron click is still their preference.
    if (this.playground.el.classList.contains('dkan-aiq-playground-sidebar--collapsed')) {
      this.setPlaygroundCollapsed(false);
    }
    // Defer focus so the slide-in transition doesn't fight it.
    setTimeout(() => { this.playground.editor.focus(); }, 50);
  };

  Widget.prototype.closePlayground = function () {
    if (!this.playground.el) return;
    this.root.classList.remove('dkan-aiq-widget--with-playground');
    this.playground.el.setAttribute('aria-hidden', 'true');
    this.playground.open = false;
  };

  Widget.prototype.bindPlaygroundToggle = function () {
    if (!this.playground.toggleBtn || !this.playground.el) {
      return;
    }
    const toggle = () => {
      const collapsed = !this.playground.el.classList.contains('dkan-aiq-playground-sidebar--collapsed');
      this.setPlaygroundCollapsed(collapsed);
      try {
        localStorage.setItem(PLAYGROUND_COLLAPSED_KEY, collapsed ? '1' : '0');
      }
      catch (e) {
        // localStorage unavailable (Safari private mode); collapse still works
        // for the current session.
      }
    };
    this.playground.toggleBtn.addEventListener('click', toggle);
    if (this.playground.railSummary) {
      this.playground.railSummary.addEventListener('click', () => {
        // Rail summary is only visible when collapsed → only ever expands.
        this.setPlaygroundCollapsed(false);
        try {
          localStorage.setItem(PLAYGROUND_COLLAPSED_KEY, '0');
        }
        catch (e) {}
      });
    }
  };

  Widget.prototype.applyPlaygroundCollapsedState = function () {
    let collapsed = false;
    try {
      collapsed = localStorage.getItem(PLAYGROUND_COLLAPSED_KEY) === '1';
    }
    catch (e) {
      // Default to expanded.
    }
    this.setPlaygroundCollapsed(collapsed);
  };

  Widget.prototype.setPlaygroundCollapsed = function (collapsed) {
    if (!this.playground.el) {
      return;
    }
    this.playground.el.classList.toggle('dkan-aiq-playground-sidebar--collapsed', collapsed);
    if (this.playground.toggleBtn) {
      this.playground.toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      this.playground.toggleBtn.setAttribute(
        'aria-label',
        collapsed ? 'Expand playground' : 'Collapse playground'
      );
    }
  };

  Widget.prototype.updatePlaygroundRailCount = function () {
    const count = this.playgroundHistory.length;
    if (this.playground.railCount) {
      this.playground.railCount.textContent = String(count);
    }
    if (this.playground.railSummary) {
      this.playground.railSummary.setAttribute('data-count', String(count));
      this.playground.railSummary.setAttribute(
        'aria-label',
        count
          ? 'Expand API playground (' + count + ' recent run' + (count !== 1 ? 's' : '') + ')'
          : 'Expand API playground'
      );
    }
  };

  /**
   * Populate the playground "Parameter help" panel from the OpenAPI spec.
   *
   * Fires asynchronously (the spec fetch may not yet be resolved). The current
   * (method, url) is stashed on `this.playground.current` before this is
   * called; when the spec resolves we re-check current still matches before
   * rendering, so a quick second openPlayground call doesn't overwrite the
   * newer help with stale content.
   */
  Widget.prototype.refreshPlaygroundHelp = function (method, url) {
    const helpEl = this.playground.helpEl;
    const bodyEl = this.playground.helpBodyEl;
    if (!helpEl || !bodyEl) return;
    helpEl.hidden = true;
    bodyEl.innerHTML = '';
    const widget = this;
    OpenApiSpec.load().then((spec) => {
      const cur = widget.playground.current;
      if (!cur || cur.method !== method || cur.url !== url) {
        // A newer openPlayground call superseded this one before the spec
        // resolved — bail out so we don't overwrite the newer help.
        return;
      }
      const help = OpenApiSpec.describe(spec, method, url);
      if (!help) return;
      renderPlaygroundHelp(bodyEl, help);
      helpEl.hidden = false;
    }).catch(() => { /* graceful degradation */ });
  };

  /**
   * Render an OpenApiSpec.describe() result into the help panel body.
   * Builds DOM nodes (no innerHTML/templating) so descriptions are safely
   * escaped even though they come from a same-origin source.
   */
  function renderPlaygroundHelp(bodyEl, help) {
    bodyEl.innerHTML = '';
    if (help.summary) {
      const sum = document.createElement('div');
      sum.className = 'dkan-aiq-playground-help-summary-text';
      sum.textContent = help.summary;
      bodyEl.appendChild(sum);
    }
    if (help.description) {
      const desc = document.createElement('div');
      desc.className = 'dkan-aiq-playground-help-description';
      desc.textContent = help.description;
      bodyEl.appendChild(desc);
    }
    const sections = [];
    if (help.parameters && help.parameters.length) {
      sections.push({ title: 'URL parameters', items: help.parameters, showLocation: true });
    }
    if (help.bodyProperties && help.bodyProperties.length) {
      sections.push({ title: 'Body properties', items: help.bodyProperties, showLocation: false });
    }
    sections.forEach((section) => {
      const wrap = document.createElement('div');
      wrap.className = 'dkan-aiq-playground-help-section';
      const title = document.createElement('div');
      title.className = 'dkan-aiq-playground-help-section-title';
      title.textContent = section.title;
      wrap.appendChild(title);
      const list = document.createElement('dl');
      list.className = 'dkan-aiq-playground-help-list';
      section.items.forEach((item) => {
        const dt = document.createElement('dt');
        dt.className = 'dkan-aiq-playground-help-term';
        const name = document.createElement('code');
        name.className = 'dkan-aiq-playground-help-name';
        name.textContent = item.name;
        dt.appendChild(name);
        if (item.type) {
          const type = document.createElement('span');
          type.className = 'dkan-aiq-playground-help-type';
          type.textContent = item.type;
          dt.appendChild(type);
        }
        if (section.showLocation && item.in) {
          const loc = document.createElement('span');
          loc.className = 'dkan-aiq-playground-help-location';
          loc.textContent = item.in;
          dt.appendChild(loc);
        }
        if (item.required) {
          const req = document.createElement('span');
          req.className = 'dkan-aiq-playground-help-required';
          req.textContent = 'required';
          dt.appendChild(req);
        }
        list.appendChild(dt);
        const dd = document.createElement('dd');
        dd.className = 'dkan-aiq-playground-help-desc';
        dd.textContent = item.description || '—';
        list.appendChild(dd);
      });
      wrap.appendChild(list);
      bodyEl.appendChild(wrap);
    });
  }

  Widget.prototype.runPlayground = function () {
    const cur = this.playground.current;
    if (!cur) return;
    const raw = this.playground.editor.value;
    let parsed;
    try {
      parsed = JSON.parse(raw);
    }
    catch (e) {
      this.playground.errorEl.hidden = false;
      this.playground.errorEl.textContent = 'Invalid JSON: ' + e.message;
      return;
    }
    this.playground.errorEl.hidden = true;
    cur.body = parsed;

    // Defensive cross-origin guard. The url comes from buildApiEquivalent()
    // which always returns a relative path today, but if a future change
    // ever produced an absolute URL pointing elsewhere, fetch would still
    // happily fire and bypass same-origin assumptions about the user's
    // session cookies.
    if (/^https?:/i.test(cur.url)) {
      try {
        const u = new URL(cur.url);
        if (u.origin !== window.location.origin) {
          this.playground.errorEl.hidden = false;
          this.playground.errorEl.textContent = 'Cross-origin requests are not supported in the playground.';
          return;
        }
      }
      catch (e) {
        this.playground.errorEl.hidden = false;
        this.playground.errorEl.textContent = 'Could not parse request URL: ' + e.message;
        return;
      }
    }

    // For GET requests the editor body is a params object; serialize to a
    // query string and bake it into the URL so the actual fetch and the
    // snippet generators (which all show URL verbatim for GET) see the same
    // thing the user is about to send.
    let runReq;
    if (cur.method === 'GET') {
      const qsStr = paramsToQueryString(cur.body);
      runReq = { method: 'GET', url: cur.url + (qsStr ? '?' + qsStr : ''), body: cur.body };
    }
    else {
      runReq = { method: cur.method, url: cur.url, body: cur.body };
    }

    this.playground.runBtn.disabled = true;
    this.playground.runBtn.textContent = 'Running…';
    Promise.resolve(this.csrfToken || ensureCsrfToken()).then((tokenOrEmpty) => {
      const csrfToken = typeof tokenOrEmpty === 'string' ? tokenOrEmpty : '';
      return runPlaygroundRequest(runReq, csrfToken)
        .then((result) => {
          this.playground.responseHost.hidden = false;
          this.playground.responseHost.innerHTML = '';
          renderPlaygroundResponse(this, this.playground.responseHost, runReq, result, csrfToken);
          this.recordPlaygroundRun(runReq, result, csrfToken);
        });
    }).catch((err) => {
      this.playground.errorEl.hidden = false;
      this.playground.errorEl.textContent = 'Playground error: ' + (err && err.message ? err.message : String(err));
    }).then(() => {
      this.playground.runBtn.disabled = false;
      this.playground.runBtn.textContent = 'Run';
    });
  };

  /**
   * Open the current GET request in a new browser tab. Reads the editor
   * body, validates it as JSON (same inline-error path as Run), serializes
   * to a query string with the shared encoder, and opens the absolute URL
   * with noopener/noreferrer. No-op for POST.
   */
  Widget.prototype.openPlaygroundInNewTab = function () {
    const cur = this.playground.current;
    if (!cur || cur.method !== 'GET') return;
    let parsed;
    try {
      parsed = JSON.parse(this.playground.editor.value);
    }
    catch (e) {
      this.playground.errorEl.hidden = false;
      this.playground.errorEl.textContent = 'Invalid JSON: ' + e.message;
      return;
    }
    this.playground.errorEl.hidden = true;
    const qsStr = paramsToQueryString(parsed);
    const fullUrl = cur.url + (qsStr ? '?' + qsStr : '');
    // Promote to absolute so window.open behaves identically regardless of
    // the page's base href; same-origin by construction.
    const absUrl = /^https?:/i.test(fullUrl) ? fullUrl : (window.location.origin + fullUrl);
    window.open(absUrl, '_blank', 'noopener,noreferrer');
  };

  /**
   * Serialize an object of {key: value | array} into a URL query string.
   * Shared by runPlayground (for GET fetches) and openPlaygroundInNewTab
   * (for new-tab URL composition) so encoding is identical in both paths.
   */
  function paramsToQueryString(obj) {
    const qs = new URLSearchParams();
    Object.keys(obj || {}).forEach((k) => {
      const v = obj[k];
      if (Array.isArray(v)) {
        v.forEach((item) => qs.append(k, String(item)));
      }
      else if (v != null) {
        qs.append(k, String(v));
      }
    });
    return qs.toString();
  }

  /**
   * Map a playground response result to its status badge CSS class.
   * Network errors get is-error, 2xx is-2xx, 4xx is-4xx, 5xx is-5xx,
   * everything else (1xx/3xx) is-info.
   */
  function statusClassFor(result) {
    if (result.networkError) return 'is-error';
    if (result.status >= 500) return 'is-5xx';
    if (result.status >= 400) return 'is-4xx';
    if (result.status >= 200) return 'is-2xx';
    return 'is-info';
  }

  /**
   * Shorten a URL for the history dropdown. Strips a leading /api/1/ and any
   * query string; falls back to the last path segment if still too long.
   * Cap is generous (~48 chars) since the dropdown row has a generous
   * 1fr column.
   */
  function shortenUrl(url) {
    let u = url.split('?')[0];
    u = u.replace(/^\/api\/1\//, '');
    if (u.length > 48) {
      const segs = u.split('/');
      u = '…/' + segs[segs.length - 1];
    }
    return u;
  }

  /**
   * Format a millisecond timestamp as "Ns ago" / "Nm ago" / "Nh ago".
   * Caps at hours; the playground state doesn't survive longer than a
   * session anyway.
   */
  function formatTimeAgo(ts) {
    const sec = Math.max(0, Math.floor((Date.now() - ts) / 1000));
    if (sec < 5) return 'just now';
    if (sec < 60) return sec + 's ago';
    const min = Math.floor(sec / 60);
    if (min < 60) return min + 'm ago';
    const hr = Math.floor(min / 60);
    return hr + 'h ago';
  }

  /**
   * Tokenize a JSON-ish string. Tolerates malformed input — the playground
   * editor highlights as you type, so partially-broken JSON is the common
   * case. Unrecognized characters fall through as 'unknown' tokens so the
   * overlay still covers the full source and stays aligned with the
   * textarea below it.
   */
  function tokenizeJson(src) {
    const tokens = [];
    let i = 0;
    const len = src.length;
    while (i < len) {
      const c = src[i];
      if (c === ' ' || c === '\t' || c === '\n' || c === '\r') {
        const start = i;
        while (i < len && (src[i] === ' ' || src[i] === '\t' || src[i] === '\n' || src[i] === '\r')) i++;
        tokens.push({ type: 'ws', text: src.slice(start, i) });
        continue;
      }
      if (c === '"') {
        const start = i;
        i++;
        while (i < len) {
          if (src[i] === '\\' && i + 1 < len) { i += 2; continue; }
          if (src[i] === '"') { i++; break; }
          i++;
        }
        tokens.push({ type: 'string', text: src.slice(start, i) });
        continue;
      }
      if (c === '-' || (c >= '0' && c <= '9')) {
        const start = i;
        if (src[i] === '-') i++;
        while (i < len && src[i] >= '0' && src[i] <= '9') i++;
        if (src[i] === '.') {
          i++;
          while (i < len && src[i] >= '0' && src[i] <= '9') i++;
        }
        if (src[i] === 'e' || src[i] === 'E') {
          i++;
          if (src[i] === '+' || src[i] === '-') i++;
          while (i < len && src[i] >= '0' && src[i] <= '9') i++;
        }
        tokens.push({ type: 'number', text: src.slice(start, i) });
        continue;
      }
      if (src.slice(i, i + 4) === 'true') { tokens.push({ type: 'bool', text: 'true' }); i += 4; continue; }
      if (src.slice(i, i + 5) === 'false') { tokens.push({ type: 'bool', text: 'false' }); i += 5; continue; }
      if (src.slice(i, i + 4) === 'null') { tokens.push({ type: 'null', text: 'null' }); i += 4; continue; }
      if (c === '{' || c === '}' || c === '[' || c === ']' || c === ',' || c === ':') {
        tokens.push({ type: 'punct', text: c });
        i++;
        continue;
      }
      tokens.push({ type: 'unknown', text: c });
      i++;
    }
    // Second pass: a string immediately followed (modulo whitespace) by `:`
    // is an object key. Coloring keys distinctly is the single biggest
    // readability win for nested JSON.
    for (let j = 0; j < tokens.length; j++) {
      if (tokens[j].type !== 'string') continue;
      let k = j + 1;
      while (k < tokens.length && tokens[k].type === 'ws') k++;
      if (k < tokens.length && tokens[k].type === 'punct' && tokens[k].text === ':') {
        tokens[j].type = 'key';
      }
    }
    return tokens;
  }

  function highlightJson(src) {
    const tokens = tokenizeJson(src);
    const escape = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    let out = '';
    for (const t of tokens) {
      const safe = escape(t.text);
      if (t.type === 'ws' || t.type === 'unknown') {
        out += safe;
      }
      else {
        out += '<span class="dkan-aiq-tok-' + t.type + '">' + safe + '</span>';
      }
    }
    // Trailing space keeps the overlay's last line tall enough that the
    // textarea's caret on a final blank line still has a row to align to.
    return out + ' ';
  }

  /**
   * Validate JSON and locate the parse error (line/col) when possible.
   * `JSON.parse` error message text varies across browsers — modern V8
   * includes "(at position N)", which we map to a 1-indexed line/col by
   * walking the source. Older messages or different shapes fall back to
   * just the message text. Empty source returns ok=true with status='empty'
   * so the indicator can stay hidden.
   */
  function validateJson(src) {
    if (src.trim() === '') return { ok: true, status: 'empty' };
    try {
      JSON.parse(src);
      return { ok: true };
    }
    catch (e) {
      const msg = (e && e.message) ? e.message : String(e);
      const posMatch = msg.match(/position\s+(\d+)/);
      let line = -1;
      let col = -1;
      if (posMatch) {
        const pos = parseInt(posMatch[1], 10);
        let l = 1;
        let c = 1;
        const stop = Math.min(pos, src.length);
        for (let i = 0; i < stop; i++) {
          if (src.charCodeAt(i) === 10) { l++; c = 1; }
          else { c++; }
        }
        line = l;
        col = c;
      }
      return { ok: false, message: msg, line: line, col: col };
    }
  }

  /**
   * Record a completed playground run into the per-instance history. The
   * UI is refreshed so the dropdown reflects the new entry. LRU-trimmed
   * to PLAYGROUND_HISTORY_CAP.
   */
  Widget.prototype.recordPlaygroundRun = function (request, result, csrfToken) {
    this.playgroundHistory.unshift({
      request: { method: request.method, url: request.url, body: request.body },
      result: result,
      csrfToken: csrfToken,
      timestamp: Date.now(),
    });
    if (this.playgroundHistory.length > PLAYGROUND_HISTORY_CAP) {
      this.playgroundHistory.length = PLAYGROUND_HISTORY_CAP;
    }
    this.refreshPlaygroundHistoryUI();
  };

  /**
   * Rebuild the history dropdown from this.playgroundHistory. Hides the
   * <details> when empty so the sidebar doesn't show an inert affordance.
   */
  Widget.prototype.refreshPlaygroundHistoryUI = function () {
    this.updatePlaygroundRailCount();
    if (!this.playground.historyEl) return;
    const list = this.playground.historyListEl;
    list.innerHTML = '';
    if (this.playgroundHistory.length === 0) {
      this.playground.historyEl.hidden = true;
      return;
    }
    this.playground.historyEl.hidden = false;
    this.playground.historySummaryEl.textContent = 'Recent runs (' + this.playgroundHistory.length + ')';
    this.playgroundHistory.forEach((entry) => {
      const li = document.createElement('li');
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'dkan-aiq-playground-history-item';
      const status = document.createElement('span');
      status.className = 'dkan-aiq-playground-history-status ' + statusClassFor(entry.result);
      status.textContent = entry.result.networkError ? 'ERR' : String(entry.result.status);
      btn.appendChild(status);
      const endpoint = document.createElement('span');
      endpoint.className = 'dkan-aiq-playground-history-endpoint';
      endpoint.textContent = entry.request.method + ' ' + shortenUrl(entry.request.url);
      btn.appendChild(endpoint);
      const dur = document.createElement('span');
      dur.className = 'dkan-aiq-playground-history-dur';
      dur.textContent = entry.result.durationMs + 'ms';
      btn.appendChild(dur);
      const ago = document.createElement('span');
      ago.className = 'dkan-aiq-playground-history-ago';
      ago.textContent = formatTimeAgo(entry.timestamp);
      btn.appendChild(ago);
      btn.addEventListener('click', () => this.restorePlaygroundRun(entry));
      li.appendChild(btn);
      list.appendChild(li);
    });
  };

  /**
   * Load a historical entry's request + response back into the playground
   * without re-fetching. Updates the editor, header, response area, and
   * Open-URL button visibility; clears the dirty flag because we're loading
   * a known state.
   */
  Widget.prototype.restorePlaygroundRun = function (entry) {
    const bodyJson = JSON.stringify(entry.request.body, null, 2);
    this.playground.current = {
      method: entry.request.method,
      url: entry.request.url.split('?')[0],
      body: entry.request.body,
      originalBodyJson: bodyJson,
    };
    this.setPlaygroundEditorValue(bodyJson);
    this.playground.dirty = false;
    this.playground.errorEl.hidden = true;
    this.playground.methodEl.textContent = entry.request.method;
    this.playground.methodEl.className = 'dkan-aiq-playground-method is-' + entry.request.method.toLowerCase();
    this.playground.urlEl.textContent = this.playground.current.url;
    if (this.playground.openTabBtn) {
      this.playground.openTabBtn.hidden = (entry.request.method !== 'GET');
    }
    this.playground.responseHost.hidden = false;
    this.playground.responseHost.innerHTML = '';
    renderPlaygroundResponse(this, this.playground.responseHost, entry.request, entry.result, entry.csrfToken);
  };

  /**
   * Execute a REST request and capture status/headers/body for display.
   *
   * Always resolves (never rejects) — network errors come back as
   * {networkError, durationMs} so the response renderer has one code path.
   */
  function runPlaygroundRequest(req, csrfToken) {
    const headers = { 'Accept': 'application/json' };
    let bodyText;
    if (req.method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      bodyText = JSON.stringify(req.body);
      if (csrfToken) {
        headers['X-CSRF-Token'] = csrfToken;
      }
    }
    const start = performance.now();
    return fetch(req.url, {
      method: req.method,
      headers: headers,
      body: bodyText,
      credentials: 'same-origin',
    })
      .then((response) => {
        const durationMs = Math.round(performance.now() - start);
        const headerMap = {};
        response.headers.forEach((v, k) => { headerMap[k] = v; });
        return response.text().then((text) => {
          let parsedBody = null;
          try { parsedBody = JSON.parse(text); }
          catch (e) { /* leave null; renderer falls back to raw text */ }
          return {
            status: response.status,
            statusText: response.statusText,
            headers: headerMap,
            bodyText: text,
            parsedBody: parsedBody,
            durationMs: durationMs,
            networkError: null,
          };
        });
      })
      .catch((err) => {
        return {
          status: null,
          statusText: null,
          headers: {},
          bodyText: '',
          parsedBody: null,
          durationMs: Math.round(performance.now() - start),
          networkError: err && err.message ? err.message : String(err),
        };
      });
  }

  /**
   * Render a playground request's response into `host`.
   *
   * Tabs: Body (pretty JSON, with a 500 KB display cap + Download fallback),
   * Code (copyable snippet in cURL / HTTPie / Python / JavaScript / PHP),
   * Headers (key/value list).
   */
  function renderPlaygroundResponse(widget, host, request, result, csrfToken) {
    // Status row.
    const statusRow = document.createElement('div');
    statusRow.className = 'dkan-aiq-playground-status-row';
    const badge = document.createElement('span');
    badge.className = 'dkan-aiq-playground-status';
    if (result.networkError) {
      badge.classList.add('is-error');
      badge.textContent = 'Network error';
    }
    else {
      const cls = result.status >= 500 ? 'is-5xx' : (result.status >= 400 ? 'is-4xx' : (result.status >= 200 ? 'is-2xx' : 'is-info'));
      badge.classList.add(cls);
      badge.textContent = result.status + ' ' + (result.statusText || '');
    }
    statusRow.appendChild(badge);
    const dur = document.createElement('span');
    dur.className = 'dkan-aiq-playground-duration';
    dur.textContent = result.durationMs + ' ms';
    statusRow.appendChild(dur);
    if (result.status === 403 && !csrfToken) {
      const hint = document.createElement('span');
      hint.className = 'dkan-aiq-playground-hint';
      hint.textContent = 'Tip: this endpoint may require a CSRF token, but the widget could not fetch one.';
      statusRow.appendChild(hint);
    }
    host.appendChild(statusRow);

    // Disclaimer: the playground response is the raw REST shape. The agent
    // tool may transform this further (sanity flags, dictionary enrichment,
    // case-corrected columns, etc.) before the LLM sees it.
    const disclaimer = document.createElement('div');
    disclaimer.className = 'dkan-aiq-playground-disclaimer';
    disclaimer.textContent = 'REST response — the agent tool may transform this further before passing it to the LLM.';
    host.appendChild(disclaimer);

    // Tab strip.
    const tabs = document.createElement('div');
    tabs.className = 'dkan-aiq-playground-tabs';
    const panel = document.createElement('div');
    panel.className = 'dkan-aiq-playground-tabpanel';
    const tabSpecs = [
      { id: 'body', label: 'Body', render: () => renderPlaygroundBody(result) },
    ];
    // Table tab is only added when the response has tabular rows. Handles
    // both array-shaped results (datastore) and object-shaped results
    // (/api/1/search keyed by URI). Skipped on errors or non-tabular endpoints.
    if (extractPlaygroundRows(result.parsedBody).length > 0) {
      tabSpecs.push({ id: 'table', label: 'Table', render: () => renderPlaygroundTable(result.parsedBody) });
    }
    tabSpecs.push({ id: 'code', label: 'Code', render: () => renderPlaygroundCode(widget, request, csrfToken) });
    tabSpecs.push({ id: 'headers', label: 'Headers', render: () => renderPlaygroundHeaders(result.headers) });
    tabSpecs.forEach((spec, i) => {
      const tab = document.createElement('button');
      tab.type = 'button';
      tab.className = 'dkan-aiq-playground-tab' + (i === 0 ? ' is-active' : '');
      tab.textContent = spec.label;
      tab.addEventListener('click', () => {
        tabs.querySelectorAll('.dkan-aiq-playground-tab').forEach((t) => t.classList.remove('is-active'));
        tab.classList.add('is-active');
        panel.innerHTML = '';
        panel.appendChild(spec.render());
      });
      tabs.appendChild(tab);
    });
    host.appendChild(tabs);
    panel.appendChild(tabSpecs[0].render());
    host.appendChild(panel);
  }

  /**
   * Coerce the response's `results` field into a flat array of row objects.
   * DKAN's datastore returns an array; /api/1/search returns an object keyed
   * by dataset URI whose values are the dataset records — both shapes are
   * "tabular" and we display them the same way.
   */
  function extractPlaygroundRows(parsedBody) {
    if (!parsedBody) return [];
    const r = parsedBody.results;
    if (Array.isArray(r)) return r;
    if (r && typeof r === 'object') return Object.values(r);
    return [];
  }

  /**
   * Render the response's `results` as a plain HTML table. Only invoked when
   * extractPlaygroundRows returns a non-empty array; the tab itself isn't
   * added otherwise. Cell truncation matches the bubble tables
   * (CELL_TRUNCATE_LEN, click to expand).
   */
  function renderPlaygroundTable(parsedBody) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-playground-table-tab';

    const rows = extractPlaygroundRows(parsedBody);
    if (rows.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'dkan-aiq-playground-empty';
      empty.textContent = 'No rows in response.';
      wrap.appendChild(empty);
      return wrap;
    }

    // Union of keys across the first ~20 rows so heterogeneous responses
    // don't lose late-appearing columns.
    const cols = [];
    const seen = new Set();
    rows.slice(0, 20).forEach((r) => {
      if (r && typeof r === 'object') {
        Object.keys(r).forEach((k) => {
          if (!seen.has(k)) { seen.add(k); cols.push(k); }
        });
      }
    });

    const table = document.createElement('table');
    table.className = 'dkan-aiq-playground-table';
    const thead = document.createElement('thead');
    const trh = document.createElement('tr');
    cols.forEach((c) => {
      const th = document.createElement('th');
      th.textContent = c;
      trh.appendChild(th);
    });
    thead.appendChild(trh);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    rows.forEach((r) => {
      const tr = document.createElement('tr');
      cols.forEach((c) => {
        const td = document.createElement('td');
        const v = r ? r[c] : '';
        const text = (v == null) ? '' : (typeof v === 'object' ? JSON.stringify(v) : String(v));
        if (text.length > CELL_TRUNCATE_LEN) {
          td.textContent = text.slice(0, CELL_TRUNCATE_LEN) + '…';
          td.classList.add('is-truncated');
          td.title = 'Click to expand';
          let expanded = false;
          td.addEventListener('click', () => {
            expanded = !expanded;
            td.textContent = expanded ? text : (text.slice(0, CELL_TRUNCATE_LEN) + '…');
          });
        }
        else {
          td.textContent = text;
        }
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    const scroll = document.createElement('div');
    scroll.className = 'dkan-aiq-playground-table-scroll';
    scroll.appendChild(table);
    wrap.appendChild(scroll);

    const footer = document.createElement('div');
    footer.className = 'dkan-aiq-playground-table-footer';
    const total = parsedBody.count != null ? parsedBody.count
                : (parsedBody.total != null ? parsedBody.total : null);
    footer.textContent = total != null
      ? rows.length + ' of ' + total + ' rows'
      : rows.length + ' rows';
    wrap.appendChild(footer);

    return wrap;
  }

  function renderPlaygroundBody(result) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-playground-body-tab';
    if (result.networkError) {
      const err = document.createElement('div');
      err.className = 'dkan-aiq-playground-error';
      err.textContent = result.networkError;
      wrap.appendChild(err);
      return wrap;
    }
    const fullText = result.parsedBody !== null
      ? JSON.stringify(result.parsedBody, null, 2)
      : result.bodyText;
    const truncated = fullText.length > PLAYGROUND_RESPONSE_DISPLAY_CAP;
    const display = truncated ? fullText.slice(0, PLAYGROUND_RESPONSE_DISPLAY_CAP) + '\n\n…[truncated]' : fullText;
    if (result.parsedBody === null && result.bodyText) {
      const note = document.createElement('div');
      note.className = 'dkan-aiq-playground-nonjson';
      note.textContent = '(non-JSON response)';
      wrap.appendChild(note);
    }
    const pre = document.createElement('pre');
    pre.className = 'dkan-aiq-playground-pre';
    pre.textContent = display;
    wrap.appendChild(pre);
    if (truncated) {
      const note = document.createElement('div');
      note.className = 'dkan-aiq-playground-truncated';
      note.textContent = 'Response truncated for display — use Download to get the full payload.';
      wrap.appendChild(note);
      const dl = document.createElement('button');
      dl.type = 'button';
      dl.className = 'dkan-aiq-playground-download';
      dl.textContent = 'Download response';
      dl.addEventListener('click', () => {
        const blob = new Blob([fullText], { type: result.parsedBody !== null ? 'application/json' : 'text/plain' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'playground-response-' + Date.now() + (result.parsedBody !== null ? '.json' : '.txt');
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
      });
      wrap.appendChild(dl);
    }
    if (result.bodyText) {
      const copy = document.createElement('button');
      copy.type = 'button';
      copy.className = 'dkan-aiq-playground-copy';
      copy.textContent = 'Copy body';
      copy.addEventListener('click', () => {
        navigator.clipboard.writeText(fullText).then(() => {
          copy.textContent = 'Copied!';
          setTimeout(() => { copy.textContent = 'Copy body'; }, 1500);
        });
      });
      wrap.appendChild(copy);
    }
    return wrap;
  }

  /**
   * Render the Code tab: a segmented language picker on top, the snippet in
   * a <pre>, and a Copy button below. Last-picked language persists on the
   * widget so jumping between bubbles stays on the same language.
   */
  function renderPlaygroundCode(widget, request, csrfToken) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-playground-code-tab';

    const picker = document.createElement('div');
    picker.className = 'dkan-aiq-playground-lang-picker';
    picker.setAttribute('role', 'tablist');
    picker.setAttribute('aria-label', 'Code language');

    const pre = document.createElement('pre');
    pre.className = 'dkan-aiq-playground-pre';
    const copy = document.createElement('button');
    copy.type = 'button';
    copy.className = 'dkan-aiq-playground-copy';

    const langButtons = new Map();

    function paint(langId) {
      widget.playgroundCodeLang = langId;
      const lang = PLAYGROUND_CODE_LANGUAGES.find((l) => l.id === langId)
        || PLAYGROUND_CODE_LANGUAGES[0];
      const code = buildPlaygroundCodeSnippet(lang.id, request, csrfToken);
      pre.textContent = code;
      copy.textContent = lang.copyLabel;
      copy.dataset.snippet = code;
      langButtons.forEach((btn, id) => {
        const active = id === lang.id;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
      });
    }

    PLAYGROUND_CODE_LANGUAGES.forEach((lang) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'dkan-aiq-playground-lang-btn';
      btn.setAttribute('role', 'tab');
      btn.dataset.lang = lang.id;
      btn.textContent = lang.label;
      btn.addEventListener('click', () => paint(lang.id));
      picker.appendChild(btn);
      langButtons.set(lang.id, btn);
    });

    copy.addEventListener('click', () => {
      const snippet = copy.dataset.snippet || '';
      const original = copy.textContent;
      navigator.clipboard.writeText(snippet).then(() => {
        copy.textContent = 'Copied!';
        setTimeout(() => { copy.textContent = original; }, 1500);
      });
    });

    wrap.appendChild(picker);
    wrap.appendChild(pre);
    wrap.appendChild(copy);

    const initial = (widget.playgroundCodeLang
      && PLAYGROUND_CODE_LANGUAGES.some((l) => l.id === widget.playgroundCodeLang))
      ? widget.playgroundCodeLang
      : 'curl';
    paint(initial);

    return wrap;
  }

  function renderPlaygroundHeaders(headers) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-playground-headers-tab';
    const dl = document.createElement('dl');
    dl.className = 'dkan-aiq-playground-headers';
    Object.keys(headers).sort().forEach((k) => {
      const dt = document.createElement('dt');
      dt.textContent = k;
      const dd = document.createElement('dd');
      dd.textContent = headers[k];
      dl.appendChild(dt);
      dl.appendChild(dd);
    });
    wrap.appendChild(dl);
    return wrap;
  }

  /**
   * Resolve a playground request URL to an absolute URL so snippets can be
   * pasted into any environment (cURL, requests, fetch in Node, etc.). The
   * playground itself fetches with the relative URL; snippets never can.
   */
  function playgroundAbsUrl(req) {
    return /^https?:/i.test(req.url) ? req.url : (window.location.origin + req.url);
  }

  /**
   * Compose a multi-line `curl` invocation for the given request.
   *
   * Single-quote the body so embedded double quotes survive the shell. Quotes
   * inside the body are escaped via the standard `'\''` POSIX trick.
   */
  function buildCurlCommand(req, csrfToken) {
    const absUrl = playgroundAbsUrl(req);
    const lines = ['curl -X ' + req.method + " '" + absUrl + "' \\"];
    lines.push("  -H 'Accept: application/json' \\");
    if (req.method !== 'GET') {
      lines.push("  -H 'Content-Type: application/json' \\");
      if (csrfToken) {
        lines.push("  -H 'X-CSRF-Token: " + csrfToken + "' \\");
      }
      const bodyJson = JSON.stringify(req.body);
      const escaped = bodyJson.replace(/'/g, "'\\''");
      lines.push("  --data-raw '" + escaped + "'");
    }
    else {
      // Drop the trailing backslash from the previous line for GET requests.
      lines[lines.length - 1] = lines[lines.length - 1].replace(/ \\$/, '');
    }
    return lines.join('\n');
  }

  /**
   * HTTPie 3.0+ invocation. Header lines use HTTPie's `Name:value` syntax;
   * the body rides on `--raw=` so it survives intact regardless of nesting.
   */
  function buildHttpieCommand(req, csrfToken) {
    const absUrl = playgroundAbsUrl(req);
    // Build all body lines first, then join with a trailing-backslash
    // continuation so the final line never has a stray slash. Avoids the
    // contortion of conditionally appending " \\" on every push.
    const parts = ["http " + req.method + " '" + absUrl + "'"];
    parts.push("  Accept:application/json");
    if (req.method !== 'GET') {
      parts.push("  Content-Type:application/json");
      if (csrfToken) {
        parts.push("  X-CSRF-Token:" + csrfToken);
      }
      const bodyJson = JSON.stringify(req.body);
      const escaped = bodyJson.replace(/'/g, "'\\''");
      parts.push("  --raw='" + escaped + "'");
    }
    return parts.join(' \\\n');
  }

  /**
   * Python `requests` snippet. Body is emitted as a triple-quoted JSON string
   * (rather than translated to a dict literal) — keeps booleans, nulls, and
   * nested structures byte-identical to what the playground actually sends.
   */
  function buildPythonRequestsCode(req, csrfToken) {
    const absUrl = playgroundAbsUrl(req);
    const out = ['import requests', ''];
    out.push('url = "' + absUrl + '"');
    const headerLines = ['    "Accept": "application/json",'];
    if (req.method !== 'GET') {
      headerLines.push('    "Content-Type": "application/json",');
      if (csrfToken) {
        headerLines.push('    "X-CSRF-Token": "' + csrfToken + '",');
      }
    }
    out.push('headers = {');
    out.push.apply(out, headerLines);
    out.push('}');
    if (req.method !== 'GET') {
      // Triple-quoted string keeps embedded " from JSON safe. JSON cannot
      // contain unescaped """ so the literal is unambiguous in practice.
      const bodyPretty = JSON.stringify(req.body, null, 2);
      out.push('body = """' + bodyPretty + '"""');
      out.push('');
      out.push('response = requests.' + req.method.toLowerCase() + '(url, headers=headers, data=body)');
    }
    else {
      out.push('');
      out.push('response = requests.get(url, headers=headers)');
    }
    out.push('print(response.status_code)');
    out.push('print(response.json())');
    return out.join('\n');
  }

  /**
   * JavaScript `fetch` snippet. The body is inlined as a JS object literal
   * (JSON is a strict subset of JS object syntax for our cases) and wrapped
   * in `JSON.stringify` so the wire format is identical to what the playground
   * actually sends.
   */
  function buildJavaScriptFetchCode(req, csrfToken) {
    const absUrl = playgroundAbsUrl(req);
    const out = [];
    out.push("const response = await fetch('" + absUrl + "', {");
    out.push("  method: '" + req.method + "',");
    out.push("  credentials: 'same-origin',");
    out.push("  headers: {");
    out.push("    'Accept': 'application/json',");
    if (req.method !== 'GET') {
      out.push("    'Content-Type': 'application/json',");
      if (csrfToken) {
        out.push("    'X-CSRF-Token': '" + csrfToken + "',");
      }
    }
    out.push("  },");
    if (req.method !== 'GET') {
      const bodyJson = JSON.stringify(req.body);
      out.push("  body: JSON.stringify(" + bodyJson + "),");
    }
    out.push("});");
    out.push("const data = await response.json();");
    out.push("console.log(data);");
    return out.join('\n');
  }

  /**
   * PHP cURL snippet. Body is emitted as a single-quoted PHP string with
   * `\` and `'` escaped — JSON-escaped Unicode (\uXXXX) contains backslashes
   * so the `\\` escape matters even when the body looks innocuous.
   */
  function buildPhpCurlCode(req, csrfToken) {
    const absUrl = playgroundAbsUrl(req);
    const out = [];
    out.push("$ch = curl_init('" + absUrl + "');");
    out.push("curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);");
    if (req.method !== 'GET') {
      if (req.method === 'POST') {
        out.push("curl_setopt($ch, CURLOPT_POST, true);");
      }
      else {
        out.push("curl_setopt($ch, CURLOPT_CUSTOMREQUEST, '" + req.method + "');");
      }
      const bodyJson = JSON.stringify(req.body);
      const phpEscaped = bodyJson.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
      out.push("curl_setopt($ch, CURLOPT_POSTFIELDS, '" + phpEscaped + "');");
    }
    out.push("curl_setopt($ch, CURLOPT_HTTPHEADER, [");
    out.push("    'Accept: application/json',");
    if (req.method !== 'GET') {
      out.push("    'Content-Type: application/json',");
      if (csrfToken) {
        out.push("    'X-CSRF-Token: " + csrfToken + "',");
      }
    }
    out.push("]);");
    out.push("$response = curl_exec($ch);");
    out.push("$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);");
    out.push("curl_close($ch);");
    out.push('echo $status . "\\n" . $response;');
    return out.join('\n');
  }

  /**
   * Dispatcher used by the Code tab to pick a generator by language id.
   * Falls back to cURL for unknown ids so a stale `playgroundCodeLang` from
   * a future build doesn't break the panel.
   */
  function buildPlaygroundCodeSnippet(lang, req, csrfToken) {
    switch (lang) {
      case 'httpie': return buildHttpieCommand(req, csrfToken);
      case 'python': return buildPythonRequestsCode(req, csrfToken);
      case 'js':     return buildJavaScriptFetchCode(req, csrfToken);
      case 'php':    return buildPhpCurlCode(req, csrfToken);
      case 'curl':
      default:       return buildCurlCommand(req, csrfToken);
    }
  }

  Widget.prototype.debugReset = function () {
    if (this.dom.debugLog) {
      this.dom.debugLog.innerHTML = '';
    }
    this.debugPending = {};
    this.debugIterationLast = 0;
    this.debugIterationMax = 0;
    this.debugToolCount = 0;
    this.debugTotalMs = 0;
    this.debugErrorCount = 0;
    // loopNumber → {group, body, summary, count, ms, loop, tokens} for the
    // per-step <details> wrappers built lazily by getOrCreateStepGroup.
    this.debugStepGroups = new Map();
    // Run-total token usage accumulated from ai_provider_response events.
    // Cached + reasoning are provider-specific (Anthropic prompt caching,
    // OpenAI o-series); shown only when non-zero.
    this.debugTokenUsage = { input: 0, output: 0, total: 0, cached: 0, reasoning: 0 };
    this.updateDebugPanelSummary();
  };

  /**
   * Render a token count in compact form for tight contexts (step
   * summaries). Footer uses the locale-formatted full number instead.
   */
  function formatTokensCompact(n) {
    if (!n) return '0';
    if (n < 1000) return String(n);
    if (n < 10000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
    return Math.round(n / 1000).toLocaleString() + 'k';
  }

  /**
   * Find or build the per-step <details> group for `loop`. Tool entries get
   * appended into this group's body so a long agent run reads as a list of
   * collapsible step blocks rather than one flat stream.
   */
  Widget.prototype.getOrCreateStepGroup = function (loop) {
    const key = loop || 1;
    if (this.debugStepGroups.has(key)) {
      return this.debugStepGroups.get(key);
    }
    const group = document.createElement('details');
    group.className = 'dkan-aiq-debug-step';
    group.open = true;
    const sum = document.createElement('summary');
    sum.className = 'dkan-aiq-debug-step-summary';
    sum.textContent = 'Step ' + key;
    group.appendChild(sum);
    const body = document.createElement('div');
    body.className = 'dkan-aiq-debug-step-list';
    group.appendChild(body);
    // Keep the footer pinned to the bottom even when new step groups
    // arrive after it has already been rendered.
    const footer = this.dom.debugLog.querySelector('.dkan-aiq-debug-footer');
    if (footer) {
      this.dom.debugLog.insertBefore(group, footer);
    }
    else {
      this.dom.debugLog.appendChild(group);
    }
    const record = {
      group: group,
      body: body,
      summary: sum,
      count: 0,
      ms: 0,
      loop: key,
      tokens: { input: 0, output: 0, total: 0, cached: 0, reasoning: 0 },
    };
    this.debugStepGroups.set(key, record);
    return record;
  };

  /**
   * Refresh a step group's summary line with its running tool count,
   * cumulative duration, and token total: "Step 2 — 3 tools · 480ms · 1.8k tokens".
   * Token chip is suppressed when no provider response has been seen yet.
   */
  Widget.prototype.updateStepGroupSummary = function (record) {
    const meta = [record.count + ' tool' + (record.count !== 1 ? 's' : '')];
    if (record.ms > 0) {
      meta.push(record.ms.toLocaleString() + 'ms');
    }
    if (record.tokens && record.tokens.total > 0) {
      meta.push(formatTokensCompact(record.tokens.total) + ' tokens');
    }
    record.summary.textContent = 'Step ' + record.loop + ' — ' + meta.join(' · ');
  };

  /**
   * Accumulate token usage from an ai_provider_response event into both
   * the run-total and the originating step group, then refresh both
   * summary lines so the chips update live as the agent runs.
   */
  Widget.prototype.debugProviderResponse = function (ev) {
    if (!this.dom.debugLog || this.dom.debugPanel.hidden) {
      return;
    }
    const usage = (ev.response_data || {}).tokenUsage || {};
    const fields = ['input', 'output', 'total', 'cached', 'reasoning'];
    let any = false;
    fields.forEach((f) => {
      const n = parseInt(usage[f], 10);
      if (!isNaN(n) && n > 0) {
        this.debugTokenUsage[f] += n;
        any = true;
      }
    });
    if (!any) {
      return;
    }
    const stepRecord = this.getOrCreateStepGroup(ev.loop_count || 1);
    fields.forEach((f) => {
      const n = parseInt(usage[f], 10);
      if (!isNaN(n) && n > 0) {
        stepRecord.tokens[f] += n;
      }
    });
    this.updateStepGroupSummary(stepRecord);
    this.debugFooter();
  };

  /**
   * Reflect the run's error count in the outer panel summary so an admin
   * can see at a glance whether the latest turn had failed tool calls
   * without expanding the panel. Adds a `has-errors` class for styling.
   */
  Widget.prototype.updateDebugPanelSummary = function () {
    if (!this.dom.debugPanel) {
      return;
    }
    const sum = this.dom.debugPanel.querySelector('.dkan-aiq-debug-summary');
    if (!sum) {
      return;
    }
    const errs = this.debugErrorCount || 0;
    if (errs > 0) {
      sum.textContent = 'Agent diagnostics — ' + errs + ' error' + (errs !== 1 ? 's' : '');
      sum.classList.add('has-errors');
    }
    else {
      sum.textContent = 'Agent diagnostics';
      sum.classList.remove('has-errors');
    }
  };

  Widget.prototype.debugIteration = function (ev) {
    if (!this.dom.debugLog || this.dom.debugPanel.hidden) {
      return;
    }
    const loop = ev.loop_count || 0;
    if (loop && loop !== this.debugIterationLast) {
      this.debugIterationLast = loop;
      if (loop > this.debugIterationMax) {
        this.debugIterationMax = loop;
      }
      // Materialize the step group up front so it appears in order even
      // when no tool calls fire for an iteration (rare but possible).
      this.getOrCreateStepGroup(loop);
    }
  };

  Widget.prototype.debugToolStarted = function (ev) {
    if (!this.dom.debugLog || this.dom.debugPanel.hidden) {
      return;
    }
    const toolId = ev.tool_id || ('tool-' + this.debugToolCount);
    // Each entry is a <details> so successful calls collapse to a single
    // line — name + result chip + meta — and only errored calls auto-open
    // (see debugToolFinished).
    const entry = document.createElement('details');
    entry.className = 'dkan-aiq-debug-entry';

    const header = document.createElement('summary');
    header.className = 'dkan-aiq-debug-header';
    const nameEl = document.createElement('span');
    nameEl.className = 'dkan-aiq-debug-name';
    nameEl.textContent = ev.tool_name || '';
    // Inline result chip — populated by debugToolFinished. Sits between
    // name and meta so the row reads "tool_name → 25 rows  step 1 · 234ms".
    const resultEl = document.createElement('span');
    resultEl.className = 'dkan-aiq-debug-result-inline';
    const metaEl = document.createElement('span');
    metaEl.className = 'dkan-aiq-debug-meta';
    // Filled in on debugToolFinished with the duration. Step number is
    // implied by the surrounding "Step N" group, so it's not repeated here.
    metaEl.textContent = '';
    header.appendChild(nameEl);
    header.appendChild(resultEl);
    header.appendChild(metaEl);
    entry.appendChild(header);

    const formatted = formatJsonString(ev.tool_input);
    const pre = document.createElement('pre');
    pre.className = 'dkan-aiq-debug-args';
    pre.textContent = formatted || '(no input args)';
    if (!formatted) {
      pre.classList.add('dkan-aiq-debug-args-empty');
    }
    entry.appendChild(pre);

    // Drop the entry into its step group rather than the panel root, so
    // multi-step runs render as collapsible Step N blocks. When no
    // agent_iteration event has fired yet (replay or simple runs), this
    // lazily creates a "Step 1" group.
    const stepRecord = this.getOrCreateStepGroup(this.debugIterationLast || 1);
    stepRecord.body.appendChild(entry);
    this.debugPending[toolId] = {
      entry: entry,
      meta: metaEl,
      result: resultEl,
      startedAt: typeof ev.time === 'number' ? ev.time : null,
      step: stepRecord,
    };
  };

  Widget.prototype.debugToolFinished = function (ev) {
    if (!this.dom.debugLog || this.dom.debugPanel.hidden) {
      return;
    }
    const toolId = ev.tool_id || '';
    const pending = this.debugPending[toolId];
    if (pending) {
      delete this.debugPending[toolId];
    }
    const entry = pending ? pending.entry : null;
    let durationMs = null;
    if (pending && pending.startedAt != null && typeof ev.time === 'number') {
      durationMs = Math.max(0, Math.round((ev.time - pending.startedAt) * 1000));
    }

    if (entry && pending && pending.meta && durationMs != null) {
      pending.meta.textContent = durationMs + 'ms';
    }

    // Result summary chip — lives in the entry's <summary> so successful
    // calls communicate "→ N rows" without having to expand. Errors get
    // both the chip and the entry auto-opened so the failure is visible.
    const summary = formatToolResultSummary(ev.tool_name, ev.tool_results);
    const isError = summary.startsWith('→ Error');
    if (entry && pending && pending.result) {
      pending.result.textContent = summary || '';
      if (isError) {
        pending.result.classList.add('dkan-aiq-debug-result-error');
      }
    }
    if (entry && isError) {
      entry.classList.add('dkan-aiq-debug-error');
      entry.open = true;
      this.debugErrorCount = (this.debugErrorCount || 0) + 1;
      this.updateDebugPanelSummary();
    }

    this.debugToolCount++;
    if (durationMs != null) {
      this.debugTotalMs += durationMs;
    }
    if (pending && pending.step) {
      pending.step.count++;
      if (durationMs != null) {
        pending.step.ms += durationMs;
      }
      this.updateStepGroupSummary(pending.step);
    }
  };

  Widget.prototype.debugFooter = function () {
    if (!this.dom.debugLog || this.dom.debugPanel.hidden) {
      return;
    }
    const tu = this.debugTokenUsage || {};
    const hasTokens = (tu.input || 0) + (tu.output || 0) + (tu.total || 0) > 0;
    if (this.debugToolCount === 0 && !hasTokens) {
      return;
    }
    const existing = this.dom.debugLog.querySelector('.dkan-aiq-debug-footer');
    if (existing) {
      existing.remove();
    }
    const footer = document.createElement('div');
    footer.className = 'dkan-aiq-debug-footer';

    const totals = document.createElement('span');
    totals.className = 'dkan-aiq-debug-footer-totals';
    const parts = [];
    if (this.debugToolCount > 0) {
      parts.push(this.debugToolCount + ' tool call' + (this.debugToolCount !== 1 ? 's' : ''));
    }
    if (this.debugIterationMax > 0) {
      parts.push(this.debugIterationMax + ' step' + (this.debugIterationMax !== 1 ? 's' : ''));
    }
    if (this.debugTotalMs > 0) {
      parts.push(this.debugTotalMs.toLocaleString() + 'ms');
    }
    if (this.debugErrorCount > 0) {
      parts.push(this.debugErrorCount + ' error' + (this.debugErrorCount !== 1 ? 's' : ''));
    }
    if (hasTokens) {
      const tokenParts = [];
      if (tu.input > 0) tokenParts.push(tu.input.toLocaleString() + ' in');
      if (tu.output > 0) tokenParts.push(tu.output.toLocaleString() + ' out');
      if (tu.total > 0) tokenParts.push(tu.total.toLocaleString() + ' total');
      if (tu.cached > 0) tokenParts.push(tu.cached.toLocaleString() + ' cached');
      if (tu.reasoning > 0) tokenParts.push(tu.reasoning.toLocaleString() + ' reasoning');
      parts.push(tokenParts.join(' · ') + ' tokens');
    }
    totals.textContent = parts.join(' · ');
    footer.appendChild(totals);

    // "Copy diagnostics" button — serializes the rendered log to plain text
    // for pasting into bug reports. Synthesizes from this.debugLog DOM so the
    // copy reflects exactly what the operator is looking at.
    const copyBtn = document.createElement('button');
    copyBtn.type = 'button';
    copyBtn.className = 'dkan-aiq-debug-copy';
    copyBtn.textContent = 'Copy diagnostics';
    const widget = this;
    copyBtn.addEventListener('click', function () {
      const text = widget.serializeDebugLog();
      const restore = function () {
        copyBtn.textContent = 'Copy diagnostics';
        copyBtn.disabled = false;
      };
      const flash = function (msg) {
        copyBtn.textContent = msg;
        copyBtn.disabled = true;
        setTimeout(restore, 1500);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(
          function () { flash('Copied!'); },
          function () { flash('Copy failed'); }
        );
      }
      else {
        flash('Clipboard unavailable');
      }
    });
    footer.appendChild(copyBtn);

    this.dom.debugLog.appendChild(footer);
  };

  /**
   * Build a plain-text dump of the diagnostics log for the clipboard.
   * Reads off the rendered DOM (rather than re-walking events) so the
   * output reflects exactly what the operator is currently viewing,
   * including any iteration separators and the totals footer.
   */
  Widget.prototype.serializeDebugLog = function () {
    const lines = [];
    const errs = this.debugErrorCount || 0;
    lines.push('Agent diagnostics');
    lines.push('=================');
    const totals = [
      this.debugToolCount + ' tool call' + (this.debugToolCount !== 1 ? 's' : ''),
      this.debugIterationMax + ' step' + (this.debugIterationMax !== 1 ? 's' : ''),
      this.debugTotalMs.toLocaleString() + 'ms total',
    ];
    if (errs > 0) {
      totals.push(errs + ' error' + (errs !== 1 ? 's' : ''));
    }
    lines.push(totals.join(' · '));
    const tu = this.debugTokenUsage || {};
    if ((tu.input || 0) + (tu.output || 0) + (tu.total || 0) > 0) {
      const tParts = [];
      if (tu.input > 0) tParts.push(tu.input.toLocaleString() + ' in');
      if (tu.output > 0) tParts.push(tu.output.toLocaleString() + ' out');
      if (tu.total > 0) tParts.push(tu.total.toLocaleString() + ' total');
      if (tu.cached > 0) tParts.push(tu.cached.toLocaleString() + ' cached');
      if (tu.reasoning > 0) tParts.push(tu.reasoning.toLocaleString() + ' reasoning');
      lines.push('Tokens: ' + tParts.join(' · '));
    }
    lines.push('');

    let idx = 0;
    const serializeEntry = function (node) {
      idx++;
      const name = (node.querySelector('.dkan-aiq-debug-name') || {}).textContent || '';
      const meta = (node.querySelector('.dkan-aiq-debug-meta') || {}).textContent || '';
      const result = (node.querySelector('.dkan-aiq-debug-result-inline') || {}).textContent || '';
      const isError = node.classList.contains('dkan-aiq-debug-error');
      const prefix = isError ? '[!]' : '[' + idx + ']';
      const headerParts = [prefix, name];
      if (meta) {
        headerParts.push('(' + meta + ')');
      }
      if (result) {
        headerParts.push(result);
      }
      lines.push(headerParts.join(' '));
      const args = node.querySelector('.dkan-aiq-debug-args');
      if (args) {
        const argsText = args.textContent || '';
        const indented = argsText.split('\n').map(function (l) { return '    ' + l; }).join('\n');
        lines.push('    args:');
        lines.push(indented);
      }
      lines.push('');
    };

    const stepGroups = this.dom.debugLog.querySelectorAll(':scope > .dkan-aiq-debug-step');
    if (stepGroups.length) {
      stepGroups.forEach(function (group) {
        const stepSummary = (group.querySelector(':scope > .dkan-aiq-debug-step-summary') || {}).textContent || '';
        if (stepSummary) {
          lines.push('--- ' + stepSummary + ' ---');
          lines.push('');
        }
        const entries = group.querySelectorAll(':scope > .dkan-aiq-debug-step-list > .dkan-aiq-debug-entry');
        entries.forEach(serializeEntry);
      });
    }
    else {
      // Fallback: flat children (covers any path where entries land outside
      // a step group, e.g. legacy state during a transition).
      const children = this.dom.debugLog.children;
      for (let i = 0; i < children.length; i++) {
        const node = children[i];
        if (node.classList.contains('dkan-aiq-debug-entry')) {
          serializeEntry(node);
        }
      }
    }
    return lines.join('\n').replace(/\n+$/, '\n');
  };

  /**
   * Parse-then-restringify so JSON-string events render as readable JSON
   * instead of an escape-soup blob.
   */
  function formatJsonString(value) {
    if (value == null || value === '') {
      return '';
    }
    let parsed = value;
    if (typeof value === 'string') {
      try {
        parsed = JSON.parse(value);
      }
      catch (e) {
        return value;
      }
    }
    parsed = unwrapDoubleEncodedStrings(parsed);
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
      // Drop empty-string entries to keep the panel readable.
      const filtered = {};
      Object.keys(parsed).forEach((k) => {
        if (parsed[k] !== '' && parsed[k] !== null) {
          filtered[k] = parsed[k];
        }
      });
      if (Object.keys(filtered).length === 0) {
        return '';
      }
      return JSON.stringify(filtered, null, 2);
    }
    return JSON.stringify(parsed, null, 2);
  }

  /**
   * Some upstream tool-arg serializations double-encode string values, so a
   * keyword like `foreclosure` arrives as the literal string `"foreclosure"`
   * (with quotes). Walk the object once and unwrap any string value that
   * itself parses as a JSON-encoded string.
   */
  function unwrapDoubleEncodedStrings(value) {
    if (typeof value === 'string') {
      if (value.length >= 2 && value.charAt(0) === '"' && value.charAt(value.length - 1) === '"') {
        try {
          const inner = JSON.parse(value);
          if (typeof inner === 'string') {
            return inner;
          }
        }
        catch (e) {
          // not JSON, leave alone
        }
      }
      return value;
    }
    if (Array.isArray(value)) {
      return value.map(unwrapDoubleEncodedStrings);
    }
    if (value && typeof value === 'object') {
      const out = {};
      Object.keys(value).forEach((k) => {
        out[k] = unwrapDoubleEncodedStrings(value[k]);
      });
      return out;
    }
    return value;
  }

  /**
   * Best-effort one-line summary of a tool's parsed JSON output.
   *
   * Renders "→ N of M rows" / "→ K columns: …" derived from the live
   * tool_results JSON. Returns '' when nothing useful can be inferred.
   */
  function formatToolResultSummary(name, raw) {
    if (raw == null || raw === '') {
      return '';
    }
    let parsed = raw;
    if (typeof raw === 'string') {
      try {
        parsed = JSON.parse(raw);
      }
      catch (e) {
        return '';
      }
    }
    if (!parsed || typeof parsed !== 'object') {
      return '';
    }
    if (parsed.error) {
      return '→ Error: ' + String(parsed.error);
    }
    if ((name === 'query_datastore' || name === 'query_datastore_join' || name === 'query_datastore_raw') && Array.isArray(parsed.results)) {
      const got = parsed.results.length;
      const total = parsed.total_rows != null
        ? parsed.total_rows
        : (parsed.count != null ? parsed.count : got);
      return '→ ' + got.toLocaleString() + ' of ' + total.toLocaleString() + ' rows';
    }
    if (name === 'get_datastore_schema') {
      const cols = Array.isArray(parsed.schema) ? parsed.schema : (Array.isArray(parsed.columns) ? parsed.columns : (Array.isArray(parsed) ? parsed : null));
      if (cols) {
        const names = cols.map((c) => (c && typeof c === 'object' ? (c.name || c.field || '?') : String(c))).slice(0, 5);
        return '→ ' + cols.length + ' columns' + (names.length ? ': ' + names.join(', ') + (cols.length > 5 ? ', …' : '') : '');
      }
    }
    if (name === 'get_datastore_stats') {
      const rows = parsed.row_count || parsed.rows || parsed.total_rows || 0;
      // parsed.columns may be a number, an array of names, or an array of
      // descriptor objects ({name, stats, …}). Normalize all three to a count
      // plus a sample of names — never string-coerce the objects directly.
      let colCount = 0;
      let colNames = [];
      if (typeof parsed.column_count === 'number') {
        colCount = parsed.column_count;
      }
      if (Array.isArray(parsed.columns)) {
        colCount = colCount || parsed.columns.length;
        colNames = parsed.columns
          .map((c) => (c && typeof c === 'object' ? (c.name || c.field || '') : String(c)))
          .filter((n) => n)
          .slice(0, 5);
      }
      else if (typeof parsed.columns === 'number') {
        colCount = colCount || parsed.columns;
      }
      if (rows || colCount) {
        let out = '→ ' + Number(rows).toLocaleString() + ' rows, ' + colCount + ' columns';
        if (colNames.length) {
          out += ': ' + colNames.join(', ') + (colCount > colNames.length ? ', …' : '');
        }
        return out;
      }
    }
    if (name === 'list_distributions') {
      const list = Array.isArray(parsed) ? parsed : (Array.isArray(parsed.distributions) ? parsed.distributions : null);
      if (list) {
        return '→ ' + list.length + ' distribution' + (list.length !== 1 ? 's' : '');
      }
    }
    if (name === 'list_datasets' || name === 'search_datasets') {
      const list = Array.isArray(parsed) ? parsed : (Array.isArray(parsed.results) ? parsed.results : (Array.isArray(parsed.datasets) ? parsed.datasets : null));
      if (list) {
        const total = parsed.total != null ? parsed.total : list.length;
        return '→ ' + list.length + ' of ' + total + (name === 'list_datasets' ? ' datasets' : ' results');
      }
    }
    if (name === 'search_columns') {
      const matches = Array.isArray(parsed.matches) ? parsed.matches.length : (parsed.total_matches || 0);
      const searched = parsed.resources_searched || 0;
      if (matches || searched) {
        return '→ ' + matches + ' match' + (matches !== 1 ? 'es' : '') + (searched ? ' across ' + searched + ' resources' : '');
      }
    }
    if (name === 'find_dataset_resources') {
      const dists = Array.isArray(parsed.distributions) ? parsed.distributions.length : (parsed.distribution_count || 0);
      const title = parsed.title || parsed.dataset_title || '';
      if (title || dists) {
        return '→ ' + (title || 'dataset') + ' (' + dists + ' distribution' + (dists !== 1 ? 's' : '') + ')';
      }
    }
    if (name === 'create_chart') {
      return '→ chart rendered';
    }
    if (name === 'sample_rows') {
      const got = parsed.row_count != null ? parsed.row_count : (Array.isArray(parsed.rows) ? parsed.rows.length : null);
      if (got != null) {
        return '→ ' + got + ' row' + (got !== 1 ? 's' : '');
      }
    }
    if (name === 'distinct_values') {
      const n = parsed.value_count != null
        ? parsed.value_count
        : (Array.isArray(parsed.values) ? parsed.values.length : null);
      if (n != null) {
        return '→ ' + n + ' distinct value' + (n !== 1 ? 's' : '') + (parsed.truncated ? ' (truncated)' : '');
      }
    }
    if (name === 'compute_stats') {
      const ops = Array.isArray(parsed.results) ? parsed.results.length : 0;
      const rows = parsed.row_count != null ? parsed.row_count : null;
      if (ops || rows != null) {
        return '→ ' + ops + ' op' + (ops !== 1 ? 's' : '') + (rows != null ? ' over ' + rows.toLocaleString() + ' rows' : '');
      }
    }
    if (name === 'get_data_dictionary') {
      // The metastore returns either a single dictionary or a `dictionaries`
      // array; in either case, count the field rows so the summary mirrors
      // get_datastore_schema's "N columns" style.
      const dicts = Array.isArray(parsed.dictionaries) ? parsed.dictionaries : (parsed.dictionary ? [parsed.dictionary] : null);
      if (dicts) {
        const fieldCount = dicts.reduce((acc, d) => acc + (Array.isArray(d.fields) ? d.fields.length : 0), 0);
        return '→ ' + dicts.length + ' dictionary' + (dicts.length !== 1 ? 'ies' : '') + (fieldCount ? ', ' + fieldCount + ' fields' : '');
      }
    }
    if (name === 'refuse') {
      // The refusal artifact already renders the visible card; the debug panel
      // only needs a one-line acknowledgement so the tool entry isn't blank.
      return '→ refused (' + (parsed.reason_category || 'other') + ')';
    }
    // Fallbacks.
    if (Array.isArray(parsed)) {
      return '→ ' + parsed.length + ' item' + (parsed.length !== 1 ? 's' : '');
    }
    if (Array.isArray(parsed.results)) {
      return '→ ' + parsed.results.length + ' result' + (parsed.results.length !== 1 ? 's' : '');
    }
    return '';
  }

  Widget.prototype.scrollToBottom = function () {
    this.dom.thread.scrollTop = this.dom.thread.scrollHeight;
  };
})(Drupal, drupalSettings, once);
