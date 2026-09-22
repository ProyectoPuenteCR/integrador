(function () {
  'use strict';

  var table = document.getElementById('commentsTable');
  if (!table || !table.tBodies.length) return;

  var headers = Array.prototype.slice.call(table.querySelectorAll('thead th[data-column]'));
  var state = { key: '', direction: '' };

  function valueFor(row, key) {
    var cell = row.querySelector('[data-column="' + key + '"]');
    if (!cell) return '';
    var value = (cell.textContent || '').trim();
    if (key === 'updated') {
      var match = value.match(/^(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2}))?/);
      if (match) return match[3] + match[2] + match[1] + (match[4] || '00') + (match[5] || '00');
    }
    return value.toLocaleLowerCase('es');
  }

  function sortBy(header) {
    var key = header.dataset.column;
    var direction = state.key === key && state.direction === 'asc' ? 'desc' : 'asc';
    state = { key: key, direction: direction };

    headers.forEach(function (item) {
      item.removeAttribute('data-sort-direction');
      item.setAttribute('aria-sort', 'none');
    });
    header.dataset.sortDirection = direction;
    header.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');

    var tbody = table.tBodies[0];
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-comment-details]'));
    rows.sort(function (a, b) {
      return valueFor(a, key).localeCompare(valueFor(b, key), 'es', {
        numeric: true,
        sensitivity: 'base'
      }) * (direction === 'asc' ? 1 : -1);
    });
    rows.forEach(function (row) { tbody.appendChild(row); });
  }

  headers.forEach(function (header) {
    if (header.dataset.column === 'report') return;
    header.classList.add('commentsSortable');
    header.setAttribute('aria-sort', 'none');
    header.title = 'Clic para ordenar; arrastrá el borde para cambiar el ancho';
    header.addEventListener('click', function (event) {
      if (!event.target.closest('.commentsResizeHandle')) sortBy(header);
    });
  });
})();
