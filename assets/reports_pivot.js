(function () {
  const mount = document.getElementById('reportsPivot');
  if (!mount || !window.reportsPivotData || !window.jQuery || !jQuery.fn || !jQuery.fn.pivotUI) return;

  const dataSets = window.reportsPivotData;

  const ptRenderers = {
    'Tabela': jQuery.pivotUtilities.renderers['Table'],
    'Tabela com Barras': jQuery.pivotUtilities.renderers['Table Barchart'],
    'Mapa de Calor': jQuery.pivotUtilities.renderers['Heatmap'],
    'Mapa de Calor por Linhas': jQuery.pivotUtilities.renderers['Row Heatmap'],
    'Mapa de Calor por Colunas': jQuery.pivotUtilities.renderers['Col Heatmap'],
  };

  const common = {
    locale: 'pt',
    rows: [],
    cols: [],
    vals: [],
    rendererName: 'Tabela',
    renderers: ptRenderers,
    sorters: {},
    menuLimit: 1000,
    rendererOptions: {
      table: {
        rowTotals: true,
        colTotals: true,
      },
    },
    aggregators: jQuery.pivotUtilities.aggregators,
  };

  const presets = {
    leads: {
      rows: ['status'],
      cols: ['origem'],
      vals: ['total'],
      aggregatorName: 'Contagem',
      rendererName: 'Tabela com Barras',
    },
    appointments: {
      rows: ['status'],
      cols: ['tatuador'],
      vals: ['valor'],
      aggregatorName: 'Soma',
      rendererName: 'Tabela',
    },
    expenses: {
      rows: ['categoria'],
      cols: ['meio'],
      vals: ['valor'],
      aggregatorName: 'Soma',
      rendererName: 'Tabela',
    },
  };

  function setSourceButtonState(source) {
    document.querySelectorAll('[data-pivot-source]').forEach((btn) => {
      btn.classList.toggle('active', (btn.getAttribute('data-pivot-source') || '') === source);
    });
  }

  function render(source) {
    const def = dataSets[source] || dataSets.leads;
    const preset = presets[source] || presets.leads;

    mount.innerHTML = '';
    setSourceButtonState(source);

    const options = jQuery.extend(true, {}, common, preset, {
      rows: preset.rows.slice(),
      cols: preset.cols.slice(),
      vals: preset.vals.slice(),
      rendererName: preset.rendererName,
      aggregatorName: preset.aggregatorName,
      onRefresh: function () {
        window.reportsPivotCurrent = source;
      },
    });

    jQuery(mount).pivotUI(def.data, options, true, 'pt');
    mount.querySelectorAll('select, input, button').forEach((el) => {
      el.setAttribute('lang', 'pt-BR');
    });
  }

  document.querySelectorAll('[data-pivot-source]').forEach((btn) => {
    btn.addEventListener('click', function () {
      render(this.getAttribute('data-pivot-source') || 'leads');
    });
  });

  render(window.reportsPivotSource || 'leads');
})();
