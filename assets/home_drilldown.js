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

  const card = (href, heading, metaHtml, detail) =>
    `<a class="drilldown-card"${href ? ` href="${esc(href)}"` : ''}>` +
    `${heading ? `<strong>${esc(heading)}</strong>` : ''}` +
    `${metaHtml ? `<div class="meta-line">${metaHtml}</div>` : ''}` +
    `${detail ? `<div class="muted">${esc(detail)}</div>` : ''}` +
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

      const blocks = [];
      items.forEach((item) => {
        (item.free_slots || []).forEach((slot) => {
          blocks.push(
            `<button type="button" class="availability-block" data-availability-date="${esc(item.date)}" data-availability-time="${esc(slot)}">` +
            `<strong>${esc(item.label)}</strong>` +
            `<span>${esc(slot)}</span>` +
            `</button>`
          );
        });
      });

      const empty = `<div class="drilldown-card"><strong>Nenhuma vaga livre encontrada</strong><div class="muted">Nesse prazo não apareceu nenhum horário livre dentro das regras do estúdio.</div></div>`;

      body.innerHTML =
        `<div class="availability-toolbar">` +
        `<label class="field" style="min-width:220px"><span class="muted">Atalhos</span><select id="availabilityRangeSelect">${options}</select></label>` +
        `<label class="field" style="max-width:180px"><span class="muted">Prazo livre</span><input id="availabilityDaysInput" type="number" min="1" max="${freeLimit}" value="${Math.min(7, freeLimit)}"></label>` +
        `<button type="button" class="btn secondary" id="availabilityDaysApply">Aplicar</button>` +
        `<p class="muted">Mostrando ${esc(chosenLabel)}. Clique numa vaga para abrir o dia correspondente.</p>` +
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

  function show(data) {
    title.textContent = data.title || 'Detalhe rápido';
    summary.textContent = data.summary || '';

    if (data.type === 'availability') {
      renderAvailability(data);
    } else if (data.type === 'finance') {
      body.innerHTML = `<div class="drilldown-grid">${(data.items || []).map((item) => card('', item.value, item.label, item.detail || '')).join('')}</div>`;
    } else if (data.type === 'appointments') {
      body.innerHTML = `<div class="drilldown-grid">${(data.items || []).map((item) => card(item.id ? `index.php?page=studio_agenda&date=${encodeURIComponent(item.appointment_date || '')}&appointment_id=${encodeURIComponent(item.id)}#appointment-form` : '', item.customer_name || item.display_name || item.title || 'Agendamento', `<span>${esc(item.appointment_date || item.last_message_at || '-')}</span><span>${esc(item.start_time ? item.start_time.slice(0, 5) : '')}</span>`, item.description || item.last_message_preview || item.title || '-')).join('')}</div>`;
    } else if (data.type === 'whatsapp') {
      body.innerHTML = `<div class="drilldown-grid">${(data.items || []).map((item) => card(`index.php?page=studio_whatsapp_conversation&id=${encodeURIComponent(item.id)}`, item.display_name || item.phone || 'Contato', `<span>${esc(item.last_message_at || '-')}</span><span>${esc(item.attendance_mode || '-')}</span>`, item.last_message_preview || '-')).join('')}</div>`;
    } else if (data.type === 'table') {
      body.innerHTML = `<div class="drilldown-grid">${(data.items || []).map((item) => {
        const meta = [];
        if (item.status) meta.push(`<span>${esc(item.status)}</span>`);
        if (item.phone) meta.push(`<span>${esc(item.phone)}</span>`);
        if (item.last_message_at) meta.push(`<span>${esc(item.last_message_at)}</span>`);
        if (item.value) meta.push(`<span>${esc(item.value)}</span>`);
        return card('', item.name || item.title || item.customer_name || item.display_name || item.phone || 'Item', meta.join(''), item.description || item.last_message_preview || item.interest || '');
      }).join('')}</div>`;
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
