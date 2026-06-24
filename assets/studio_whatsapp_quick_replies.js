(function () {
  'use strict';

  function $(id) {
    return document.getElementById(id);
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      }[char] || char;
    });
  }

  function readJson(id) {
    var node = $(id);
    if (!node) return [];
    try {
      var parsed = JSON.parse(node.textContent || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      return [];
    }
  }

  function getCsrfToken() {
    return document.querySelector('input[name="csrf_token"]')?.value || '';
  }

  function normalize(value) {
    return String(value || '').toLocaleLowerCase('pt-BR');
  }

  function injectStyles() {
    if ($('waQuickReplyStyles')) return;
    var style = document.createElement('style');
    style.id = 'waQuickReplyStyles';
    style.textContent = `
      .wa-quick-popover{position:fixed;z-index:9999;width:min(440px,calc(100vw - 24px));max-height:min(560px,78vh);display:grid;grid-template-rows:auto minmax(0,1fr) auto;border:1px solid rgba(15,23,42,.18);border-radius:12px;background:#fff;color:#0f172a;box-shadow:0 20px 70px rgba(15,23,42,.24);overflow:hidden}
      .wa-quick-popover.hidden{display:none}
      .wa-quick-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-bottom:1px solid rgba(148,163,184,.22);background:#f8fafc}
      .wa-quick-head strong{font-size:13px}
      .wa-quick-head span{font-size:12px;color:#64748b}
      .wa-quick-list{overflow:auto;padding:8px;display:grid;gap:6px}
      .wa-quick-item{width:100%;border:1px solid rgba(148,163,184,.20);border-radius:10px;background:#fff;padding:10px;text-align:left;display:grid;gap:5px;cursor:pointer}
      .wa-quick-item:hover,.wa-quick-item.active{border-color:#12b886;background:#ecfdf5}
      .wa-quick-item-top{display:flex;justify-content:space-between;gap:10px;align-items:center}
      .wa-quick-item strong{font-size:13px;line-height:1.25}
      .wa-quick-item code{font-size:12px;color:#047857;background:#d1fae5;border-radius:999px;padding:3px 7px}
      .wa-quick-item p{margin:0;color:#475569;font-size:12px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
      .wa-quick-empty{padding:18px;color:#64748b;font-size:13px;line-height:1.4}
      .wa-quick-manager{border-top:1px solid rgba(148,163,184,.22);padding:10px;background:#f8fafc;display:grid;gap:8px}
      .wa-quick-actions{display:flex;gap:8px;flex-wrap:wrap}
      .wa-quick-actions button{border:1px solid rgba(15,23,42,.14);background:#fff;border-radius:999px;padding:8px 10px;font-size:12px;font-weight:700;cursor:pointer}
      .wa-quick-actions button.primary{background:#12b886;border-color:#12b886;color:#052e25}
      .wa-quick-actions button.danger{color:#b91c1c}
      .wa-quick-form{display:none;gap:8px}
      .wa-quick-form.open{display:grid}
      .wa-quick-form input,.wa-quick-form textarea,.wa-quick-form select{width:100%;border:1px solid rgba(148,163,184,.36);border-radius:8px;padding:9px 10px;font:inherit;background:#fff;color:#0f172a;box-sizing:border-box}
      .wa-quick-form textarea{min-height:74px;resize:vertical}
      .wa-quick-row{display:grid;grid-template-columns:1fr 1fr;gap:8px}
      @media (max-width:640px){.wa-quick-popover{left:12px!important;right:12px!important;width:auto;bottom:78px!important;top:auto!important}.wa-quick-row{grid-template-columns:1fr}}
    `;
    document.head.appendChild(style);
  }

  function tokenInfo(textarea) {
    var value = textarea.value || '';
    var pos = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : value.length;
    var before = value.slice(0, pos);
    var match = before.match(/(^|\s)(\/[^\s]*)$/);
    if (!match) return null;
    var token = match[2] || '/';
    return {
      query: token.slice(1),
      start: pos - token.length,
      end: pos
    };
  }

  function replaceToken(textarea, info, body) {
    var value = textarea.value || '';
    var before = value.slice(0, info.start);
    var after = value.slice(info.end);
    var insert = String(body || '');
    var spacer = before && !/\s$/.test(before) ? ' ' : '';
    textarea.value = before + spacer + insert + after;
    var next = (before + spacer + insert).length;
    textarea.focus();
    try {
      textarea.setSelectionRange(next, next);
    } catch (ignore) {}
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function initQuickReplies(config) {
    var textarea = document.querySelector(config.textareaSelector);
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    injectStyles();

    var replies = readJson(config.dataId).filter(function (reply) {
      return reply && reply.is_active !== false;
    });
    var activeIndex = 0;
    var currentInfo = null;
    var editing = null;

    var popover = document.createElement('div');
    popover.className = 'wa-quick-popover hidden';
    popover.innerHTML = `
      <div class="wa-quick-head"><div><strong>Respostas rápidas</strong><span></span></div><button type="button" data-quick-close aria-label="Fechar">×</button></div>
      <div class="wa-quick-list"></div>
      <div class="wa-quick-manager">
        <div class="wa-quick-actions">
          <button type="button" class="primary" data-quick-new>Nova resposta</button>
          <button type="button" data-quick-edit>Editar selecionada</button>
          <button type="button" class="danger" data-quick-delete>Excluir</button>
        </div>
        <form class="wa-quick-form">
          <input name="title" placeholder="Título" required>
          <div class="wa-quick-row">
            <input name="shortcut" placeholder="/atalho">
            <input name="category" placeholder="Categoria" value="Atendimento">
          </div>
          <textarea name="body" placeholder="Texto da resposta rápida" required></textarea>
          <div class="wa-quick-actions">
            <button type="submit" class="primary">Salvar</button>
            <button type="button" data-quick-cancel>Cancelar</button>
          </div>
        </form>
      </div>
    `;
    document.body.appendChild(popover);

    var list = popover.querySelector('.wa-quick-list');
    var subtitle = popover.querySelector('.wa-quick-head span');
    var form = popover.querySelector('.wa-quick-form');

    function matches(query) {
      var needle = normalize(query);
      return replies.filter(function (reply) {
        var haystack = normalize([reply.title, reply.shortcut, reply.category, reply.body].join(' '));
        return needle === '' || haystack.indexOf(needle) !== -1;
      }).slice(0, 12);
    }

    function selectedReply() {
      var items = matches(currentInfo ? currentInfo.query : '');
      return items[Math.max(0, Math.min(activeIndex, items.length - 1))] || null;
    }

    function position() {
      var rect = textarea.getBoundingClientRect();
      popover.style.left = Math.max(12, Math.min(window.innerWidth - 452, rect.left)) + 'px';
      popover.style.top = Math.max(12, rect.top - 430) + 'px';
      if (rect.top < 460) {
        popover.style.top = Math.min(window.innerHeight - 580, rect.bottom + 8) + 'px';
      }
    }

    function render() {
      var query = currentInfo ? currentInfo.query : '';
      var items = matches(query);
      subtitle.textContent = query ? '/' + query : 'Digite / para filtrar';
      if (!items.length) {
        list.innerHTML = '<div class="wa-quick-empty">Nenhuma resposta encontrada. Crie uma resposta pessoal para este atendimento.</div>';
        return;
      }
      if (activeIndex >= items.length) activeIndex = 0;
      list.innerHTML = items.map(function (reply, index) {
        return `<button type="button" class="wa-quick-item ${index === activeIndex ? 'active' : ''}" data-quick-id="${reply.id}">
          <span class="wa-quick-item-top"><strong>${escapeHtml(reply.title)}</strong><code>${escapeHtml(reply.shortcut || '/')}</code></span>
          <p>${escapeHtml(reply.body)}</p>
        </button>`;
      }).join('');
    }

    function open() {
      currentInfo = tokenInfo(textarea);
      if (!currentInfo) {
        close();
        return;
      }
      position();
      render();
      popover.classList.remove('hidden');
    }

    function close() {
      popover.classList.add('hidden');
      currentInfo = null;
      editing = null;
      form.classList.remove('open');
    }

    function openForm(reply) {
      editing = reply || null;
      form.elements.title.value = reply ? reply.title || '' : '';
      form.elements.shortcut.value = reply ? reply.shortcut || '' : (currentInfo?.query ? '/' + currentInfo.query : '');
      form.elements.category.value = reply ? reply.category || 'Atendimento' : 'Atendimento';
      form.elements.body.value = reply ? reply.body || '' : '';
      form.classList.add('open');
      form.elements.title.focus();
    }

    function setReplies(next) {
      replies = Array.isArray(next) ? next.filter(function (reply) { return reply && reply.is_active !== false; }) : replies;
      var node = $(config.dataId);
      if (node) node.textContent = JSON.stringify(replies);
      render();
    }

    async function postAction(action, payload) {
      var body = new URLSearchParams(payload || {});
      body.set('csrf_token', getCsrfToken());
      body.set('action', action);
      var response = await fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body
      });
      var data = await response.json().catch(function () { return null; });
      if (!response.ok || !data || !data.ok) {
        throw new Error((data && data.error) || 'Nao foi possivel salvar a resposta rapida.');
      }
      if (Array.isArray(data.quick_replies)) {
        setReplies(data.quick_replies);
      }
      return data;
    }

    textarea.addEventListener('input', function () {
      if (tokenInfo(textarea)) {
        open();
      } else if (!form.classList.contains('open')) {
        close();
      }
    });

    textarea.addEventListener('keydown', function (event) {
      if (popover.classList.contains('hidden')) return;
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex++;
        render();
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex = Math.max(0, activeIndex - 1);
        render();
      } else if (event.key === 'Enter' || event.key === 'Tab') {
        var reply = selectedReply();
        if (reply && currentInfo && !form.classList.contains('open')) {
          event.preventDefault();
          replaceToken(textarea, currentInfo, reply.body);
          close();
        }
      } else if (event.key === 'Escape') {
        event.preventDefault();
        close();
      }
    });

    list.addEventListener('click', function (event) {
      var button = event.target.closest('[data-quick-id]');
      if (!button || !currentInfo) return;
      var reply = replies.find(function (item) { return String(item.id) === String(button.getAttribute('data-quick-id')); });
      if (!reply) return;
      replaceToken(textarea, currentInfo, reply.body);
      close();
    });

    popover.addEventListener('click', async function (event) {
      var target = event.target.closest('button');
      if (!target) return;
      if (target.hasAttribute('data-quick-close')) {
        close();
      } else if (target.hasAttribute('data-quick-new')) {
        openForm(null);
      } else if (target.hasAttribute('data-quick-edit')) {
        var reply = selectedReply();
        if (reply && reply.editable) openForm(reply);
      } else if (target.hasAttribute('data-quick-delete')) {
        var selected = selectedReply();
        if (!selected || !selected.editable || !confirm('Excluir esta resposta rapida?')) return;
        try {
          await postAction('delete_quick_reply', { id: String(selected.id) });
        } catch (error) {
          alert(error.message || 'Nao foi possivel excluir.');
        }
      } else if (target.hasAttribute('data-quick-cancel')) {
        form.classList.remove('open');
      }
    });

    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      try {
        var payload = {
          id: editing ? String(editing.id) : '',
          title: form.elements.title.value,
          shortcut: form.elements.shortcut.value,
          category: form.elements.category.value,
          body: form.elements.body.value,
          is_active: '1',
          scope: editing ? (editing.scope || 'personal') : 'personal'
        };
        await postAction('save_quick_reply', payload);
        form.classList.remove('open');
        editing = null;
      } catch (error) {
        alert(error.message || 'Nao foi possivel salvar.');
      }
    });

    document.addEventListener('click', function (event) {
      if (event.target === textarea || popover.contains(event.target)) return;
      close();
    });
    window.addEventListener('resize', function () {
      if (!popover.classList.contains('hidden')) position();
    });
  }

  initQuickReplies({
    textareaSelector: '#reply-message',
    dataId: 'workspaceQuickRepliesData'
  });
  initQuickReplies({
    textareaSelector: '#m2Message',
    dataId: 'm2QuickRepliesData'
  });
})();
