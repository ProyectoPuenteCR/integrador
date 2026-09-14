(function (root) {
  'use strict';
  function text(value) { return value == null ? '' : String(value).trim(); }
  function fold(value) { return text(value).toLocaleLowerCase('es'); }
  function matches(row, filters, search) {
    if (search && !Object.keys(row).some(function (key) { return fold(row[key]).indexOf(fold(search)) !== -1; })) return false;
    return filters.every(function (filter) {
      return filter.exact ? text(row[filter.field]) === filter.value : fold(row[filter.field]).indexOf(fold(filter.value)) !== -1;
    });
  }
  function groups(rows, field) {
    var counts = new Map();
    rows.forEach(function (row) { var key = text(row[field]); counts.set(key, (counts.get(key) || 0) + 1); });
    return Array.from(counts, function (item) { return { value: item[0], label: item[0] || 'Sin dato', count: item[1] }; })
      .sort(function (a, b) { return a.label.localeCompare(b.label, 'es', { numeric: true }); });
  }
  function csvCell(value) {
    var out = text(value);
    // Evitar que Excel interprete señales o nombres como fórmulas.
    if (/^[=+@\-\t\r\n]/.test(out)) out = "'" + out;
    return '"' + out.replace(/"/g, '""') + '"';
  }
  var pure = { matches: matches, groups: groups, csvCell: csvCell };
  if (typeof module !== 'undefined' && module.exports) module.exports = pure;
  if (!root.document) return;
  var cfg = root.CLEAR_BM_MONITOR, doc = root.document;
  if (!cfg || cfg.error) return;
  var table = doc.getElementById('nmWellsTable');
  if (!table) return;
  var rows = cfg.rows || [], search = doc.getElementById('nmSearch'), controls = Array.from(doc.querySelectorAll('[data-nm-filter]'));
  var rowNodes = new Map(Array.from(table.querySelectorAll('[data-nm-row]')).map(function (node) { return [Number(node.dataset.nmRow), node]; }));
  var colors = ['#1a4d5c', '#438eaa', '#bd793c', '#7354a3', '#678554', '#a34e75', '#5b73a2', '#8b763e'];
  var charts = [], visible = [], summary = '', number = new Intl.NumberFormat('es-AR');
  var status = doc.getElementById('nmStatus');
  function announce(message) { status.textContent = message; }
  function selectedFilters() {
    return controls.filter(function (control) { return control.value !== ''; }).map(function (control) {
      return { field: control.dataset.nmFilter, exact: control.tagName === 'SELECT', value: control.tagName === 'SELECT' ? JSON.parse(control.value) : control.value };
    });
  }
  controls.forEach(function (control) {
    if (control.tagName === 'SELECT') groups(rows, control.dataset.nmFilter).forEach(function (group) {
      var option = doc.createElement('option'); option.value = JSON.stringify(group.value); option.textContent = group.label; control.appendChild(option);
    });
    control.addEventListener(control.tagName === 'SELECT' ? 'change' : 'input', refresh);
  });
  function syncMaster() {
    var master = table.querySelector('[data-ns-report-select-all]');
    if (!master) return;
    var inputs = visible.map(function (index) { return rowNodes.get(index).querySelector('[data-ns-report-add]'); }).filter(Boolean);
    var checked = inputs.filter(function (input) { return input.checked; }).length;
    master.checked = inputs.length > 0 && checked === inputs.length;
    master.indeterminate = checked > 0 && checked < inputs.length;
  }
  function refresh() {
    var filters = selectedFilters(); visible = [];
    rows.forEach(function (row, index) { var show = matches(row, filters, search.value); rowNodes.get(index).hidden = !show; if (show) visible.push(index); });
    var data = visible.map(function (index) { return rows[index]; });
    summary = filters.map(function (filter) { return (cfg.columns[filter.field] || filter.field) + ': ' + (filter.value || 'Sin dato'); }).join(' · ');
    if (search.value.trim()) summary += (summary ? ' · ' : '') + 'Buscar: ' + search.value.trim();
    summary = summary || 'Sin filtros';
    doc.getElementById('nmFilterSummary').textContent = summary;
    doc.getElementById('nmVisible').textContent = number.format(data.length);
    doc.getElementById('nmWithKey').textContent = number.format(data.filter(function (row) { return text(row['YT:LLAVE']) !== ''; }).length);
    doc.getElementById('nmNoZone').textContent = number.format(data.filter(function (row) { return row.ZONA === 'Sin zona' || row.ZONA === 'Zona ambigua'; }).length);
    doc.getElementById('nmEmpty').hidden = data.length > 0;
    charts.forEach(function (item) {
      item.groups = groups(data, item.field);
      item.chart.data.labels = item.groups.map(function (group) { return group.label; });
      item.chart.data.datasets[0].data = item.groups.map(function (group) { return group.count; });
      item.chart.data.datasets[0].backgroundColor = item.groups.map(function (group) { return colors[item.allValues.indexOf(group.value) % colors.length]; });
      item.chart.update('none');
      item.empty.hidden = data.length > 0;
      if (item.button) item.button.disabled = item.busy || !data.length;
    });
    syncMaster();
  }
  function initCharts() {
    charts.forEach(function (item) { item.chart.destroy(); }); charts = [];
    if (typeof root.Chart === 'undefined') { announce('No se cargó Chart.js. La grilla sigue disponible; los gráficos no se pueden agregar.'); return; }
    var type = doc.getElementById('nmChartType').value;
    Array.from(doc.querySelectorAll('[data-nm-chart-field]')).forEach(function (card) {
      var item = { field: card.dataset.nmChartField, empty: card.querySelector('.nmChartEmpty'), button: card.querySelector('[data-nm-report-chart]'), busy: false };
      item.allValues = groups(rows, item.field).map(function (group) { return group.value; });
      item.chart = new root.Chart(card.querySelector('canvas'), {
        type: type,
        data: { labels: [], datasets: [{ label: 'Pozos', data: [], backgroundColor: colors, borderWidth: 1 }] },
        options: {
          responsive: true, maintainAspectRatio: false, animation: false,
          plugins: { legend: { display: type === 'doughnut', position: 'bottom' }, tooltip: { callbacks: {
            label: function (ctx) { var value = Number(ctx.raw) || 0, total = visible.length; return ctx.label + ': ' + number.format(value) + ' pozos (' + (total ? (value * 100 / total).toFixed(1) : '0') + '%)'; }
          } } },
          scales: type === 'bar' ? { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { ticks: { maxRotation: 45 } } } : {},
          onClick: function (event, points) {
            if (!points.length) return;
            var value = JSON.stringify(item.groups[points[0].index].value), control = controls.find(function (node) { return node.dataset.nmFilter === item.field; });
            if (control) { control.value = control.value === value ? '' : value; refresh(); }
          }
        }
      });
      if (item.button) item.button.onclick = function () {
        if (item.busy || !visible.length || !root.CLEAR_NS_REPORT) return;
        item.busy = true; item.button.disabled = true;
        var payload = { kind: 'chart', chart_type: type, unit: 'pozos', axis_label: item.field, palette: 'multicolor',
          labels: item.groups.map(function (group) { return group.label; }),
          datasets: [{ label: 'Pozos', values: item.groups.map(function (group) { return group.count; }) }],
          context: 'Fuente BM_RTQP; señales actuales sin clasificación PUMP OFF. Consulta: ' + cfg.queriedAt + ' ' + cfg.timezone + '. Filtros: ' + summary,
          image_data: item.chart.toBase64Image('image/png', 1) };
        root.CLEAR_NS_REPORT.addItems([{ key: cfg.chartKeys[item.field], type: 'chart', title: 'BM actual · ' + item.field + ' · ' + visible.length + ' pozos', payload: payload }])
          .then(function () { announce('Gráfico agregado al reporte con los filtros de esta captura.'); })
          .catch(function (error) { announce(error.message || 'No se pudo agregar el gráfico.'); })
          .then(function () { item.busy = false; item.button.disabled = !visible.length; });
      };
      charts.push(item);
    });
  }
  Array.from(doc.querySelectorAll('[data-nm-sort]')).forEach(function (button) {
    var ascending = false;
    button.addEventListener('click', function () {
      ascending = !ascending; var field = button.dataset.nmSort;
      Array.from(rowNodes.keys()).sort(function (a, b) {
        return text(rows[a][field]).localeCompare(text(rows[b][field]), 'es', { numeric: true }) * (ascending ? 1 : -1);
      }).forEach(function (index) { table.tBodies[0].appendChild(rowNodes.get(index)); });
      table.tBodies[0].appendChild(doc.getElementById('nmEmpty'));
      Array.from(table.querySelectorAll('th[aria-sort]')).forEach(function (th) { th.removeAttribute('aria-sort'); });
      button.closest('th').setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
    });
  });
  search.addEventListener('input', refresh);
  doc.getElementById('nmReset').addEventListener('click', function () { search.value = ''; controls.forEach(function (control) { control.value = ''; }); refresh(); });
  doc.getElementById('nmChartType').addEventListener('change', function () { initCharts(); refresh(); });
  table.addEventListener('change', syncMaster);
  root.addEventListener('clear-report-selection-loaded', syncMaster);
  var selectedButton = doc.getElementById('nmAddSelected');
  if (selectedButton) selectedButton.addEventListener('click', function () {
    var inputs = visible.map(function (index) { return rowNodes.get(index).querySelector('[data-ns-report-add]'); }).filter(function (input) { return input && input.checked && !input.disabled; });
    if (!inputs.length) { announce('Marcá al menos un pozo visible.'); return; }
    var items = inputs.map(function (input) {
      return { key: input.dataset.reportKey, type: 'row', title: input.dataset.reportTitle,
        payload: JSON.parse(decodeURIComponent(escape(root.atob(input.dataset.reportPayload)))) };
    });
    selectedButton.disabled = true;
    root.CLEAR_NS_REPORT.addItems(items).then(function (result) { announce(result.message); })
      .catch(function (error) { announce(error.message || 'No se pudo completar la selección.'); })
      .then(function () { selectedButton.disabled = false; syncMaster(); });
  });
  doc.getElementById('nmExport').addEventListener('click', function () {
    var fields = Array.from(table.tHead.rows[0].cells).filter(function (cell) { return cell.dataset.columnKey !== 'report' && cell.style.display !== 'none'; })
      .map(function (cell) { return Object.keys(cfg.columns)[Number(cell.dataset.columnKey.slice(1))]; });
    if (!fields.length) { announce('Mostrá al menos una columna para exportar.'); return; }
    var lines = [['Origen', 'BM_RTQP · lectura actual'], ['Consulta', cfg.queriedAt, cfg.timezone], ['Filtros', summary], fields.map(function (key) { return cfg.columns[key]; })];
    Array.from(table.tBodies[0].querySelectorAll('[data-nm-row]')).filter(function (node) { return !node.hidden; }).forEach(function (node) {
      var row = rows[Number(node.dataset.nmRow)]; lines.push(fields.map(function (field) { return row[field]; }));
    });
    var csv = '\uFEFF' + lines.map(function (line) { return line.map(csvCell).join(';'); }).join('\r\n');
    var url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })), link = doc.createElement('a');
    link.href = url; link.download = 'CLEAR_Monitoreo_BM_' + cfg.queriedAt.slice(0,10) + '.csv'; doc.body.appendChild(link); link.click(); link.remove();
    root.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  });
  initCharts(); refresh();
})(typeof window !== 'undefined' ? window : globalThis);
