(function () {
  'use strict';
  var table = document.getElementById('allAlarmsTable');
  var chooser = document.getElementById('columnChooser');
  var chooserButton = document.getElementById('columnChooserButton');
  var exportButton = document.getElementById('exportAllAlarmsExcel');
  var toggles = Array.prototype.slice.call(document.querySelectorAll('[data-column-toggle]'));
  var storageKey = 'clear:todasAlarmas:visibleColumns:v1';
  var columns = window.CLEAR_ALL_ALARMS_COLUMNS || [];

  function readVisible() {
    try {
      var raw = localStorage.getItem(storageKey);
      if (!raw) return columns.map(function (_, i) { return i; });
      var parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed.map(Number).filter(function (i) { return i >= 0 && i < columns.length; }) : [];
    } catch (e) { return columns.map(function (_, i) { return i; }); }
  }
  function saveVisible(indices) { try { localStorage.setItem(storageKey, JSON.stringify(indices)); } catch (e) {} }
  function applyColumns(indices) {
    var visible = {};
    indices.forEach(function (i) { visible[i] = true; });
    document.querySelectorAll('[data-column-index]').forEach(function (cell) {
      var index = Number(cell.getAttribute('data-column-index'));
      cell.hidden = !visible[index];
    });
    toggles.forEach(function (toggle) {
      toggle.checked = !!visible[Number(toggle.getAttribute('data-column-toggle'))];
    });
    saveVisible(indices);
  }
  function selectedIndices() {
    return toggles.filter(function (t) { return t.checked; }).map(function (t) { return Number(t.getAttribute('data-column-toggle')); });
  }

  if (chooserButton && chooser) chooserButton.addEventListener('click', function () { chooser.hidden = !chooser.hidden; });
  toggles.forEach(function (toggle) { toggle.addEventListener('change', function () {
    var selected = selectedIndices();
    if (!selected.length) { toggle.checked = true; selected = selectedIndices(); }
    applyColumns(selected);
  }); });
  var showAll = document.getElementById('showAllColumns');
  if (showAll) showAll.addEventListener('click', function () { applyColumns(columns.map(function (_, i) { return i; })); });
  var compact = document.getElementById('hideOptionalColumns');
  if (compact) compact.addEventListener('click', function () {
    var important = ['ALM_NATIVETIMEIN','ALM_NATIVETIMELAST','ALM_TAGNAME','ALM_TAGDESC','ALM_VALUE','ALM_UNIT','ALM_ALMPRIORITY','ALM_LOGNODENAME','ALM_PHYSNODE'];
    var indices = [];
    columns.forEach(function (column, i) { if (important.indexOf(String(column).toUpperCase()) !== -1) indices.push(i); });
    applyColumns(indices.length ? indices : columns.slice(0, Math.min(8, columns.length)).map(function (_, i) { return i; }));
  });
  applyColumns(readVisible());

  if (exportButton) exportButton.addEventListener('click', function () {
    var form = document.getElementById('allAlarmsFilters');
    var params = new URLSearchParams(new FormData(form));
    var visibleColumns = selectedIndices().map(function (i) { return columns[i]; }).filter(Boolean);
    params.set('columns', visibleColumns.join(','));
    window.location.href = 'todas_alarmas_export.php?' + params.toString();
  });

  var from = document.getElementById('allAlarmDateFrom');
  var to = document.getElementById('allAlarmDateTo');
  var fromTime = document.getElementById('allAlarmTimeFrom');
  var toTime = document.getElementById('allAlarmTimeTo');
  function syncDates() {
    if (from && from.value && fromTime && !fromTime.value) fromTime.value = '00:00';
    if (to && to.value && toTime && !toTime.value) toTime.value = '23:59';
    if (from && to) { from.max = to.value || ''; to.min = from.value || ''; }
  }
  [from,to,fromTime,toTime].forEach(function (el) { if (el) el.addEventListener('change', syncDates); });
  syncDates();
})();
