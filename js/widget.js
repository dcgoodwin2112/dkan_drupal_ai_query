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
    sample_rows: 'Sample of rows',
    distinct_values: 'Distinct values',
    search_columns: 'Column search',
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
    sample_rows: 'Returns a small random sample of rows from the resource so you can eyeball the data.',
    distinct_values: 'Lists the unique values that appear in one column of the resource.',
    search_columns: 'Searches column names (and optionally descriptions) across all dataset resources to find columns matching your keyword.',
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
    this.followUpPlaceholder = 'Ask a follow-up...';

    this.dom = {
      sidebar: root.querySelector('.dkan-aiq-sidebar'),
      sidebarList: root.querySelector('.dkan-aiq-sidebar-list'),
      sidebarSearch: root.querySelector('.dkan-aiq-sidebar-search-input'),
      sidebarFooter: root.querySelector('.dkan-aiq-sidebar-footer'),
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
          this.renderSidebar(this.cachedConversations);
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
          this.renderSidebar(this.cachedConversations);
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
    this.dom.sidebarSearch.addEventListener('input', () => {
      const term = this.dom.sidebarSearch.value.toLowerCase();
      this.dom.sidebarList.querySelectorAll('.dkan-aiq-sidebar-entry').forEach((item) => {
        const title = item.getAttribute('data-title') || '';
        item.style.display = title.toLowerCase().includes(term) ? '' : 'none';
      });
    });
  };

  Widget.prototype.refreshSidebar = function () {
    if (!this.historyEnabled) {
      return;
    }
    fetch('/api/dkan-ai-query/conversations', {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then((list) => { this.renderSidebar(list); })
      .catch(() => {});
  };

  Widget.prototype.renderSidebar = function (list) {
    this.cachedConversations = list || [];
    this.dom.sidebarList.innerHTML = '';
    if (!this.cachedConversations.length) {
      const empty = document.createElement('div');
      empty.className = 'dkan-aiq-sidebar-empty';
      empty.textContent = 'No conversations yet.';
      this.dom.sidebarList.appendChild(empty);
    }
    else {
      this.cachedConversations.forEach((conv) => {
        this.dom.sidebarList.appendChild(this.buildSidebarEntry(conv));
      });
    }
    if (this.dom.sidebarFooter) {
      const n = this.cachedConversations.length;
      this.dom.sidebarFooter.textContent = n + ' conversation' + (n !== 1 ? 's' : '');
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
        if (this.dom.sidebarFooter) {
          const n = this.cachedConversations.length;
          this.dom.sidebarFooter.textContent = n + ' conversation' + (n !== 1 ? 's' : '');
        }
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

    // API and SQL preview only apply to the two datastore-query tools — the
    // rest don't map to a public datastore endpoint and would render nonsense.
    const apiPrimary = (!isSimpleTool && input) ? (input.distribution_uuid || input.resolved_resource_id || input.resource_id || '') : '';
    const apiJoin = (!isSimpleTool && input) ? (input.join_distribution_uuid || input.resolved_join_resource_id || input.join_resource_id || '') : '';
    const sqlPrimary = (!isSimpleTool && input) ? (input.resolved_resource_id || input.resource_id || '') : '';
    const sqlJoin = (!isSimpleTool && input) ? (input.resolved_join_resource_id || input.join_resource_id || '') : '';
    const apiText = (!isSimpleTool && input) ? buildApiEquivalent(toolName, input, apiPrimary, apiJoin) : null;
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

  function buildApiEquivalent(toolName, input, resolvedResourceId, resolvedJoinId) {
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
        JSON.parse(input.expressions).forEach((expr) => { properties.push(expr); });
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

    const endpoint = isJoin
      ? 'POST /api/1/datastore/query'
      : 'POST /api/1/datastore/query/' + resourceId;
    return endpoint + '\n' + JSON.stringify(body, null, 2);
  }

  function buildSqlEquivalent(toolName, input, resolvedResourceId, resolvedJoinId) {
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

    const entry = buildAuxEntry(artifact);
    list.appendChild(entry);

    // Reflect the running count in the outer summary so the analyst sees
    // "Supporting data — 3 tool calls" without having to expand first.
    const count = list.children.length;
    outer.querySelector('.dkan-aiq-aux-tools-summary').textContent =
      'Supporting data — ' + count + ' tool call' + (count !== 1 ? 's' : '');

    this.scrollToBottom();
  };

  /**
   * Build a single per-tool entry: collapsed details whose summary shows
   * the friendly tool name + one-line headline; body shows the tool-
   * specific content + a Raw output disclosure.
   */
  function buildAuxEntry(artifact) {
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
    if ((name === 'query_datastore' || name === 'query_datastore_join') && Array.isArray(parsed.results)) {
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
