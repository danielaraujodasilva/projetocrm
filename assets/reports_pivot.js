(function () {
  const mount = document.getElementById('reportsPivot');
  if (!mount || !window.WebDataRocks || !window.reportsPivotData) return;

  const ptLabels = {
    messages: {
      error: 'Erro!',
      warning: 'Aviso!',
      limitation: 'Limitação!',
      browse: 'Procurar',
      confirmation: 'Confirmação',
      reportFileType: 'Arquivo de relatório do WebDataRocks',
      loading: 'Carregando...',
      loadingConfiguration: '',
      loadingData: 'Carregando dados...',
      uploading: 'Enviando...',
    },
    weekdays: {
      first: 'Domingo',
      second: 'Segunda-feira',
      third: 'Terça-feira',
      fourth: 'Quarta-feira',
      fifth: 'Quinta-feira',
      sixth: 'Sexta-feira',
      seventh: 'Sábado',
    },
    weekdaysShort: {
      first: 'Dom',
      second: 'Seg',
      third: 'Ter',
      fourth: 'Qua',
      fifth: 'Qui',
      sixth: 'Sex',
      seventh: 'Sáb',
    },
    toolbar: {
      connect: 'Conectar',
      connect_local_csv: 'CSV local',
      connect_local_json: 'JSON local',
      connect_google_drive: 'Google Drive',
      connect_remote_csv: 'CSV remoto',
      connect_remote_csv_mobile: 'CSV',
      connect_remote_json: 'JSON remoto',
      connect_remote_json_mobile: 'JSON',
      open: 'Abrir',
      local_report: 'Relatório local',
      remote_report: 'Relatório remoto',
      remote_report_mobile: 'Relatório',
      save: 'Salvar',
      load_json: 'Relatório JSON',
      grid: 'Grade',
      grid_flat: 'Plana',
      grid_classic: 'Clássica',
      grid_compact: 'Compacta',
      format: 'Formatar',
      format_cells: 'Formatar células',
      format_cells_mobile: 'Formatar',
      conditional_formatting: 'Formatação condicional',
      conditional_formatting_mobile: 'Condicional',
      options: 'Opções',
      fullscreen: 'Tela cheia',
      minimize: 'Minimizar',
      export: 'Exportar',
      export_print: 'Imprimir',
      export_html: 'Para HTML',
      export_csv: 'Para CSV',
      export_excel: 'Para Excel',
      export_image: 'Para imagem',
      export_pdf: 'Para PDF',
      fields: 'Campos',
      ok: 'OK',
      apply: 'Aplicar',
      done: 'Concluir',
      cancel: 'Cancelar',
      value: 'Valor',
      delete: 'Excluir',
      if: 'Se',
      then: 'Então',
      open_remote_csv: 'Abrir CSV remoto',
      open_remote_json: 'Abrir JSON remoto',
      csv: 'CSV',
      credentials: 'credenciais',
      username: 'Usuário',
      password: 'Senha',
      open_remote_report: 'Abrir relatório remoto',
      choose_value: 'Escolha o valor',
      text_align: 'Alinhamento do texto',
      align_left: 'esquerda',
      align_right: 'direita',
      none: 'Nenhum',
      space: '(Espaço)',
      thousand_separator: 'Separador de milhar',
      decimal_separator: 'Separador decimal',
      decimal_places: 'Casas decimais',
      currency_symbol: 'Símbolo da moeda',
      currency_align: 'Alinhamento da moeda',
      null_value: 'Valor nulo',
      is_percent: 'Formatar como percentual',
      true_value: 'verdadeiro',
      false_value: 'falso',
      conditional: 'Condicional',
      add_condition: 'Adicionar condição',
      less_than: 'Menor que',
      less_than_or_equal: 'Menor ou igual a',
      greater_than: 'Maior que',
      greater_than_or_equal: 'Maior ou igual a',
      equal_to: 'Igual a',
      not_equal_to: 'Diferente de',
      between: 'Entre',
      is_empty: 'Vazio',
      all_values: 'Todos os valores',
      and: 'e',
      and_symbole: '&',
      cp_text: 'Texto',
      cp_highlight: 'Destaque',
      layout_options: 'Opções de layout',
      layout: 'Layout',
      compact_view: 'Forma compacta',
      classic_view: 'Forma clássica',
      flat_view: 'Forma plana',
      grand_totals: 'Totais gerais',
      grand_totals_off: 'Não mostrar totais gerais',
      grand_totals_on: 'Mostrar totais gerais',
      grand_totals_on_rows: 'Mostrar só nas linhas',
      grand_totals_on_columns: 'Mostrar só nas colunas',
      subtotals: 'Subtotais',
      subtotals_off: 'Não mostrar subtotais',
      subtotals_on: 'Mostrar subtotais',
      subtotals_on_rows: 'Mostrar subtotais só nas linhas',
      subtotals_on_columns: 'Mostrar subtotais só nas colunas',
      choose_page_orientation: 'Escolha a orientação da página',
      landscape: 'Paisagem',
      portrait: 'Retrato',
      message_error: 'Erro!',
      message_no_google_client_id: '',
    },
  };

  const translations = [
    ['Excel-like', 'Estilo planilha'],
    ['Connect', 'Conectar'],
    ['Open', 'Abrir'],
    ['Save', 'Salvar'],
    ['Export', 'Exportar'],
    ['Fields', 'Campos'],
    ['Options', 'Opções'],
    ['Fullscreen', 'Tela cheia'],
    ['Format', 'Formatar'],
    ['Format cells', 'Formatar células'],
    ['Conditional formatting', 'Formatação condicional'],
    ['Grid', 'Grade'],
    ['Flat', 'Plana'],
    ['Classic', 'Clássica'],
    ['Compact', 'Compacta'],
    ['Local report', 'Relatório local'],
    ['Remote report', 'Relatório remoto'],
    ['Report', 'Relatório'],
    ['To local CSV', 'CSV local'],
    ['To local JSON', 'JSON local'],
    ['To remote CSV', 'CSV remoto'],
    ['To remote JSON', 'JSON remoto'],
    ['To HTML', 'Para HTML'],
    ['To CSV', 'Para CSV'],
    ['To Excel', 'Para Excel'],
    ['To Image', 'Para imagem'],
    ['To PDF', 'Para PDF'],
    ['Print', 'Imprimir'],
    ['Apply', 'Aplicar'],
    ['Cancel', 'Cancelar'],
    ['Done', 'Concluir'],
    ['Choose value', 'Escolha o valor'],
    ['Text align', 'Alinhamento do texto'],
    ['Thousand separator', 'Separador de milhar'],
    ['Decimal separator', 'Separador decimal'],
    ['Decimal places', 'Casas decimais'],
    ['Currency symbol', 'Símbolo da moeda'],
    ['Currency align', 'Alinhamento da moeda'],
    ['Null value', 'Valor nulo'],
    ['Format as percent', 'Formatar como percentual'],
    ['Conditional', 'Condicional'],
    ['Add condition', 'Adicionar condição'],
    ['Less than', 'Menor que'],
    ['Less than or equal to', 'Menor ou igual a'],
    ['Greater than', 'Maior que'],
    ['Greater than or equal to', 'Maior ou igual a'],
    ['Equal to', 'Igual a'],
    ['Not equal to', 'Diferente de'],
    ['Between', 'Entre'],
    ['Empty', 'Vazio'],
    ['All values', 'Todos os valores'],
    ['Layout options', 'Opções de layout'],
    ['Grand totals', 'Totais gerais'],
    ['Do not show grand totals', 'Não mostrar totais gerais'],
    ['Show grand totals', 'Mostrar totais gerais'],
    ['Show for rows only', 'Mostrar só nas linhas'],
    ['Show for columns only', 'Mostrar só nas colunas'],
    ['Subtotals', 'Subtotais'],
    ['Do not show subtotals', 'Não mostrar subtotais'],
    ['Show subtotals', 'Mostrar subtotais'],
    ['Show subtotal rows only', 'Mostrar subtotais só nas linhas'],
    ['Show subtotal columns only', 'Mostrar subtotais só nas colunas'],
    ['Choose page orientation', 'Escolha a orientação da página'],
    ['Landscape', 'Paisagem'],
    ['Portrait', 'Retrato'],
    ['Loading data...', 'Carregando dados...'],
    ['Loading...', 'Carregando...'],
    ['Error!', 'Erro!'],
    ['Warning!', 'Aviso!'],
    ['Confirmation', 'Confirmação'],
    ['Browse', 'Procurar'],
    ['Columns', 'Colunas'],
    ['Rows', 'Linhas'],
    ['Measures', 'Medidas'],
    ['Filters', 'Filtros'],
    ['Values', 'Valores'],
  ];

  function translateText(root) {
    if (!root) return;
    const nodes = root.querySelectorAll('span,button,a,label,div,h1,h2,h3,h4,h5,h6,p');
    nodes.forEach((el) => {
      const text = (el.textContent || '').trim();
      if (!text) return;
      for (const [from, to] of translations) {
        if (text === from) {
          el.textContent = to;
          break;
        }
      }
    });
  }

  function applyToolbarLabels(toolbar) {
    if (!toolbar) return;
    try {
      toolbar.updateLabels(ptLabels.toolbar);
      toolbar.Labels = Object.assign({}, toolbar.Labels || {}, ptLabels.toolbar);
    } catch (error) {
      console.warn('Nao foi possivel aplicar a traducao da barra da tabela dinamica', error);
    }
    setTimeout(() => translateText(document.getElementById('wdr-toolbar-wrapper')), 0);
  }

  function makeReport(source) {
    const def = (window.reportsPivotData || {})[source] || window.reportsPivotData.leads;
    return {
      dataSource: { dataSourceType: 'json', data: def.data },
      slice: def.report.slice,
      options: {
        grid: { showGrandTotals: 'on', showTotals: 'on', type: 'flat' },
        configuratorButton: true,
        sorting: 'on',
        drillThrough: true,
        drillThroughMaxRows: 50,
      },
      localization: ptLabels,
      formats: [
        { name: 'currency', thousandsSeparator: '.', decimalSeparator: ',', decimalPlaces: 2, currencySymbol: 'R$ ', currencySymbolAlign: 'left' },
        { name: 'int', thousandsSeparator: '.', decimalPlaces: 0 },
      ],
    };
  }

  function initPivot(source) {
    mount.innerHTML = '';
    document.querySelectorAll('[data-pivot-source]').forEach((btn) => {
      btn.classList.toggle('active', (btn.getAttribute('data-pivot-source') || '') === source);
    });

    window.reportsPivot = new WebDataRocks({
      container: '#reportsPivot',
      toolbar: true,
      height: 640,
      report: makeReport(source),
      beforetoolbarcreated: applyToolbarLabels,
      global: {
        options: {
          grid: { showFilter: true, showReportFiltersArea: true },
          configuratorButton: true,
          sorting: 'on',
          drillThrough: true,
        },
      },
    });

    const observer = new MutationObserver(() => translateText(document.getElementById('wdr-toolbar-wrapper')));
    observer.observe(mount, { childList: true, subtree: true });
    setTimeout(() => translateText(document.getElementById('wdr-toolbar-wrapper')), 400);
  }

  document.querySelectorAll('[data-pivot-source]').forEach((btn) => {
    btn.addEventListener('click', function () {
      initPivot(this.getAttribute('data-pivot-source') || 'leads');
    });
  });

  initPivot(window.reportsPivotSource || 'leads');
})();
