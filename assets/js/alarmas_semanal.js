(function () {
  'use strict';
  var cfg = window.CLEAR_WEEKLY_ALARMS || {};

  var toolbar = document.getElementById('asToolbar');
  var weekDate = document.getElementById('asWeekDate');
  if (toolbar && weekDate) weekDate.addEventListener('change', function () {
    if (weekDate.value) toolbar.submit();
  });

  var installationTypeSelect = document.querySelector('select[name="tipo_instalacion"]');
  if (installationTypeSelect) installationTypeSelect.addEventListener('change', function () {
    var filterForm = document.getElementById('asGridFilters');
    if (filterForm) filterForm.submit();
  });

  function sortableValue(cell, type) {
    var raw = String(cell.getAttribute('data-sort-value') || cell.textContent || '').trim();
    if (type === 'number') {
      var cleaned = raw.replace(/[^0-9,.-]/g, '');
      if (cleaned.indexOf(',') >= 0 && cleaned.indexOf('.') >= 0) cleaned = cleaned.lastIndexOf(',') > cleaned.lastIndexOf('.') ? cleaned.replace(/\./g, '').replace(',', '.') : cleaned.replace(/,/g, '');
      else if (cleaned.indexOf(',') >= 0) cleaned = cleaned.replace(',', '.');
      var value = Number(cleaned);
      return Number.isFinite(value) ? value : Number.NEGATIVE_INFINITY;
    }
    return raw.toLocaleLowerCase('es-AR');
  }

  var sortButtons = Array.prototype.slice.call(document.querySelectorAll('.asSortButton'));
  sortButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      var table = button.closest('table');
      var tbody = table && table.tBodies.length ? table.tBodies[0] : null;
      if (!tbody) return;
      var rows = Array.prototype.slice.call(tbody.rows).filter(function (row) { return !row.querySelector('.asEmpty'); });
      if (rows.length < 2) return;
      var column = Number(button.getAttribute('data-sort-column'));
      var type = button.getAttribute('data-sort-type') || 'text';
      var direction = button.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
      sortButtons.forEach(function (other) { other.classList.remove('is-active'); other.removeAttribute('aria-sort'); var mark=other.querySelector('span'); if(mark)mark.textContent='↕'; });
      button.classList.add('is-active');button.setAttribute('aria-sort', direction);
      var marker = button.querySelector('span');if(marker)marker.textContent=direction === 'ascending' ? '↑' : '↓';
      rows.forEach(function (row,index) { row._asOriginalIndex=index; });
      rows.sort(function (left,right) {
        var a=sortableValue(left.cells[column],type), b=sortableValue(right.cells[column],type);
        var compared = type === 'number' ? a-b : String(a).localeCompare(String(b),'es-AR',{numeric:true,sensitivity:'base'});
        if (!compared) compared=left._asOriginalIndex-right._asOriginalIndex;
        return direction === 'ascending' ? compared : -compared;
      });
      var fragment=document.createDocumentFragment();rows.forEach(function(row){fragment.appendChild(row);});tbody.appendChild(fragment);
    });
  });

  if (!cfg.dailyLabels || typeof Chart === 'undefined') return;

  var petrol = '#1a4d5c';
  var petrolHover = '#236779';
  var red = '#e63329';
  var redHover = '#bf251e';
  var grid = 'rgba(138,153,168,.18)';
  var text = '#647786';
  var number = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 1 });

  function navigateFilter(key, value) {
    var url = new URL(window.location.href);
    if (String(url.searchParams.get(key) || '') === String(value)) url.searchParams.delete(key);
    else url.searchParams.set(key, value);
    url.searchParams.delete('pagina');
    window.location.href = cfg.page + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '');
  }

  function commonOptions() {
    return {
      responsive: true,
      maintainAspectRatio: false,
      layout: { padding: { top: 14 } },
      animation: { duration: 280 },
      interaction: { intersect: true, mode: 'nearest' },
      plugins: {
        legend: { display: false },
        tooltip: {
          displayColors: false,
          callbacks: { label: function (ctx) { return number.format(ctx.parsed.y) + ' alarmas'; } }
        }
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: text, font: { size: 10, weight: '600' } }, border: { color: grid } },
        y: { beginAtZero: true, grid: { color: grid }, ticks: { color: text, font: { size: 10 }, callback: function (v) { return number.format(v); } }, border: { display: false } }
      }
    };
  }

  var dailyColors = cfg.dailyDates.map(function (date) { return date === cfg.selectedDay ? red : petrol; });
  var dailyHover = cfg.dailyDates.map(function (date) { return date === cfg.selectedDay ? redHover : petrolHover; });
  var dailyOptions = commonOptions();
  dailyOptions.onClick = function (event, elements) {
    if (!elements.length) return;
    navigateFilter('dia', cfg.dailyDates[elements[0].index]);
  };
  dailyOptions.onHover = function (event, elements) { event.native.target.style.cursor = elements.length ? 'pointer' : 'default'; };
  dailyOptions.plugins.tooltip.callbacks.title = function (items) { return items.length ? cfg.dailyLabels[items[0].dataIndex] : ''; };
  var dailyValueLabels = {
    id: 'dailyValueLabels',
    afterDatasetsDraw: function (chart) {
      var context = chart.ctx;
      context.save();
      context.textAlign = 'center';
      context.textBaseline = 'bottom';
      context.font = '700 10px Inter, Segoe UI, sans-serif';
      chart.getDatasetMeta(0).data.forEach(function (bar, index) {
        var value = Number(cfg.dailyValues[index] || 0);
        if (!value) return;
        context.fillStyle = cfg.dailyDates[index] === cfg.selectedDay ? red : '#15303a';
        context.fillText(number.format(value), bar.x, Math.max(12, bar.y - 5));
      });
      context.restore();
    }
  };
  new Chart(document.getElementById('asDailyChart'), {
    type: 'bar',
    data: { labels: cfg.dailyLabels, datasets: [{ data: cfg.dailyValues, backgroundColor: dailyColors, hoverBackgroundColor: dailyHover, borderRadius: 7, borderSkipped: false, maxBarThickness: 54 }] },
    options: dailyOptions,
    plugins: [dailyValueLabels]
  });

  var sorted = cfg.hourValues.slice().filter(function (v) { return Number(v) > 0; }).sort(function (a,b) { return b-a; });
  var peakCut = sorted.length >= 4 ? Number(sorted[Math.min(3, sorted.length-1)]) : Number.POSITIVE_INFINITY;
  var hourlyColors = cfg.hourValues.map(function (value, index) {
    return String(index) === String(cfg.selectedHour) || Number(value) >= peakCut ? red : petrol;
  });
  var hourlyOptions = commonOptions();
  hourlyOptions.onClick = function (event, elements) {
    if (!elements.length) return;
    navigateFilter('hora', String(elements[0].index));
  };
  hourlyOptions.onHover = function (event, elements) { event.native.target.style.cursor = elements.length ? 'pointer' : 'default'; };
  hourlyOptions.plugins.tooltip.callbacks.title = function (items) { return items.length ? cfg.hourLabels[items[0].dataIndex] + ':00' : ''; };
  new Chart(document.getElementById('asHourlyChart'), {
    type: 'bar',
    data: { labels: cfg.hourLabels, datasets: [{ data: cfg.hourValues, backgroundColor: hourlyColors, hoverBackgroundColor: hourlyColors, borderRadius: 3, borderSkipped: false, maxBarThickness: 24 }] },
    options: hourlyOptions
  });

  var clearDay = document.querySelector('[data-clear-chart-filter]');
  if (clearDay) clearDay.addEventListener('click', function () { navigateFilter('dia', cfg.selectedDay); });
  var clearHour = document.querySelector('[data-clear-hour-filter]');
  if (clearHour) clearHour.addEventListener('click', function () { navigateFilter('hora', cfg.selectedHour); });

  var perPage = document.getElementById('asPerPage');
  if (perPage) perPage.addEventListener('change', function () {
    var url = new URL(window.location.href);
    url.searchParams.set('por_pagina', perPage.value);
    url.searchParams.delete('pagina');
    window.location.href = cfg.page + '?' + url.searchParams.toString();
  });

  var refresh = document.getElementById('asRefresh');
  if (refresh) {
    var current = new URL(window.location.href).searchParams.get('refresh') || '0';
    refresh.value = current;
    var seconds = parseInt(current, 10) || 0;
    if (seconds > 0) window.setTimeout(function () { window.location.reload(); }, seconds * 1000);
  }
})();
