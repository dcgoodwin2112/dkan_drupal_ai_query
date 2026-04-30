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
        (full.messages || []).forEach((m) => {
          if (m.role === 'user') {
            this.appendUserBubble(m.content);
          }
          else if (m.role === 'assistant') {
            const bubble = this.appendAssistantBubble();
            this.renderAssistantText(bubble, m.content);
            (m.artifacts || []).forEach((a) => this.renderArtifactInBubble(bubble, a));
          }
        });
        this.updateThreadHeader();
        this.refreshSidebar();
      });
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
  };

  Widget.prototype.renderTableInBubble = function (bubble, artifact) {
    const rows = artifact.rows || [];
    const provenance = artifact.provenance || null;
    if (rows.length === 0 && !provenance) {
      return;
    }
    const cols = rows.length ? Object.keys(rows[0] || {}) : [];
    const input = artifact.input || null;
    const toolName = artifact.tool || 'query_datastore';

    // The API endpoint takes a distribution UUID in its URL path
    // (the public-facing form), while the SQL panel needs the internal
    // {hash}__{version} resource id (the actual datastore table name).
    const apiPrimary = input ? (input.distribution_uuid || input.resolved_resource_id || input.resource_id || '') : '';
    const apiJoin = input ? (input.join_distribution_uuid || input.resolved_join_resource_id || input.join_resource_id || '') : '';
    const sqlPrimary = input ? (input.resolved_resource_id || input.resource_id || '') : '';
    const sqlJoin = input ? (input.resolved_join_resource_id || input.join_resource_id || '') : '';
    const apiText = input ? buildApiEquivalent(toolName, input, apiPrimary, apiJoin) : null;
    const sqlText = input ? buildSqlEquivalent(toolName, input, sqlPrimary, sqlJoin) : null;

    const container = document.createElement('div');
    container.className = 'dkan-aiq-table-container';
    bubble.appendChild(container);

    // Summary bar (always visible).
    const summary = document.createElement('div');
    summary.className = 'dkan-aiq-table-summary';

    const meta = document.createElement('span');
    meta.className = 'dkan-aiq-table-meta';
    let countText = rows.length + ' row' + (rows.length !== 1 ? 's' : '');
    if (artifact.count != null && artifact.count > rows.length) {
      countText += ' of ' + artifact.count + ' total';
    }
    meta.textContent = countText;
    summary.appendChild(meta);

    const actions = document.createElement('span');
    actions.className = 'dkan-aiq-table-actions';

    const s = this.settings || {};

    let toggleBtn = null;
    if (rows.length && s.showTableToggle !== false) {
      toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'dkan-aiq-table-toggle';
      toggleBtn.textContent = 'Show table';
      actions.appendChild(toggleBtn);
    }

    let apiBtn = null;
    if (apiText && s.showApiCall !== false) {
      apiBtn = document.createElement('button');
      apiBtn.type = 'button';
      apiBtn.className = 'dkan-aiq-api-btn';
      apiBtn.textContent = 'Show API call';
      actions.appendChild(apiBtn);
    }

    let sqlBtn = null;
    if (sqlText && s.showSql !== false) {
      sqlBtn = document.createElement('button');
      sqlBtn.type = 'button';
      sqlBtn.className = 'dkan-aiq-sql-btn';
      sqlBtn.textContent = 'Show SQL';
      actions.appendChild(sqlBtn);
    }

    let provBtn = null;
    if (provenance && s.showProvenance !== false) {
      provBtn = document.createElement('button');
      provBtn.type = 'button';
      provBtn.className = 'dkan-aiq-prov-btn';
      provBtn.textContent = 'Show provenance';
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

    // API call collapsible panel.
    if (apiBtn && apiText) {
      const apiWrap = document.createElement('div');
      apiWrap.className = 'dkan-aiq-api-wrapper';
      apiWrap.hidden = true;
      const apiPre = document.createElement('pre');
      apiPre.className = 'dkan-aiq-api-code';
      apiPre.textContent = apiText;
      apiWrap.appendChild(apiPre);
      if (s.showCopyButtons !== false) {
        const copyApi = document.createElement('button');
        copyApi.type = 'button';
        copyApi.className = 'dkan-aiq-api-copy';
        copyApi.textContent = 'Copy';
        copyApi.addEventListener('click', () => {
          navigator.clipboard.writeText(apiText).then(() => {
            copyApi.textContent = 'Copied!';
            setTimeout(() => { copyApi.textContent = 'Copy'; }, 1500);
          });
        });
        apiWrap.appendChild(copyApi);
      }
      container.appendChild(apiWrap);
      apiBtn.addEventListener('click', () => {
        const isHidden = apiWrap.hidden;
        apiWrap.hidden = !isHidden;
        apiBtn.textContent = isHidden ? 'Hide API call' : 'Show API call';
        this.scrollToBottom();
      });
    }

    // SQL collapsible panel.
    if (sqlBtn && sqlText) {
      const sqlWrap = document.createElement('div');
      sqlWrap.className = 'dkan-aiq-sql-wrapper';
      sqlWrap.hidden = true;
      const sqlPre = document.createElement('pre');
      sqlPre.className = 'dkan-aiq-sql-code';
      sqlPre.textContent = sqlText;
      sqlWrap.appendChild(sqlPre);
      if (s.showCopyButtons !== false) {
        const copySql = document.createElement('button');
        copySql.type = 'button';
        copySql.className = 'dkan-aiq-sql-copy';
        copySql.textContent = 'Copy';
        copySql.addEventListener('click', () => {
          navigator.clipboard.writeText(sqlText).then(() => {
            copySql.textContent = 'Copied!';
            setTimeout(() => { copySql.textContent = 'Copy'; }, 1500);
          });
        });
        sqlWrap.appendChild(copySql);
      }
      container.appendChild(sqlWrap);
      sqlBtn.addEventListener('click', () => {
        const isHidden = sqlWrap.hidden;
        sqlWrap.hidden = !isHidden;
        sqlBtn.textContent = isHidden ? 'Hide SQL' : 'Show SQL';
        this.scrollToBottom();
      });
    }

    // Provenance collapsible panel (Phase 5).
    if (provBtn && provenance) {
      const provWrap = document.createElement('div');
      provWrap.className = 'dkan-aiq-prov-wrapper';
      provWrap.hidden = true;
      renderProvenancePanel(provWrap, provenance);
      container.appendChild(provWrap);
      provBtn.addEventListener('click', () => {
        const isHidden = provWrap.hidden;
        provWrap.hidden = !isHidden;
        provBtn.textContent = isHidden ? 'Hide provenance' : 'Show provenance';
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
          td.textContent = v === null || v === undefined ? '' : String(v);
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
        toggleBtn.textContent = isHidden ? 'Hide table' : 'Show table';
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
  function renderProvenancePanel(wrap, prov) {
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
      addRow('Executed', prov.executed_at);
    }
    addRow('Tool', prov.tool || '(unknown)');
    if (prov.row_count != null) {
      let countText = String(prov.row_count) + ' returned';
      if (prov.total_rows != null && prov.total_rows !== prov.row_count) {
        countText += ' / ' + prov.total_rows + ' total';
      }
      addRow('Rows', countText);
    }

    const flags = prov.sanity_flags || null;
    if (flags) {
      const flagged = [];
      if (flags.zero_rows) flagged.push('zero_rows');
      if (flags.row_cap_hit) flagged.push('row_cap_hit');
      if (Array.isArray(flags.all_null_columns) && flags.all_null_columns.length) {
        flagged.push('all_null_columns: ' + flags.all_null_columns.join(', '));
      }
      if (flags.coverage_warning) {
        flagged.push('coverage_warning: ' + flags.coverage_warning);
      }
      if (flagged.length) {
        const ul = document.createElement('ul');
        ul.className = 'dkan-aiq-prov-flags';
        flagged.forEach((line) => {
          const li = document.createElement('li');
          li.textContent = line;
          ul.appendChild(li);
        });
        addRow('Sanity flags', ul);
      }
    }

    if (prov.query_summary) {
      const pre = document.createElement('pre');
      pre.className = 'dkan-aiq-prov-query';
      pre.textContent = JSON.stringify(prov.query_summary, null, 2);
      addRow('Query', pre);
    }

    wrap.appendChild(dl);
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
  };

  Widget.prototype.debugIteration = function (ev) {
    if (!this.dom.debugLog || this.dom.debugPanel.hidden) {
      return;
    }
    const loop = ev.loop_count || 0;
    if (loop && loop !== this.debugIterationLast) {
      // Match the original UX: a separator only between iterations, not
      // before the very first one.
      if (this.debugIterationLast > 0) {
        const sep = document.createElement('div');
        sep.className = 'dkan-aiq-debug-separator';
        sep.textContent = 'Step ' + loop + ' — Analyzing results';
        this.dom.debugLog.appendChild(sep);
      }
      this.debugIterationLast = loop;
      if (loop > this.debugIterationMax) {
        this.debugIterationMax = loop;
      }
    }
  };

  Widget.prototype.debugToolStarted = function (ev) {
    if (!this.dom.debugLog || this.dom.debugPanel.hidden) {
      return;
    }
    const toolId = ev.tool_id || ('tool-' + this.debugToolCount);
    const entry = document.createElement('div');
    entry.className = 'dkan-aiq-debug-entry';

    const header = document.createElement('div');
    header.className = 'dkan-aiq-debug-header';
    const nameEl = document.createElement('span');
    nameEl.className = 'dkan-aiq-debug-name';
    nameEl.textContent = ev.tool_name || '';
    const metaEl = document.createElement('span');
    metaEl.className = 'dkan-aiq-debug-meta';
    metaEl.textContent = this.debugIterationLast ? ('step ' + this.debugIterationLast) : '';
    header.appendChild(nameEl);
    header.appendChild(metaEl);
    entry.appendChild(header);

    const formatted = formatJsonString(ev.tool_input);
    if (formatted) {
      const pre = document.createElement('pre');
      pre.className = 'dkan-aiq-debug-args';
      pre.textContent = formatted;
      entry.appendChild(pre);
    }

    this.dom.debugLog.appendChild(entry);
    this.debugPending[toolId] = {
      entry: entry,
      meta: metaEl,
      startedAt: typeof ev.time === 'number' ? ev.time : null,
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

    if (entry && pending && pending.meta) {
      const stepText = this.debugIterationLast ? ('step ' + this.debugIterationLast) : '';
      const parts = [];
      if (stepText) {
        parts.push(stepText);
      }
      if (durationMs != null) {
        parts.push(durationMs + 'ms');
      }
      pending.meta.textContent = parts.join(' · ');
    }

    // Result summary line.
    const summary = formatToolResultSummary(ev.tool_name, ev.tool_results);
    const isError = summary.startsWith('→ Error');
    if (entry && summary) {
      const result = document.createElement('div');
      result.className = 'dkan-aiq-debug-result' + (isError ? ' dkan-aiq-debug-result-error' : '');
      result.textContent = summary;
      entry.appendChild(result);
    }
    if (entry && isError) {
      entry.classList.add('dkan-aiq-debug-error');
    }

    this.debugToolCount++;
    if (durationMs != null) {
      this.debugTotalMs += durationMs;
    }
  };

  Widget.prototype.debugFooter = function () {
    if (!this.dom.debugLog || this.dom.debugPanel.hidden || this.debugToolCount === 0) {
      return;
    }
    const existing = this.dom.debugLog.querySelector('.dkan-aiq-debug-footer');
    if (existing) {
      existing.remove();
    }
    const footer = document.createElement('div');
    footer.className = 'dkan-aiq-debug-footer';
    const parts = [
      this.debugToolCount + ' tool call' + (this.debugToolCount !== 1 ? 's' : ''),
      this.debugIterationMax + ' step' + (this.debugIterationMax !== 1 ? 's' : ''),
      this.debugTotalMs.toLocaleString() + 'ms total',
    ];
    footer.textContent = parts.join(' · ');
    this.dom.debugLog.appendChild(footer);
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
