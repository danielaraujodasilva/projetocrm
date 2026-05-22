(function () {
  const modal = document.getElementById('homeDrilldownModal');
  const title = document.getElementById('homeDrilldownTitle');
  const summary = document.getElementById('homeDrilldownSummary');
  const body = document.getElementById('homeDrilldownBody');

  if (!modal || !title || !summary || !body) return;

  const esc = (value) =>
    String(value ?? '').replace(/[&<>"']/g, (char) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char] || char)
    );

  const money = (value) => {
    const numeric = Number(String(value ?? 0).replace(/[^\d,-]/g, '').replace(/\./g, '').replace(',', '.'));
    const safe = Number.isFinite(numeric) ? numeric : Number(value ?? 0) || 0;
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(safe);
  };

  const badge = (text, tone = 'neutral') => `<span class="drilldown-badge ${tone}">${esc(text)}</span>`;

  const card = (href, heading, metaHtml, detail, extraClass = '') =>
    `<a class="drilldown-card ${extraClass}"${href ? ` href="${esc(href)}"` : ''}>` +
    `<strong>${esc(heading)}</strong>` +
    `${metaHtml ? `<div class="meta-line">${metaHtml}</div>` : ''}` +
    `${detail ? `<div class="drilldown-card-more">${esc(detail)}</div>` : ''}` +
    `</a>`;

  const closeModal = () => modal.classList.add('hidden');
  const openModal = () => modal.classList.remove('hidden');

  function getAvailabilityRows(data, rangeKey, limitDays) {
    const ranges = data.ranges || {};
    const defaultRange = data.default_range || '7d';
    const range = ranges[rangeKey] || ranges[defaultRange] || Object.values(ranges)[0] || { label: '7 dias', items: [] };
    const items = Array.isArray(range.items) ? range.items.slice(0, Math.max(1, limitDays || range.items.length)) : [];
    return { range, items };
  }

  function renderAvailability(data) {
    const ranges = Object.entries(data.ranges || {});
    const defaultRange = data.default_range || '7d';
    const rangeLabels = {
      '3d': '3 dias',
      '7d': '7 dias',
      '15d': '15 dias',
      month: 'Este mês',
      next_month: 'Mês que vem',
      custom: 'Prazo livre',
    };

    const options = ranges
      .filter(([key]) => key !== 'custom')
      .map(([key, value]) => `<option value="${esc(key)}"${key === defaultRange ? ' selected' : ''}>${esc(value.label || rangeLabels[key] || key)}</option>`)
      .join('');

    const freeRange = ranges.find(([key]) => key === 'custom');
    const freeLimit = freeRange ? (freeRange[1]?.items?.length || 365) : 365;

    const renderRange = (rangeKey, customDays) => {
      const activeRangeKey = rangeKey === 'custom' ? 'custom' : rangeKey;
      const { range, items } = getAvailabilityRows(data, activeRangeKey, activeRangeKey === 'custom' ? customDays : undefined);
      const chosenLabel = activeRangeKey === 'custom'
        ? `Prazo livre: ${customDays} dias`
        : (range.label || rangeLabels[activeRangeKey] || activeRangeKey);

      const blocks = items.map((item) => {
        const freeSlots = Array.isArray(item.free_slots) ? item.free_slots : [];
        const bookedSlots = Array.isArray(item.booked) ? item.booked : [];
        const freeHtml = freeSlots.length
          ? `<div class="availability-slot-row">${freeSlots.map((slot) => `<button type="button" class="availability-block free" data-availability-date="${esc(item.date)}" data-availability-time="${esc(slot)}"><strong>${esc(slot)}</strong><span>Livre</span></button>`).join('')}</div>`
          : `<div class="availability-day-empty"><span>Sem horários livres neste dia.</span></div>`;
        const bookedHtml = bookedSlots.length
          ? `<div class="availability-slot-row occupied">${bookedSlots.map((slot) => `<button type="button" class="availability-block occupied" data-availability-date="${esc(item.date)}" data-availability-time="${esc(slot.time)}"><strong>${esc(slot.time)}</strong><span>${esc(slot.customer_name || 'Ocupado')}</span></button>`).join('')}</div>`
          : '';

        return `
          <section class="availability-day">
            <header class="availability-day-head">
              <div>
                <strong>${esc(item.label)}</strong>
                <div class="availability-day-meta">
                  <span>${esc(item.date)}</span>
                  <span>${esc(item.free || freeSlots.length)} livres · ${esc(bookedSlots.length)} ocupadas</span>
                </div>
              </div>
              ${item.allowed ? badge(`${freeSlots.length} vagas livres`, 'ok') : badge('Fora das regras', 'warn')}
            </header>
            <div class="availability-day-body">
              <div class="availability-day-section">
                <small>Horários livres</small>
                ${freeHtml}
              </div>
              ${bookedHtml ? `<div class="availability-day-section"><small>Horários ocupados</small>${bookedHtml}</div>` : ''}
            </div>
          </section>`;
      });

      const empty = `<div class="drilldown-empty"><strong>Nenhuma vaga livre encontrada</strong><div class="muted">Nesse prazo não apareceu nenhum horário livre dentro das regras do estúdio.</div></div>`;

      body.innerHTML =
        `<div class="availability-toolbar">` +
        `<label class="field"><span class="muted">Atalhos</span><select id="availabilityRangeSelect">${options}</select></label>` +
        `<label class="field availability-custom-field"><span class="muted">Prazo livre</span><input id="availabilityDaysInput" type="number" min="1" max="${freeLimit}" value="${Math.min(7, freeLimit)}"></label>` +
        `<button type="button" class="btn secondary" id="availabilityDaysApply">Aplicar</button>` +
        `<p class="muted availability-note">Mostrando ${esc(chosenLabel)}. Clique numa vaga livre para abrir a agenda daquele dia.</p>` +
        `</div>` +
        `<div class="availability-list">${blocks.length ? blocks.join('') : empty}</div>`;

      setTimeout(() => {
        const select = document.getElementById('availabilityRangeSelect');
        const input = document.getElementById('availabilityDaysInput');
        const apply = document.getElementById('availabilityDaysApply');
        if (select) {
          select.value = activeRangeKey === 'custom' ? defaultRange : activeRangeKey;
          select.addEventListener('change', function () {
            if (this.value === 'custom') {
              renderRange('custom', Number(input?.value || freeLimit));
              return;
            }
            renderRange(this.value);
          });
        }
        if (apply && input) {
          apply.addEventListener('click', () => renderRange('custom', Number(input.value || freeLimit)));
        }
      }, 0);
    };

    renderRange(defaultRange);
  }

  function renderScheduledMonth(data) {
    const items = Array.isArray(data.items) ? data.items : [];
    const filters = data.filters || {};
    const filterEntries = Object.entries(filters);

    const renderList = (periodLabel, rows) => {
      const totalCount = rows.length;
      const totalValue = rows.reduce((sum, item) => sum + (Number(item.value) || 0), 0);
      const list = rows.map((item) => {
        const href = item.id ? `index.php?page=studio_agenda&date=${encodeURIComponent(item.appointment_date || '')}&appointment_id=${encodeURIComponent(item.id)}#appointment-form` : '';
        const meta = [
          item.appointment_date ? badge(item.appointment_date) : '',
          item.start_time ? badge(item.start_time.slice(0, 5)) : '',
          item.status ? badge(item.status, item.status === 'confirmado' ? 'ok' : 'neutral') : '',
        ].filter(Boolean).join('');
        const detail = `${item.title || item.customer_name || 'Atendimento'} · ${money(item.value || 0)}`;
        return card(href, item.customer_name || item.title || 'Agendamento', meta, detail, 'compact');
      }).join('');

      body.innerHTML = `
        <div class="availability-toolbar">
          ${filterEntries.map(([key, label]) => `<button type="button" class="drilldown-chip ${key === 'month' ? '' : 'secondary'}" data-scheduled-filter="${esc(key)}">${esc(label)}</button>`).join('')}
          <div class="drilldown-toolbar-summary">
            <strong>${esc(totalCount)} agendamentos</strong>
            <span>${esc(money(totalValue))}</span>
            <small>${esc(periodLabel)}</small>
          </div>
        </div>
        <div class="drilldown-card-list">${list || '<div class="drilldown-empty"><strong>Nenhum agendamento encontrado</strong><div class="muted">Não há itens nesse período selecionado.</div></div>'}</div>`;

      setTimeout(() => {
        document.querySelectorAll('[data-scheduled-filter]').forEach((btn) => {
          btn.addEventListener('click', () => {
            const key = btn.getAttribute('data-scheduled-filter');
            if (!key) return;
            const nextItems = Array.isArray(data.rangeMap?.[key]) ? data.rangeMap[key] : rows;
            const nextLabel = filters[key] || periodLabel;
            renderList(nextLabel, nextItems);
          });
        });
      }, 0);
    };

    renderList(data.summary || 'Período selecionado', items);
  }

  function renderHumanReplies(data) {
    const total = Number(data.count || 0);
    const items = Array.isArray(data.items) ? data.items : [];
    const summaryHtml = `
      <div class="drilldown-panel-summary">
        <div class="drilldown-kpi"><strong>${esc(total)}</strong><span>Conversas sem confirmação</span><small>Este cartão ficou sem espelho confiável de leitura no WhatsApp, então ele mostra apenas o que o sistema sabe de forma segura.</small></div>
        <div class="drilldown-kpi highlight"><strong>${esc(items.length)}</strong><span>Conversas listadas</span><small>Somente itens com última atividade recente.</small></div>
      </div>`;

    const rows = items.map((item) => {
      const href = item.id ? `index.php?page=studio_whatsapp_conversation&id=${encodeURIComponent(item.id)}` : '';
      const meta = [
        item.last_message_at ? badge(item.last_message_at, 'neutral') : '',
        item.attendance_mode ? badge(item.attendance_mode === 'bot' ? 'bot' : 'human', item.attendance_mode === 'bot' ? 'ok' : 'neutral') : '',
      ].filter(Boolean).join('');
      return card(href, item.display_name || item.phone || 'Contato', meta, item.last_message_preview || 'Sem prévia recente.', 'compact');
    }).join('');

    body.innerHTML = `${summaryHtml}<div class="drilldown-card-list">${rows || '<div class="drilldown-empty"><strong>Sem itens confiáveis para exibir</strong><div class="muted">Esse card foi propositalmente simplificado para não mostrar informação falsa.</div></div>'}</div>`;
  }

  function renderFinance(data) {
    const items = Array.isArray(data.items) ? data.items : [];
    const revenue = items[0]?.value || 'R$ 0,00';
    const expenses = items[1]?.value || 'R$ 0,00';
    const balance = items[2]?.value || 'R$ 0,00';

    body.innerHTML = `
      <div class="drilldown-panel-grid">
        <div class="drilldown-panel-summary">
          <div class="drilldown-kpi">
            <strong>${esc(revenue)}</strong>
            <span>${esc(items[0]?.label || 'Agenda no mês')}</span>
            <small>Total previsto do período selecionado.</small>
          </div>
          <div class="drilldown-kpi">
            <strong>${esc(expenses)}</strong>
            <span>${esc(items[1]?.label || 'Despesas no mês')}</span>
            <small>Despesas registradas no mesmo recorte.</small>
          </div>
          <div class="drilldown-kpi highlight">
            <strong>${esc(balance)}</strong>
            <span>${esc(items[2]?.label || 'Saldo simples')}</span>
            <small>${esc(data.summary || 'Receita menos despesas no período.')}</small>
          </div>
        </div>
        <div class="drilldown-card-list">
          ${items.map((item) => card('', item.value, badge(item.label, 'neutral'), item.detail || `Resumo financeiro de ${item.label}.`, 'compact')).join('')}
        </div>
      </div>`;
  }

  function renderLeads(data) {
    const items = Array.isArray(data.items) ? data.items : [];
    const totalCount = items.length;
    const totalValue = items.reduce((sum, item) => sum + (Number(item.estimated_value) || 0), 0);
    const rows = items.map((item) => {
      const href = item.id ? `index.php?page=studio_lead&id=${encodeURIComponent(item.id)}` : '';
      const phoneHref = item.phone ? `https://wa.me/${String(item.phone).replace(/[^\d]/g, '')}` : '';
      const meta = [
        item.pipeline_stage ? badge(item.pipeline_stage, 'neutral') : '',
        item.status ? badge(item.status, item.status === 'fechado' ? 'ok' : (item.status === 'perdido' ? 'warn' : 'neutral')) : '',
        item.source ? badge(item.source, 'neutral') : '',
        item.lead_score ? badge(`${item.lead_score}/10`, item.lead_score >= 7 ? 'warn' : 'neutral') : '',
      ].filter(Boolean).join('');
      const detail = [
        item.phone ? `Telefone ${item.phone}` : '',
        item.interest || item.description || '',
        item.updated_at ? `Atualizado ${item.updated_at}` : '',
      ].filter(Boolean).join(' · ');
      return `
        <div class="drilldown-card compact">
          <strong>${esc(item.name || item.phone || 'Lead')}</strong>
          ${meta ? `<div class="meta-line">${meta}</div>` : ''}
          ${detail ? `<div class="drilldown-card-more">${esc(detail)}</div>` : ''}
          <div class="lead-card-actions lead-card-actions-quick">
            ${href ? `<a class="btn tiny secondary" href="${esc(href)}">Ver</a>` : ''}
            ${phoneHref ? `<a class="btn tiny secondary" href="${esc(phoneHref)}" target="_blank" rel="noopener">WhatsApp</a>` : ''}
          </div>
        </div>`;
    }).join('');

    body.innerHTML = `
      <div class="drilldown-toolbar-summary">
        <strong>${esc(totalCount)} leads</strong>
        <span>${esc(money(totalValue))}</span>
        <small>${esc(data.summary || 'Leads em atenção')}</small>
      </div>
      <div class="drilldown-card-list stacked">${rows || '<div class="drilldown-empty"><strong>Nenhum lead encontrado</strong><div class="muted">Não há leads para este recorte.</div></div>'}</div>`;
  }

  function renderAppointments(data) {
    const items = Array.isArray(data.items) ? data.items : [];
    const filters = data.filters || {};
    const filterEntries = Object.entries(filters);

    const renderList = (periodLabel, rows) => {
      const totalCount = rows.length;
      const totalValue = rows.reduce((sum, item) => sum + (Number(item.value) || 0), 0);
      const list = rows.map((item) => {
        const href = item.id ? `index.php?page=studio_agenda&date=${encodeURIComponent(item.appointment_date || '')}&appointment_id=${encodeURIComponent(item.id)}#appointment-form` : '';
        const meta = [
          item.appointment_date ? badge(item.appointment_date) : '',
          item.start_time ? badge(item.start_time.slice(0, 5)) : '',
          item.artist_name ? badge(item.artist_name, 'neutral') : '',
          item.status ? badge(item.status, item.status === 'confirmado' ? 'ok' : 'neutral') : '',
        ].filter(Boolean).join('');
        const detailParts = [item.title, item.value ? `Valor ${money(item.value)}` : '', item.deposit_value ? `Sinal ${money(item.deposit_value)}` : ''].filter(Boolean);
        return card(href, item.customer_name || item.display_name || item.title || 'Agendamento', meta, detailParts.length ? detailParts.join(' · ') : 'Abra para editar ou ver detalhes.', 'compact');
      }).join('');

      body.innerHTML = `
        <div class="availability-toolbar">
          ${filterEntries.map(([key, label]) => `<button type="button" class="drilldown-chip ${key === 'month' ? '' : 'secondary'}" data-appointment-filter="${esc(key)}">${esc(label)}</button>`).join('')}
          <div class="drilldown-toolbar-summary">
            <strong>${esc(totalCount)} agendamentos</strong>
            <span>${esc(money(totalValue))}</span>
            <small>${esc(periodLabel)}</small>
          </div>
        </div>
        <div class="drilldown-card-list stacked">${list || '<div class="drilldown-empty"><strong>Nenhum agendamento encontrado</strong><div class="muted">Não há itens nesse período selecionado.</div></div>'}</div>`;

      setTimeout(() => {
        document.querySelectorAll('[data-appointment-filter]').forEach((btn) => {
          btn.addEventListener('click', () => {
            const key = btn.getAttribute('data-appointment-filter');
            if (!key) return;
            const nextItems = Array.isArray(data.rangeMap?.[key]) ? data.rangeMap[key] : rows;
            const nextLabel = filters[key] || periodLabel;
            renderList(nextLabel, nextItems);
          });
        });
      }, 0);
    };

    renderList(data.summary || 'Período selecionado', items);
  }

  function renderWhatsapp(data) {
    const items = Array.isArray(data.items) ? data.items : [];
    body.innerHTML = `
      <div class="drilldown-card-list">
        ${items.map((item) => {
          const href = `index.php?page=studio_whatsapp_conversation&id=${encodeURIComponent(item.id)}`;
          const meta = [
            item.last_message_at ? badge(item.last_message_at, 'neutral') : '',
            item.attendance_mode ? badge(item.attendance_mode === 'bot' ? 'bot' : 'human', item.attendance_mode === 'bot' ? 'ok' : 'neutral') : '',
            item.needs_human ? badge('precisa humano', 'warn') : '',
            item.ai_last_status ? badge(item.ai_last_status, 'neutral') : '',
          ].filter(Boolean).join('');
          const detail = [
            item.phone ? `Telefone ${item.phone}` : '',
            item.message_count ? `${item.message_count} mensagens` : '',
            item.last_message_preview || 'Sem prévia recente.',
          ].filter(Boolean).join(' · ');
          return card(href, item.display_name || item.phone || 'Contato', meta, detail, 'compact');
        }).join('')}
      </div>`;
  }

  function renderTable(data) {
    const items = Array.isArray(data.items) ? data.items : [];
    const kind = data.kind || '';
    body.innerHTML = `
      <div class="drilldown-card-list">
        ${items.map((item) => {
          const meta = [];
          if (kind === 'whatsapp') {
            if (item.status) meta.push(badge(item.status, 'neutral'));
            if (item.phone) meta.push(badge(item.phone, 'neutral'));
            if (item.last_message_at) meta.push(badge(item.last_message_at, 'neutral'));
            if (item.value) meta.push(badge(item.value, 'ok'));
            const href = item.id ? `index.php?page=studio_whatsapp_conversation&id=${encodeURIComponent(item.id)}` : '';
            const detail = [item.description, item.last_message_preview, item.attendance_mode ? `Atendimento ${item.attendance_mode}` : ''].filter(Boolean).join(' · ');
            return card(href, item.name || item.title || item.customer_name || item.display_name || item.phone || 'Contato', meta.join(''), detail || 'Abra a conversa para ver o histórico.', 'compact');
          }

          if (item.status) meta.push(badge(item.status, item.status === 'confirmado' ? 'ok' : 'neutral'));
          if (item.phone) meta.push(badge(item.phone, 'neutral'));
          if (item.last_message_at) meta.push(badge(item.last_message_at, 'neutral'));
          if (item.value) meta.push(badge(item.value, 'ok'));
          const href = item.id ? `index.php?page=studio_lead&id=${encodeURIComponent(item.id)}` : '';
          const detail = item.description || item.last_message_preview || item.interest || item.source || item.email || '';
          return card(href, item.name || item.title || item.customer_name || item.display_name || item.phone || 'Item', meta.join(''), detail || 'Abra para ver mais detalhes.', 'compact');
        }).join('')}
      </div>`;
  }

  function show(data) {
    title.textContent = data.title || 'Detalhe rápido';
    summary.textContent = data.summary || '';

    if (data.type === 'availability') {
      renderAvailability(data);
    } else if (data.type === 'scheduled_month') {
      renderScheduledMonth(data);
    } else if (data.type === 'finance') {
      renderFinance(data);
    } else if (data.type === 'appointments') {
      renderAppointments(data);
    } else if (data.type === 'leads') {
      renderLeads(data);
    } else if (data.type === 'whatsapp') {
      renderWhatsapp(data);
    } else if (data.type === 'table') {
      renderTable(data);
    } else {
      body.innerHTML = `<div class="drilldown-card">${esc(data.summary || '')}</div>`;
    }

    openModal();
    return false;
  }

  window.openHomeDrilldown = function (key) {
    const data = (window.homeDrilldowns || {})[key];
    if (!data) return false;
    try {
      if (key === 'waiting_replies') {
        return show({
          ...data,
          type: 'scheduled_month',
          title: 'Conversas sem confirmação confiável',
          summary: 'Esse card foi reduzido para evitar informação falsa de leitura de respostas já vistas.',
          count: data.count || 0,
          items: data.items || [],
        });
      }
      return show(data);
    } catch (error) {
      console.error(error);
      return false;
    }
  };

  document.querySelectorAll('[data-home-focus]').forEach((btn) => {
    btn.addEventListener('click', () => window.openHomeDrilldown(btn.getAttribute('data-home-focus') || ''));
  });

  modal.addEventListener('click', (event) => {
    if (event.target === modal) closeModal();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeModal();
  });

  document.addEventListener('click', (event) => {
    const availabilityBtn = event.target.closest('[data-availability-date]');
    if (availabilityBtn) {
      const date = availabilityBtn.getAttribute('data-availability-date') || '';
      const time = availabilityBtn.getAttribute('data-availability-time') || '';
      if (date) {
        const url = `index.php?page=studio_agenda&date=${encodeURIComponent(date)}`;
        window.location.href = `${url}#appointment-form${time ? `&time=${encodeURIComponent(time)}` : ''}`;
      }
      return;
    }

    const appointmentBtn = event.target.closest('[data-appointment-id]');
    if (appointmentBtn) {
      const appointmentId = appointmentBtn.getAttribute('data-appointment-id') || '';
      const date = appointmentBtn.getAttribute('data-appointment-date') || '';
      if (appointmentId && date) {
        window.location.href = `index.php?page=studio_agenda&date=${encodeURIComponent(date)}&appointment_id=${encodeURIComponent(appointmentId)}#appointment-form`;
      }
    }
  });
})();
