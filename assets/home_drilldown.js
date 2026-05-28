(function () {
  const modal = document.getElementById('homeDrilldownModal');
  const title = document.getElementById('homeDrilldownTitle');
  const summary = document.getElementById('homeDrilldownSummary');
  const body = document.getElementById('homeDrilldownBody');
  const todayAgendaModal = document.getElementById('homeTodayAgendaModal');
  const todayAgendaBody = document.getElementById('homeTodayAgendaBody');
  const closeTodayAgendaBtn = document.getElementById('closeHomeTodayAgendaModal');

  if (!modal || !title || !summary || !body) return;

  const esc = (value) =>
    String(value ?? '').replace(/[&<>"']/g, (char) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char] || char)
    );

  const money = (value) => {
    const raw = String(value ?? 0).trim();
    if (!raw) {
      return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(0);
    }
    let normalized = raw.replace(/[^\d,.\-]/g, '');
    if (normalized.includes(',') && normalized.includes('.')) {
      normalized = normalized.replace(/\./g, '').replace(',', '.');
    } else if (normalized.includes(',')) {
      normalized = normalized.replace(/\./g, '').replace(',', '.');
    } else if (/^-?\d{1,3}(?:\.\d{3})+$/.test(normalized)) {
      normalized = normalized.replace(/\./g, '');
    }
    const numeric = Number(normalized);
    let safe = Number.isFinite(numeric) ? numeric : Number(raw) || 0;
    if (Number.isFinite(safe) && safe >= 10000 && Number.isInteger(safe) && safe % 100 === 0) {
      safe /= 100;
    }
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(safe);
  };

  const badge = (text, tone = 'neutral') => `<span class="drilldown-badge ${tone}">${esc(text)}</span>`;

  const formatDatePt = (value, withTime = false) => {
    const raw = String(value ?? '').trim();
    if (!raw) return '-';
    const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return raw;
    const weekdays = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
    const weekday = (weekdays[date.getDay()] || '').toUpperCase();
    const dd = String(date.getDate()).padStart(2, '0');
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const yyyy = date.getFullYear();
    const hh = String(date.getHours()).padStart(2, '0');
    const mi = String(date.getMinutes()).padStart(2, '0');
    return withTime ? `${weekday} - ${dd}/${mm}/${yyyy} ${hh}:${mi}` : `${weekday} - ${dd}/${mm}/${yyyy}`;
  };

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
                  <span>${esc(formatDatePt(item.date))}</span>
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
          item.appointment_date ? badge(formatDatePt(item.appointment_date)) : '',
          item.start_time ? badge(item.start_time.slice(0, 5)) : '',
          item.status ? badge(item.status, item.status === 'confirmado' ? 'ok' : 'neutral') : '',
        ].filter(Boolean).join('');
        const detail = `${item.title || item.customer_name || 'Atendimento'} · ${item.value_label || money(item.value || 0)}`;
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
        item.last_message_at ? badge(formatDatePt(item.last_message_at, true), 'neutral') : '',
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
        item.updated_at ? `Atualizado ${formatDatePt(item.updated_at, true)}` : '',
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
            item.appointment_date ? badge(formatDatePt(item.appointment_date)) : '',
            item.start_time ? badge(item.start_time.slice(0, 5)) : '',
            item.artist_name ? badge(item.artist_name, 'neutral') : '',
            item.status ? badge(item.status, item.status === 'confirmado' ? 'ok' : 'neutral') : '',
        ].filter(Boolean).join('');
        const detailParts = [item.title, item.value_label ? `Valor ${item.value_label}` : (item.value ? `Valor ${money(item.value)}` : ''), item.deposit_label ? `Sinal ${item.deposit_label}` : (item.deposit_value ? `Sinal ${money(item.deposit_value)}` : '')].filter(Boolean);
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

  function renderMetaCampaign(data) {
    const items = Array.isArray(data.items) ? data.items : [];
    const filters = data.filters || {};
    const filterEntries = Object.entries(filters);
    const trackedPhrases = data.tracking_hint || '';
    const allItems = Array.isArray(data.all_items) ? data.all_items : items;
    const defaultRange = data.default_range || 'today';
    const todayIso = data.today_iso || '';

    const applyDateRange = (rows, startDate, endDate) => {
      if (!startDate || !endDate) return rows;
      return rows.filter((item) => {
        const value = String(item.first_message_at || '').slice(0, 10);
        if (!value) return false;
        return value >= startDate && value <= endDate;
      });
    };

    const renderList = (periodLabel, rows) => {
      const totalCount = rows.length;
      const list = rows.map((item) => {
        const href = item.lead_id
          ? `index.php?page=studio_lead&id=${encodeURIComponent(item.lead_id)}`
          : `index.php?page=studio_whatsapp_conversation&id=${encodeURIComponent(item.id)}`;
        const heading = item.lead_name || item.customer_name || item.name || item.phone || 'Contato da campanha';
        const meta = [
          item.first_message_at ? badge(formatDatePt(item.first_message_at, true), 'neutral') : '',
          item.pipeline_stage ? badge(item.pipeline_stage, 'neutral') : '',
          item.lead_status ? badge(item.lead_status, item.lead_status === 'fechado' ? 'ok' : 'neutral') : '',
          item.phone ? badge(item.phone, 'neutral') : '',
        ].filter(Boolean).join('');
        const detail = [
          item.first_message_body ? `Primeira mensagem: ${item.first_message_body}` : '',
          item.estimated_value ? `Estimado ${money(item.estimated_value)}` : '',
          item.lead_id ? 'Lead vinculado' : 'Sem lead vinculado ainda',
        ].filter(Boolean).join(' · ');
        return card(href, heading, meta, detail, 'compact');
      }).join('');

      body.innerHTML = `
        <div class="availability-toolbar">
          ${filterEntries.map(([key, label]) => `<button type="button" class="drilldown-chip ${key === defaultRange ? '' : 'secondary'}" data-meta-filter="${esc(key)}">${esc(label)}</button>`).join('')}
          <label class="field availability-custom-field"><span class="muted">De</span><input id="metaCampaignStartDate" type="date" value="${esc(todayIso)}"></label>
          <label class="field availability-custom-field"><span class="muted">Até</span><input id="metaCampaignEndDate" type="date" value="${esc(todayIso)}"></label>
          <button type="button" class="btn secondary" id="metaCampaignApplyDates">Aplicar</button>
          <div class="drilldown-toolbar-summary">
            <strong>${esc(totalCount)} contatos</strong>
            <span>${esc(periodLabel)}</span>
            <small>${esc(trackedPhrases ? `Frases rastreadas: ${trackedPhrases}` : 'Usando a primeira mensagem recebida da conversa.')}</small>
          </div>
        </div>
        <div class="drilldown-card-list stacked">${list || '<div class="drilldown-empty"><strong>Nenhuma entrada encontrada</strong><div class="muted">Nenhuma primeira mensagem bateu com as frases configuradas nesse período.</div></div>'}</div>`;

      setTimeout(() => {
        document.querySelectorAll('[data-meta-filter]').forEach((btn) => {
          btn.addEventListener('click', () => {
            const key = btn.getAttribute('data-meta-filter');
            if (!key) return;
            const nextItems = Array.isArray(data.rangeMap?.[key]) ? data.rangeMap[key] : rows;
            const nextLabel = filters[key] || periodLabel;
            renderList(nextLabel, nextItems);
          });
        });
        const startInput = document.getElementById('metaCampaignStartDate');
        const endInput = document.getElementById('metaCampaignEndDate');
        const applyButton = document.getElementById('metaCampaignApplyDates');
        if (applyButton && startInput && endInput) {
          applyButton.addEventListener('click', () => {
            const startDate = startInput.value || '';
            const endDate = endInput.value || '';
            const filtered = applyDateRange(allItems, startDate, endDate);
            const label = startDate && endDate
              ? `Período livre: ${startDate.split('-').reverse().join('/')} até ${endDate.split('-').reverse().join('/')}`
              : 'Período personalizado';
            renderList(label, filtered);
          });
        }
      }, 0);
    };

    renderList(filters[defaultRange] || data.summary || 'Período selecionado', items);
  }

  function renderWhatsapp(data) {
    const items = Array.isArray(data.items) ? data.items : [];
    body.innerHTML = `
      <div class="drilldown-card-list">
        ${items.map((item) => {
          const href = `index.php?page=studio_whatsapp_conversation&id=${encodeURIComponent(item.id)}`;
          const meta = [
            item.last_message_at ? badge(formatDatePt(item.last_message_at, true), 'neutral') : '',
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
            if (item.last_message_at) meta.push(badge(formatDatePt(item.last_message_at, true), 'neutral'));
            if (item.value) meta.push(badge(item.value, 'ok'));
            const href = item.id ? `index.php?page=studio_whatsapp_conversation&id=${encodeURIComponent(item.id)}` : '';
            const detail = [item.description, item.last_message_preview, item.attendance_mode ? `Atendimento ${item.attendance_mode}` : ''].filter(Boolean).join(' · ');
            return card(href, item.name || item.title || item.customer_name || item.display_name || item.phone || 'Contato', meta.join(''), detail || 'Abra a conversa para ver o histórico.', 'compact');
          }

          if (item.status) meta.push(badge(item.status, item.status === 'confirmado' ? 'ok' : 'neutral'));
          if (item.phone) meta.push(badge(item.phone, 'neutral'));
          if (item.last_message_at) meta.push(badge(formatDatePt(item.last_message_at, true), 'neutral'));
          if (item.value) meta.push(badge(item.value, 'ok'));
          const href = item.id ? `index.php?page=studio_lead&id=${encodeURIComponent(item.id)}` : '';
          const detail = item.description || item.last_message_preview || item.interest || item.source || item.email || '';
          return card(href, item.name || item.title || item.customer_name || item.display_name || item.phone || 'Item', meta.join(''), detail || 'Abra para ver mais detalhes.', 'compact');
        }).join('')}
      </div>`;
  }

  function renderTodayAgenda(data) {
    if (!todayAgendaModal || !todayAgendaBody) return;
    const items = Array.isArray(data.items) ? data.items : [];
    const rows = items.map((appointment) => {
      const href = appointment.id ? `index.php?page=studio_agenda&date=${encodeURIComponent(appointment.appointment_date || '')}&appointment_id=${encodeURIComponent(appointment.id)}#appointment-form` : '';
      const status = String(appointment.status || '');
      const statusTone = ['confirmado', 'agendado'].includes(status) ? 'ok' : (status === 'pre_agendado' ? 'warn' : (['cancelado', 'perdido'].includes(status) ? 'danger' : 'neutral'));
      const value = money(appointment.value ?? 0);
      const deposit = money(appointment.deposit_value ?? 0);
      return `
        <tr>
          <td><a href="${esc(href)}"><strong>${esc(String(appointment.start_time || '').slice(0, 5))}</strong></a></td>
          <td>${esc(appointment.customer_name || appointment.title || '-')}</td>
          <td>${esc(appointment.artist_name || '-')}</td>
          <td><span class="badge ${esc(statusTone)}">${esc(status || '-')}</span></td>
          <td>${esc(value)}</td>
          <td>${esc(deposit)}</td>
          <td>${esc(String(appointment.description || appointment.notes || '-').slice(0, 80))}</td>
        </tr>`;
    }).join('');

    todayAgendaBody.innerHTML = `
      <div class="drilldown-toolbar-summary" style="margin-bottom:16px">
        <strong>${esc(items.length)} agendamentos</strong>
        <span>${esc(data.date || '')}</span>
        <small>Agenda do dia selecionado.</small>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Hora</th><th>Cliente / Lead</th><th>Tatuador</th><th>Status</th><th>Valor</th><th>Sinal</th><th>Obs</th></tr></thead>
          <tbody>${rows || '<tr><td colspan="7" class="muted">Nenhum atendimento agendado para hoje.</td></tr>'}</tbody>
        </table>
      </div>`;

    todayAgendaModal.classList.remove('hidden');
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
    } else if (data.type === 'meta_campaign') {
      renderMetaCampaign(data);
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
      if (key === 'today_agenda') {
        renderTodayAgenda(window.homeTodayAgenda || { items: [] });
        return false;
      }
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

  if (todayAgendaModal) {
    todayAgendaModal.addEventListener('click', (event) => {
      if (event.target === todayAgendaModal) todayAgendaModal.classList.add('hidden');
    });
  }

  if (closeTodayAgendaBtn) {
    closeTodayAgendaBtn.addEventListener('click', () => todayAgendaModal && todayAgendaModal.classList.add('hidden'));
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeModal();
    if (event.key === 'Escape' && todayAgendaModal) todayAgendaModal.classList.add('hidden');
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
