/**
 * @file
 * Phase 3 test page: conversation sidebar + multi-turn flow.
 *
 * - Loads the user's conversations on attach.
 * - Selecting a conversation loads its messages (replayed in the answer panel).
 * - Submitting with a selected conversation appends a turn (server-side).
 * - "+ New" clears selection and starts a fresh conversation on next submit.
 * - Pin / delete buttons live on each sidebar item.
 *
 * Charts use Vega-Embed loaded from CDN. Network access required.
 */
(function (Drupal, once) {
  'use strict';

  const VEGA_EMBED_CDN = 'https://cdn.jsdelivr.net/npm/vega-embed@6';

  Drupal.behaviors.dkanAiqTestPage = {
    attach: function (context) {
      ensureVegaEmbed();
      once('dkan-aiq-test', '[data-dkan-aiq-test]', context).forEach(function (root) {
        const ui = {
          form: root.querySelector('[data-form]'),
          eventsEl: root.querySelector('[data-events]'),
          answerEl: root.querySelector('[data-answer]'),
          tablesEl: root.querySelector('[data-tables]'),
          chartsEl: root.querySelector('[data-charts]'),
          convList: root.querySelector('[data-conv-list]'),
          newBtn: root.querySelector('[data-new-btn]'),
          activeLabel: root.querySelector('[data-active-conv]'),
          convIdInput: root.querySelector('[data-conv-id]'),
        };

        ui.newBtn.addEventListener('click', function () {
          setActiveConversation(ui, null, null);
          clearOutputs(ui);
        });

        ui.form.addEventListener('submit', function (evt) {
          evt.preventDefault();
          submitTurn(ui);
        });

        refreshConversations(ui);
      });
    },
  };

  function refreshConversations(ui) {
    fetch('/api/dkan-ai-query/conversations', {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (list) {
        renderSidebar(ui, list);
      })
      .catch(function () {});
  }

  function renderSidebar(ui, list) {
    ui.convList.innerHTML = '';
    list.forEach(function (conv) {
      const li = document.createElement('li');
      li.className = 'dkan-aiq-test__conv-item' + (conv.pinned ? ' is-pinned' : '');
      const title = document.createElement('span');
      title.className = 'dkan-aiq-test__conv-title';
      title.textContent = (conv.pinned ? '📌 ' : '') + conv.title;
      title.title = 'Load conversation #' + conv.id;
      title.addEventListener('click', function () { loadConversation(ui, conv); });

      const actions = document.createElement('span');
      actions.className = 'dkan-aiq-test__conv-actions';

      const pinBtn = document.createElement('button');
      pinBtn.type = 'button';
      pinBtn.textContent = conv.pinned ? 'Unpin' : 'Pin';
      pinBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        togglePin(ui, conv.id);
      });

      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.textContent = 'Delete';
      delBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        deleteConversation(ui, conv.id);
      });

      actions.appendChild(pinBtn);
      actions.appendChild(delBtn);
      li.appendChild(title);
      li.appendChild(actions);
      ui.convList.appendChild(li);
    });
  }

  function loadConversation(ui, conv) {
    fetch('/api/dkan-ai-query/conversations/' + conv.id, {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (full) {
        clearOutputs(ui);
        setActiveConversation(ui, full.id, full.title);
        // Replay messages into the answer panel as a flat transcript.
        ui.answerEl.textContent = (full.messages || []).map(function (m) {
          return '[' + m.role + ']\n' + m.content;
        }).join('\n\n---\n\n');
        // Replay artifacts attached to assistant turns.
        (full.messages || []).forEach(function (m) {
          (m.artifacts || []).forEach(function (a, idx) {
            if (a.type === 'data') {
              renderTable(a, idx, ui.tablesEl);
            }
            else if (a.type === 'chart') {
              renderChart(a, idx, ui.chartsEl);
            }
          });
        });
      });
  }

  function togglePin(ui, id) {
    fetch('/api/dkan-ai-query/conversations/' + id + '/pin', {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function () { refreshConversations(ui); });
  }

  function deleteConversation(ui, id) {
    if (!window.confirm('Delete this conversation?')) {
      return;
    }
    fetch('/api/dkan-ai-query/conversations/' + id, {
      method: 'DELETE',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function () {
        if (parseInt(ui.convIdInput.value, 10) === id) {
          setActiveConversation(ui, null, null);
          clearOutputs(ui);
        }
        refreshConversations(ui);
      });
  }

  function setActiveConversation(ui, id, title) {
    ui.convIdInput.value = id || '';
    ui.activeLabel.textContent = id
      ? ('continuing #' + id + ' — ' + (title || ''))
      : '(new conversation)';
  }

  function clearOutputs(ui) {
    ui.eventsEl.innerHTML = '';
    ui.tablesEl.innerHTML = '';
    ui.chartsEl.innerHTML = '';
    ui.answerEl.textContent = '(pending)';
  }

  function submitTurn(ui) {
    clearOutputs(ui);
    ui.answerEl.textContent = '(running…)';

    const data = new FormData(ui.form);
    const question = (data.get('question') || '').toString().trim();
    const resourceId = (data.get('resource_id') || '').toString().trim();
    const model = (data.get('model') || '').toString().trim();
    const conversationId = parseInt(ui.convIdInput.value, 10) || 0;
    if (!question) {
      return;
    }

    const threadId = 'aiq-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
    const seen = { events: 0, artifacts: 0 };

    const pollHandle = setInterval(function () {
      pollOnce(threadId, ui.eventsEl, ui.tablesEl, ui.chartsEl, seen);
    }, 500);

    const body = JSON.stringify({
      question: question,
      thread_id: threadId,
      resource_id: resourceId,
      model: model,
      conversation_id: conversationId || null,
    });

    fetch('/api/dkan-ai-query/start', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: body,
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json().then(function (j) {
          return { ok: r.ok, body: j };
        });
      })
      .then(function (resp) {
        clearInterval(pollHandle);
        pollOnce(threadId, ui.eventsEl, ui.tablesEl, ui.chartsEl, seen);
        if (resp.ok) {
          ui.answerEl.textContent = resp.body.answer || '(empty)';
          appendMeta(ui.eventsEl, 'agent_finished', resp.body);
          if (resp.body.conversation_id) {
            setActiveConversation(ui, resp.body.conversation_id, question.slice(0, 60));
            refreshConversations(ui);
          }
        }
        else {
          ui.answerEl.textContent = 'ERROR: ' + (resp.body.error || JSON.stringify(resp.body));
        }
      })
      .catch(function (err) {
        clearInterval(pollHandle);
        ui.answerEl.textContent = 'ERROR: ' + err.message;
      });
  }

  function pollOnce(threadId, eventsEl, tablesEl, chartsEl, seen) {
    fetch('/api/dkan-ai-query/poll/' + encodeURIComponent(threadId), {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        const events = json.events || [];
        for (let i = seen.events; i < events.length; i++) {
          appendEvent(eventsEl, events[i], i);
        }
        seen.events = events.length;

        const artifacts = json.artifacts || [];
        for (let i = seen.artifacts; i < artifacts.length; i++) {
          renderArtifact(artifacts[i], i, tablesEl, chartsEl);
        }
        seen.artifacts = artifacts.length;
      })
      .catch(function () {});
  }

  function appendEvent(listEl, item, idx) {
    const li = document.createElement('li');
    li.className = 'dkan-aiq-test__event';
    const type = item.type || item.event_type || '(unknown)';
    li.innerHTML =
      '<span class="dkan-aiq-test__event-idx">#' + idx + '</span> ' +
      '<strong>' + escapeHtml(type) + '</strong> ' +
      '<code>' + escapeHtml(JSON.stringify(item).slice(0, 800)) + '</code>';
    listEl.appendChild(li);
  }

  function appendMeta(listEl, label, payload) {
    const li = document.createElement('li');
    li.className = 'dkan-aiq-test__event dkan-aiq-test__event--meta';
    li.innerHTML =
      '<strong>' + escapeHtml(label) + '</strong> ' +
      '<code>' + escapeHtml(JSON.stringify(payload).slice(0, 800)) + '</code>';
    listEl.appendChild(li);
  }

  function renderArtifact(artifact, idx, tablesEl, chartsEl) {
    if (artifact.type === 'data') {
      renderTable(artifact, idx, tablesEl);
    }
    else if (artifact.type === 'chart') {
      renderChart(artifact, idx, chartsEl);
    }
  }

  function renderTable(artifact, idx, tablesEl) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-test__table';
    const rows = artifact.rows || [];
    const heading = document.createElement('h4');
    heading.textContent = '#' + idx + ' ' + (artifact.tool || 'data') + ' — ' + rows.length + ' rows';
    wrap.appendChild(heading);

    if (rows.length === 0) {
      const p = document.createElement('p');
      p.textContent = '(no rows)';
      wrap.appendChild(p);
      tablesEl.appendChild(wrap);
      return;
    }

    const cols = Object.keys(rows[0] || {});
    const table = document.createElement('table');
    const thead = document.createElement('thead');
    const trh = document.createElement('tr');
    cols.forEach(function (c) {
      const th = document.createElement('th');
      th.textContent = c;
      trh.appendChild(th);
    });
    thead.appendChild(trh);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    rows.slice(0, 50).forEach(function (row) {
      const tr = document.createElement('tr');
      cols.forEach(function (c) {
        const td = document.createElement('td');
        const val = row[c];
        td.textContent = val === null || val === undefined ? '' : String(val);
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    wrap.appendChild(table);
    if (rows.length > 50) {
      const note = document.createElement('p');
      note.className = 'dkan-aiq-test__table-note';
      note.textContent = '(showing first 50 of ' + rows.length + ' rows)';
      wrap.appendChild(note);
    }
    tablesEl.appendChild(wrap);
  }

  function renderChart(artifact, idx, chartsEl) {
    const wrap = document.createElement('div');
    wrap.className = 'dkan-aiq-test__chart';
    const heading = document.createElement('h4');
    heading.textContent = '#' + idx + ' chart';
    wrap.appendChild(heading);
    const target = document.createElement('div');
    target.className = 'dkan-aiq-test__chart-target';
    wrap.appendChild(target);
    chartsEl.appendChild(wrap);

    waitForVegaEmbed().then(function (vegaEmbed) {
      vegaEmbed(target, artifact.spec, { actions: false }).catch(function (err) {
        target.textContent = 'Chart render failed: ' + err.message;
      });
    });
  }

  let vegaEmbedPromise = null;
  function ensureVegaEmbed() {
    if (vegaEmbedPromise || window.vegaEmbed) {
      return;
    }
    vegaEmbedPromise = new Promise(function (resolve, reject) {
      const s = document.createElement('script');
      s.src = VEGA_EMBED_CDN;
      s.onload = function () { resolve(window.vegaEmbed); };
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }
  function waitForVegaEmbed() {
    if (window.vegaEmbed) {
      return Promise.resolve(window.vegaEmbed);
    }
    if (!vegaEmbedPromise) {
      ensureVegaEmbed();
    }
    return vegaEmbedPromise;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
})(Drupal, once);
