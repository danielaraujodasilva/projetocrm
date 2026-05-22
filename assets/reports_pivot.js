(function () {
  const mount = document.getElementById('reportsPivot');
  const dataSets = window.reportsPivotData || {};
  const defaultSource = window.reportsPivotSource || 'leads';

  if (!mount || !window.WebDataRocks || !dataSets[defaultSource]) {
    return;
  }

  const sourceButtons = Array.from(document.querySelectorAll('[data-pivot-source]'));
  let pivot = null;

  function setSourceButtonState(source) {
    sourceButtons.forEach((button) => {
      button.classList.toggle('active', (button.getAttribute('data-pivot-source') || '') === source);
    });
  }

  function buildReport(source) {
    const definition = dataSets[source] || dataSets[defaultSource];
    const report = JSON.parse(JSON.stringify(definition.report || {}));
    report.dataSource = {
      data: definition.data || [],
    };
    report.options = Object.assign({}, report.options || {}, {
      grid: Object.assign({}, (report.options || {}).grid || {}, {
        type: 'compact',
        showFilter: true,
        showHeaders: true,
        showGrandTotals: 'on',
        showTotals: 'on',
      }),
      configuratorButton: true,
      showAggregationLabels: true,
    });
    report.formats = [
      { name: 'currency', currencySymbol: 'R$ ', currencySymbolAlign: 'left', thousandsSeparator: '.', decimalSeparator: ',', decimalPlaces: 2 },
      { name: 'int', thousandsSeparator: '.', decimalSeparator: ',', decimalPlaces: 0 },
    ];

    return report;
  }

  function render(source) {
    const report = buildReport(source);
    setSourceButtonState(source);

    if (pivot) {
      pivot.setReport(report);
      return;
    }

    pivot = new WebDataRocks({
      container: '#reportsPivot',
      height: 640,
      width: '100%',
      toolbar: true,
      report,
      beforetoolbarcreated: function (toolbarInstance) {
        toolbarInstance.getTabs = function () {
          const tabs = WebDataRocksToolbar.prototype.getTabs.call(this);
          return tabs.filter((tab) => !['connect'].includes(tab.id));
        };
      },
      reportcomplete: function () {
        if (pivot) {
          pivot.off('reportcomplete');
        }
      },
    });
  }

  sourceButtons.forEach((button) => {
    button.addEventListener('click', function () {
      const source = this.getAttribute('data-pivot-source') || defaultSource;
      render(source);
    });
  });

  render(defaultSource);
})();
