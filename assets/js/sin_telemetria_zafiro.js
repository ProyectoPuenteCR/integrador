/* =============================================================
   CLEAR — Sin telemetría en Zafiro
   Gráfico semanal + orden y filtros rápidos de la grilla.
============================================================= */
(function () {
  'use strict';
  var cfg = window.CLEAR_ZST || {};

  function cssVar(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name);
    return (v && v.trim()) || fallback;
  }

  /* ---------- Gráfico ---------- */
  var canvas = document.getElementById('zstWeeklyChart');
  if (canvas && window.Chart) {
    var red = cssVar('--red', '#e63329');
    var redSoft = cssVar('--red-soft', '#fdeceb');
    var green = cssVar('--green', '#0f8a5f');
    var amber = cssVar('--amber', '#d98a1a');
    var text = cssVar('--text', '#15303a');
    var mut = cssVar('--text-mut', '#8a99a8');
    var line = cssVar('--line-mid', '#e2e9ee');
    var selected = cfg.selectedWeek || '';

    var barColors = (cfg.weeks || []).map(function (w) { return w === selected ? red : redSoft; });
    var barBorders = (cfg.weeks || []).map(function () { return red; });

    /* Valor encima de cada barra. */
    var valueLabels = {
      id: 'zstValueLabels',
      afterDatasetsDraw: function (chart) {
        var meta = chart.getDatasetMeta(0);
        var ctx = chart.ctx;
        ctx.save();
        ctx.font = '700 12px Inter, "Segoe UI", sans-serif';
        ctx.fillStyle = text;
        ctx.textAlign = 'center';
        meta.data.forEach(function (bar, i) {
          var value = chart.data.datasets[0].data[i];
          if (value === null || value === undefined) return;
          ctx.fillText(String(value), bar.x, bar.y - 8);
        });
        ctx.restore();
      }
    };

    var chart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: cfg.labels || [],
        datasets: [
          { type: 'bar', label: 'Sin telemetría', data: cfg.counts || [], backgroundColor: barColors, borderColor: barBorders, borderWidth: 1, borderRadius: 4, maxBarThickness: 64, order: 3 },
          { type: 'line', label: 'Normalizados', data: cfg.normalized || [], borderColor: green, backgroundColor: green, pointRadius: 4, pointHoverRadius: 6, tension: 0.25, spanGaps: true, order: 1 },
          { type: 'line', label: 'Nuevos', data: cfg.newWells || [], borderColor: amber, backgroundColor: amber, borderDash: [5, 4], pointRadius: 4, pointHoverRadius: 6, tension: 0.25, spanGaps: true, order: 2 }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { top: 22 } },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'bottom', labels: { color: mut, boxWidth: 12, usePointStyle: true } },
          tooltip: {
            callbacks: {
              title: function (items) {
                var l = items[0] && items[0].label;
                return Array.isArray(l) ? l.join(' · ') : String(l || '');
              },
              label: function (item) {
                var v = item.raw;
                return ' ' + item.dataset.label + ': ' + (v === null || v === undefined ? 'sin captura' : v);
              }
            }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: mut } },
          y: { beginAtZero: true, grid: { color: line }, ticks: { color: mut, precision: 0 }, title: { display: true, text: 'Cantidad de pozos', color: mut } }
        },
        onHover: function (event, elements) {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: function (event, elements) {
          if (!elements.length) return;
          var week = (cfg.weeks || [])[elements[0].index];
          if (!week || week === selected) return;
          var base = cfg.baseUrl || 'sin_telemetria_zafiro.php';
          window.location.href = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'semana=' + encodeURIComponent(week);
        }
      },
      plugins: [valueLabels]
    });
    window.CLEAR_ZST_CHART = chart;
  }

  /* ---------- Grilla ---------- */
  var table = document.getElementById('zstTable');
  if (!table) return;
  var tbody = table.tBodies[0];
  var rows = Array.prototype.slice.call(tbody.rows).filter(function (r) { return r.cells.length > 1; });
  var visibleCounter = document.querySelector('[data-zst-visible]');

  function cellValue(row, index) {
    var cell = row.cells[index];
    return cell ? (cell.getAttribute('data-v') || cell.textContent || '').trim() : '';
  }

  /* Opciones de los combos a partir de los valores presentes. */
  Array.prototype.slice.call(table.querySelectorAll('select[data-zst-filter]')).forEach(function (select) {
    var index = parseInt(select.getAttribute('data-zst-filter'), 10);
    var values = {};
    rows.forEach(function (row) { var v = cellValue(row, index); if (v !== '') values[v] = true; });
    Object.keys(values).sort(function (a, b) { return a.localeCompare(b, 'es', { numeric: true }); }).forEach(function (v) {
      var option = document.createElement('option');
      option.value = v; option.textContent = v;
      select.appendChild(option);
    });
  });

  function applyFilters() {
    var active = Array.prototype.slice.call(table.querySelectorAll('[data-zst-filter]')).map(function (el) {
      return { index: parseInt(el.getAttribute('data-zst-filter'), 10), value: el.value.trim().toLowerCase(), exact: el.tagName === 'SELECT' };
    }).filter(function (f) { return f.value !== ''; });
    var visible = 0;
    rows.forEach(function (row) {
      var ok = active.every(function (f) {
        var v = cellValue(row, f.index).toLowerCase();
        return f.exact ? v === f.value : v.indexOf(f.value) >= 0;
      });
      row.hidden = !ok;
      if (ok) visible++;
    });
    if (visibleCounter) visibleCounter.textContent = visible.toLocaleString('es-AR');
  }
  Array.prototype.slice.call(table.querySelectorAll('[data-zst-filter]')).forEach(function (el) {
    el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', applyFilters);
  });

  /* Orden por columna. */
  var sortState = { index: -1, dir: 1 };
  Array.prototype.slice.call(table.querySelectorAll('[data-zst-sort]')).forEach(function (button) {
    button.addEventListener('click', function () {
      var index = parseInt(button.getAttribute('data-zst-sort'), 10);
      var numeric = button.getAttribute('data-type') === 'number';
      sortState.dir = sortState.index === index ? -sortState.dir : 1;
      sortState.index = index;
      rows.sort(function (a, b) {
        var x = cellValue(a, index), y = cellValue(b, index);
        var r = numeric ? (parseFloat(x) || 0) - (parseFloat(y) || 0) : x.localeCompare(y, 'es', { numeric: true, sensitivity: 'base' });
        return r * sortState.dir;
      });
      rows.forEach(function (row) { tbody.appendChild(row); });
      Array.prototype.slice.call(table.querySelectorAll('[data-zst-sort]')).forEach(function (b) {
        b.classList.remove('is-sorted');
        b.querySelector('span').textContent = '↕';
      });
      button.classList.add('is-sorted');
      button.querySelector('span').textContent = sortState.dir === 1 ? '↑' : '↓';
    });
  });

  /* Seleccionar todos (solo filas visibles). */
  var checkAll = table.querySelector('[data-zst-check-all]');
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      rows.forEach(function (row) {
        if (row.hidden) return;
        var input = row.querySelector('input[type="checkbox"]');
        if (input && input.checked !== checkAll.checked) {
          input.checked = checkAll.checked;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    });
  }
})();
