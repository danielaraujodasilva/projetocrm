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

  function renderAvailability(data) {
    const ranges = Object.entries(data.ranges || {});
    const defaultRange = data.default_range || '7d';
    const renderRange = (rangeKey) => {
      const pair =
        ranges.find(([key]) => key === rangeKey) ||
        ranges.find(([key]) => key === defaultRange) ||
        ranges[0] ||
        ['7d', { label: '7 dias', items: [] }];
      const keyReal = pair[0];
      const range = pair[1] || { items: [] };
      const options = ranges
        .map(([key, value]) => `<option value="${esc(key)}"${key === keyReal ? ' selected' : ''}>${esc(value.label || key)}</option>`)
        .join('');
      const days = (range.items || [])
        .map((item) => {
          const free = (item.free_slots || [])
            .map((slot) => `<button type="button" class="drilldown-chip" data-availability-date="${esc(item.date)}">${esc(slot)}</button>`)
            .join('');
          const booked = (item.booked || [])
            .map((appt) => `<button type="button" class="drilldown-chip secondary" data-appointment-id="${esc(appt.id || 0)}" data-appointment-date="${esc(item.date)}">${esc(appt.time)}${appt.customer_name ? ` • ${esc(appt.customer_name)}` : ''}</button>`)
            .join('');
          return (
            `<details class="drilldown-card drilldown-details" open>` +
            `<summary><strong>${esc(item.label)}</strong><span class="muted">${esc(item.allowed ? `${item.free} vagas livres` : 'Fora dos dias permitidos')}</span></summary>` +
            `<div class="drilldown-detail-list">` +
            `<div><span class="muted">Horarios livres</span>${free ? `<div class="drilldown-chip-row">${free}</div>` : '<p class="muted">Sem horarios livres nesse dia.</p>'}</div>` +
            `${booked ? `<div style="margin-top:10px"><span class="muted">Ocupados</span><div class="drilldown-chip-row">${booked}</div></div>` : ''}` +
            `</div></details>`
          );
        })
        .join('');

      body.innerHTML =
        `<div class="availability-toolbar">` +
        `<label class="field" style="max-width:240px"><span class="muted">Período</span><select id="availabilityRangeSelect">${options}</select></label>` +
        `<p class="muted">Mostrando ${esc(range.label || keyReal)}. Toque num dia ou horario para navegar direto.</p>` +
        `</div>` +
        `<div class="drilldown-grid availability-grid">` +
        (days || '<div class="drilldown-card"><strong>Nenhuma vaga livre encontrada</strong><div class="muted">Nesse período não apareceu nenhum horário livre rápido dentro das regras do estúdio.</div></div>') +
        `</div>`;

      setTimeout(() => {
        const sel = document.getElementById('availabilityRangeSelect');
        if (sel) {
          sel.addEventListener('change', function () {
            renderRange(this.value);
          });
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
})();
