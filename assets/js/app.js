/* =============================================================
   CLEAR PLATAFORMA — app.js  (mínimo, sin dependencias)
============================================================= */
(function () {
  'use strict';

  // Filtro del menú lateral
  var filter = document.getElementById('menuFilter');
  var nav = document.getElementById('sideNav');
  if (filter && nav) {
    filter.addEventListener('input', function () {
      var q = filter.value.trim().toLowerCase();
      var links = nav.querySelectorAll('.side__link');
      links.forEach(function (a) {
        var txt = a.textContent.trim().toLowerCase();
        a.style.display = (q === '' || txt.indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }

  // Toggle sidebar en móvil (si agregás un botón con id="menuToggle")
  var toggle = document.getElementById('menuToggle');
  var side = document.getElementById('sidebar');
  if (toggle && side) {
    toggle.addEventListener('click', function () {
      side.classList.toggle('is-open');
    });
  }

  // Ordenamiento genérico para tablas estáticas.
  // Las listas principales usan ordenamiento por servidor desde list.php,
  // porque tienen paginación y exportación. Esta rutina cubre tablas internas
  // como administración de usuarios u otras vistas sin paginado SQL.
  function normalizarTexto(valor) {
    return (valor || '')
      .toString()
      .trim()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  }

  function parseValor(valor) {
    var raw = (valor || '').toString().trim();
    var clean = normalizarTexto(raw);

    // Fechas frecuentes del sistema: dd/mm/yyyy hh:mm:ss o yyyy-mm-dd.
    var fechaLatam = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?/);
    if (fechaLatam) {
      return {
        type: 'date',
        value: new Date(
          Number(fechaLatam[3]),
          Number(fechaLatam[2]) - 1,
          Number(fechaLatam[1]),
          Number(fechaLatam[4] || 0),
          Number(fechaLatam[5] || 0),
          Number(fechaLatam[6] || 0)
        ).getTime()
      };
    }

    var fechaIso = Date.parse(raw);
    if (!Number.isNaN(fechaIso) && /\d{4}-\d{1,2}-\d{1,2}/.test(raw)) {
      return { type: 'date', value: fechaIso };
    }

    // Números con formato local: 1.234,56 / 1234.56 / 1,234.56.
    var numericCandidate = raw.replace(/\s/g, '');
    if (/^-?[\d.,]+$/.test(numericCandidate)) {
      var normalized = numericCandidate;
      var lastComma = normalized.lastIndexOf(',');
      var lastDot = normalized.lastIndexOf('.');

      if (lastComma > lastDot) {
        normalized = normalized.replace(/\./g, '').replace(',', '.');
      } else if (lastDot > lastComma) {
        normalized = normalized.replace(/,/g, '');
      }

      var n = Number(normalized);
      if (!Number.isNaN(n)) return { type: 'number', value: n };
    }

    return { type: 'text', value: clean };
  }

  function initSortableTable(table) {
    var tbody = table.tBodies && table.tBodies[0];
    var headers = table.tHead ? Array.prototype.slice.call(table.tHead.querySelectorAll('th')) : [];
    if (!tbody || !headers.length) return;

    table.classList.add('grid--sortable');

    headers.forEach(function (th, index) {
      var hasAnchor = !!th.querySelector('a');
      var isDisabled = th.getAttribute('data-sortable') === 'false' || th.classList.contains('no-sort');
      if (hasAnchor || isDisabled) return;

      th.setAttribute('tabindex', '0');
      th.setAttribute('role', 'button');
      th.setAttribute('aria-sort', 'none');
      th.setAttribute('title', 'Ordenar por ' + th.textContent.trim());

      if (!th.querySelector('.sort-indicator')) {
        var indicator = document.createElement('span');
        indicator.className = 'sort-indicator';
        indicator.setAttribute('aria-hidden', 'true');
        indicator.textContent = '↕';
        th.appendChild(indicator);
      }

      function ordenar() {
        var current = th.getAttribute('data-sort-dir');
        var next = current === 'asc' ? 'desc' : 'asc';
        var dirFactor = next === 'asc' ? 1 : -1;

        headers.forEach(function (h) {
          h.classList.remove('is-sorted');
          h.removeAttribute('data-sort-dir');
          h.setAttribute('aria-sort', 'none');
          var si = h.querySelector('.sort-indicator');
          if (si && !h.querySelector('a')) si.textContent = '↕';
        });

        th.classList.add('is-sorted');
        th.setAttribute('data-sort-dir', next);
        th.setAttribute('aria-sort', next === 'asc' ? 'ascending' : 'descending');
        var sortIcon = th.querySelector('.sort-indicator');
        if (sortIcon) sortIcon.textContent = next === 'asc' ? '▲' : '▼';

        var rows = Array.prototype.slice.call(tbody.rows).filter(function (tr) {
          return tr.cells.length > index && !tr.querySelector('td[colspan]');
        });

        rows.sort(function (a, b) {
          var av = parseValor(a.cells[index].textContent);
          var bv = parseValor(b.cells[index].textContent);

          if (av.type === 'number' && bv.type === 'number') return (av.value - bv.value) * dirFactor;
          if (av.type === 'date' && bv.type === 'date') return (av.value - bv.value) * dirFactor;
          return av.value.localeCompare(bv.value, 'es', { numeric: true, sensitivity: 'base' }) * dirFactor;
        });

        rows.forEach(function (tr) { tbody.appendChild(tr); });
      }

      th.addEventListener('click', ordenar);
      th.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') {
          ev.preventDefault();
          ordenar();
        }
      });
    });
  }

  // Aplicar automáticamente los filtros de combo. Se usa delegación para
  // cubrir también los combos que pertenecen al formulario mediante el
  // atributo form="tableFilters" y las grillas cargadas dinámicamente.
  document.addEventListener('change', function (event) {
    var select = event.target.closest && event.target.closest('select[data-auto-submit="true"]');
    if (!select) return;
    var form = select.form || document.getElementById(select.getAttribute('form') || '');
    if (!form) return;
    var page = form.querySelector('[name="p"]');
    if (page) page.value = '1';
    // submit() evita que un campo opcional todavía incompleto bloquee el filtro.
    HTMLFormElement.prototype.submit.call(form);
  }, true);

  // Mantener coherente el rango de fechas antes de enviar el formulario.
  var fechaDesde = document.getElementById('fechaDesde');
  var fechaHasta = document.getElementById('fechaHasta');
  var horaDesde = document.getElementById('horaDesde');
  var horaHasta = document.getElementById('horaHasta');
  if (fechaDesde && fechaHasta) {
    function syncDateLimits() {
      if (horaDesde) {
        if (fechaDesde.value && !horaDesde.value) horaDesde.value = '00:00';
        if (!fechaDesde.value) horaDesde.value = '';
      }
      if (horaHasta) {
        if (fechaHasta.value && !horaHasta.value) horaHasta.value = '00:00';
        if (!fechaHasta.value) horaHasta.value = '';
      }

      fechaDesde.max = fechaHasta.value || '';
      fechaHasta.min = fechaDesde.value || '';

      var sameDay = fechaDesde.value && fechaHasta.value && fechaDesde.value === fechaHasta.value;
      if (horaDesde && horaHasta) {
        if (sameDay) {
          horaDesde.max = horaHasta.value || '';
          horaHasta.min = horaDesde.value || '';
        } else {
          horaDesde.max = '';
          horaHasta.min = '';
        }
      }
    }
    fechaDesde.addEventListener('change', syncDateLimits);
    fechaHasta.addEventListener('change', syncDateLimits);
    if (horaDesde && horaHasta) {
      horaDesde.addEventListener('change', syncDateLimits);
      horaHasta.addEventListener('change', syncDateLimits);
    }
    syncDateLimits();
  }


  // Autocompletado dinámico de TAG: consulta al servidor a medida que se escribe.
  (function initTagAutocomplete() {
    var input = document.querySelector('input[data-tag-autocomplete="true"]');
    var panel = document.getElementById('tagAutocomplete');
    var form = document.getElementById('tableFilters');
    if (!input || !panel || !form) return;

    var timer = null;
    var request = null;
    var activeIndex = -1;

    function closeSuggestions() {
      panel.hidden = true;
      panel.innerHTML = '';
      activeIndex = -1;
      input.setAttribute('aria-expanded', 'false');
    }

    function setActive(index) {
      var items = panel.querySelectorAll('.tagAutocomplete__item');
      items.forEach(function (item) { item.classList.remove('is-active'); });
      if (!items.length) {
        activeIndex = -1;
        return;
      }
      activeIndex = Math.max(0, Math.min(index, items.length - 1));
      items[activeIndex].classList.add('is-active');
      items[activeIndex].scrollIntoView({ block: 'nearest' });
    }

    function chooseTag(value) {
      input.value = value;
      closeSuggestions();
      form.submit();
    }

    function renderSuggestions(tags) {
      panel.innerHTML = '';
      activeIndex = -1;
      if (!tags || !tags.length) {
        var empty = document.createElement('div');
        empty.className = 'tagAutocomplete__empty';
        empty.textContent = 'No se encontraron tags coincidentes';
        panel.appendChild(empty);
      } else {
        tags.forEach(function (tag) {
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'tagAutocomplete__item';
          button.setAttribute('role', 'option');
          button.textContent = tag;
          button.addEventListener('mousedown', function (event) {
            event.preventDefault();
            chooseTag(tag);
          });
          panel.appendChild(button);
        });
      }
      panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function loadSuggestions() {
      var term = input.value.trim();
      if (term.length < 2) {
        closeSuggestions();
        return;
      }

      if (request) request.abort();
      request = new AbortController();

      var params = new URLSearchParams(new FormData(form));
      params.set('term', term);
      params.delete('q');
      params.delete('p');

      fetch('tag_suggest.php?' + params.toString(), {
        signal: request.signal,
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (response) {
          if (!response.ok) throw new Error('No se pudieron cargar las sugerencias');
          return response.json();
        })
        .then(function (data) {
          if (data && data.ok) renderSuggestions(data.tags || []);
          else closeSuggestions();
        })
        .catch(function (error) {
          if (error.name !== 'AbortError') closeSuggestions();
        });
    }

    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', 'tagAutocomplete');
    input.setAttribute('aria-expanded', 'false');

    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(loadSuggestions, 250);
    });
    input.addEventListener('focus', function () {
      if (input.value.trim().length >= 2) loadSuggestions();
    });
    input.addEventListener('keydown', function (event) {
      if (panel.hidden) return;
      var items = panel.querySelectorAll('.tagAutocomplete__item');
      if (event.key === 'ArrowDown' && items.length) {
        event.preventDefault();
        setActive(activeIndex + 1);
      } else if (event.key === 'ArrowUp' && items.length) {
        event.preventDefault();
        setActive(activeIndex <= 0 ? items.length - 1 : activeIndex - 1);
      } else if (event.key === 'Enter' && activeIndex >= 0 && items[activeIndex]) {
        event.preventDefault();
        chooseTag(items[activeIndex].textContent);
      } else if (event.key === 'Escape') {
        closeSuggestions();
      }
    });
    document.addEventListener('click', function (event) {
      if (!panel.contains(event.target) && event.target !== input) closeSuggestions();
    });
  })();

  // Panel lateral de detalle para las filas de todas las grillas.
  (function initDetailDrawer() {
    var drawer = document.getElementById('detailDrawer');
    var overlay = document.getElementById('detailDrawerOverlay');
    var closeButton = document.getElementById('detailDrawerClose');
    var title = document.getElementById('detailDrawerTitle');
    var body = document.getElementById('detailDrawerBody');
    if (!drawer || !overlay || !closeButton || !title || !body) return;

    function closeDrawer() {
      drawer.classList.remove('is-open');
      drawer.setAttribute('aria-hidden', 'true');
      overlay.hidden = true;
      document.body.classList.remove('has-detail-drawer');
    }

    function formatDate(value) {
      if (!value) return '—';
      var m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2}(?::\d{2})?)/);
      if (m) return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4];
      return String(value);
    }

    function updateReconButtons(tag, operator, count) {
      var buttons = document.querySelectorAll('[data-recon-open="true"]');
      buttons.forEach(function (btn) {
        if (btn.getAttribute('data-tag') !== tag || btn.getAttribute('data-operator') !== operator) return;
        var row = btn.closest('tr');
        if (row) row.setAttribute('data-recon-comment-count', String(count));
        var isReconocidas = row && row.getAttribute('data-recon-view') === 'reconocidas';
        btn.classList.toggle('has-comments', Number(count || 0) > 0);
        btn.setAttribute('title', Number(count || 0) > 0 ? 'Este registro tiene comentarios cargados' : 'Agregar comentario');
        var label = isReconocidas ? (count > 0 ? 'Comentar · ' + String(count) : 'Comentar') : (count > 0 ? String(count) + ' Ver' : 'Agregar');
        btn.innerHTML = '<span class="reconCommentsBtn__icon" aria-hidden="true">💬</span>' + label + (count > 0 ? '<span class="reconCommentsBtn__flag" aria-hidden="true" title="Tiene comentarios">●</span>' : '');
        var td = btn.closest('td[data-label]');
        if (td) td.setAttribute('data-raw-value', String(count));
      });
    }

    window.CLEAR_updateReconButtons = updateReconButtons;

    function appendReconCommentsSection(row) {
      var tag = row.getAttribute('data-recon-tag') || '';
      var operator = row.getAttribute('data-recon-operator') || '';
      var eventDate = row.getAttribute('data-recon-date') || '';
      var reconView = row.getAttribute('data-recon-view') || '';
      if (!tag || !operator) return;

      var field = document.createElement('div');
      field.className = 'detailDrawer__field detailDrawer__field--commentSection';
      var fieldLabel = document.createElement('div');
      fieldLabel.className = 'detailDrawer__label';
      fieldLabel.textContent = 'Comentario del reconocimiento';
      field.appendChild(fieldLabel);

      var wrap = document.createElement('div');
      wrap.className = 'detailReconComments';
      var status = document.createElement('div');
      status.className = 'detailReconComments__status is-visible is-loading';
      status.textContent = 'Cargando comentarios…';
      wrap.appendChild(status);
      field.appendChild(wrap);
      body.appendChild(field);

      function setStatus(message, type) {
        status.className = 'detailReconComments__status' + (message ? ' is-visible' : '') + (type ? ' is-' + type : '');
        status.textContent = message || '';
      }

      function openCommentsModal() {
        if (typeof window.CLEAR_reconCommentsOpen === 'function') {
          window.CLEAR_reconCommentsOpen(tag, operator, eventDate || '');
        }
      }

      function createSecondaryButton(textButton) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'detailReconComments__secondary';
        btn.textContent = textButton;
        return btn;
      }

      function renderAggregateView(items) {
        wrap.innerHTML = '';
        var note = document.createElement('div');
        note.className = 'detailReconComments__empty';
        var withComments = items.filter(function (item) { return item.comment; }).length;
        note.textContent = 'Este registro agrupa varios reconocimientos. Podés abrir el detalle para comentar un evento puntual.' + (withComments ? ' Ya hay ' + withComments + ' comentario(s) cargado(s).' : '');
        wrap.appendChild(note);
        var actions = document.createElement('div');
        actions.className = 'detailReconComments__actions';
        var btn = createSecondaryButton('Ver reconocimientos y comentar');
        btn.addEventListener('click', openCommentsModal);
        actions.appendChild(btn);
        wrap.appendChild(actions);
      }

      function renderEventView(item, options) {
        wrap.innerHTML = '';
        var preview = document.createElement('div');
        preview.className = 'detailReconComments__preview';
        preview.innerHTML = '<div class="detailReconComments__meta"><span><strong>Evento:</strong> ' + formatDate(item.event_date) + '</span><span><strong>Prioridad:</strong> ' + (item.priority || '—') + '</span><span><strong>Estado:</strong> ' + (item.status || '—') + '</span></div>';
        wrap.appendChild(preview);

        var form = document.createElement('form');
        form.className = 'detailReconComments__form';

        var reasonLabel = document.createElement('label');
        reasonLabel.textContent = 'Motivo';
        var reasonSelect = document.createElement('select');
        reasonSelect.required = true;
        var emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = 'Seleccionar motivo';
        reasonSelect.appendChild(emptyOption);
        (options || []).forEach(function (opt) {
          var op = document.createElement('option');
          op.value = opt;
          op.textContent = opt;
          if (item.reason === opt) op.selected = true;
          reasonSelect.appendChild(op);
        });
        reasonLabel.appendChild(reasonSelect);
        form.appendChild(reasonLabel);

        var commentLabel = document.createElement('label');
        commentLabel.textContent = 'Comentario';
        var commentArea = document.createElement('textarea');
        commentArea.required = true;
        commentArea.maxLength = 2000;
        commentArea.placeholder = 'Describí por qué se reconoció la alarma...';
        commentArea.value = item.comment || '';
        commentLabel.appendChild(commentArea);
        form.appendChild(commentLabel);

        var meta = document.createElement('div');
        meta.className = 'detailReconComments__hint';
        var metaParts = [];
        if (item.user) metaParts.push('Usuario: ' + item.user);
        if (item.created_at) metaParts.push('Carga: ' + formatDate(item.created_at));
        if (item.updated_at) metaParts.push('Última modificación: ' + formatDate(item.updated_at));
        meta.textContent = metaParts.join(' · ') || 'Sin comentario cargado todavía.';
        form.appendChild(meta);

        var actions = document.createElement('div');
        actions.className = 'detailReconComments__actions';
        var saveBtn = document.createElement('button');
        saveBtn.type = 'submit';
        saveBtn.className = 'detailReconComments__button';
        saveBtn.textContent = 'Guardar comentario';
        var historyBtn = createSecondaryButton('Ver todos los reconocimientos');
        historyBtn.addEventListener('click', openCommentsModal);
        actions.appendChild(saveBtn);
        actions.appendChild(historyBtn);
        form.appendChild(actions);
        wrap.appendChild(form);

        form.addEventListener('submit', function (evt) {
          evt.preventDefault();
          var reason = reasonSelect.value.trim();
          var comment = commentArea.value.trim();
          if (!reason || !comment) {
            setStatus('Motivo y comentario son obligatorios.', 'error');
            return;
          }
          if (reason === 'Otro' && comment.length < 5) {
            setStatus('Cuando el motivo es “Otro”, describí un poco más el comentario.', 'error');
            return;
          }
          saveBtn.disabled = true;
          setStatus('Guardando comentario…', 'loading');
          var data = new FormData();
          data.append('action', 'save_comment');
          data.append('tag', tag);
          data.append('operator', operator);
          data.append('event_key', item.event_key || '');
          data.append('event_date', item.event_date || '');
          data.append('description', item.description || '');
          data.append('priority', item.priority || '');
          data.append('reason', reason);
          data.append('comment', comment);
          fetch('reconocidas_usuario_api.php', { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
              return response.json().then(function (payload) { if (!response.ok) throw new Error(payload.error || 'No se pudo guardar'); return payload; });
            })
            .then(function (payload) {
              item.reason = reason;
              item.comment = comment;
              item.user = payload.user || item.user || '';
              item.updated_at = payload.saved_at || item.updated_at || '';
              if (!item.created_at) item.created_at = payload.saved_at || '';
              var updatedParts = [];
              if (item.user) updatedParts.push('Usuario: ' + item.user);
              if (item.created_at) updatedParts.push('Carga: ' + formatDate(item.created_at));
              if (item.updated_at) updatedParts.push('Última modificación: ' + formatDate(item.updated_at));
              meta.textContent = updatedParts.join(' · ');
              setStatus(payload.message || 'Comentario guardado.', 'ok');
              updateReconButtons(tag, operator, Number(payload.comment_count || 0));
            })
            .catch(function (error) {
              setStatus(error.message || 'No se pudo guardar el comentario.', 'error');
            })
            .finally(function () { saveBtn.disabled = false; });
        });
      }

      var params = new URLSearchParams({ action: 'details', tag: tag, operator: operator });
      fetch('reconocidas_usuario_api.php?' + params.toString(), { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (response) {
          return response.json().then(function (payload) { if (!response.ok) throw new Error(payload.error || 'No se pudo cargar'); return payload; });
        })
        .then(function (payload) {
          var items = Array.isArray(payload.items) ? payload.items : [];
          var options = Array.isArray(payload.reason_options) ? payload.reason_options : [];
          setStatus('', '');
          if (reconView !== 'reconocidas' || !eventDate) {
            renderAggregateView(items);
            return;
          }
          var match = null;
          for (var i = 0; i < items.length; i++) {
            if (String(items[i].event_date || '') === String(eventDate)) { match = items[i]; break; }
          }
          if (match) {
            renderEventView(match, options);
          } else {
            renderAggregateView(items);
          }
        })
        .catch(function (error) {
          setStatus(error.message || 'No se pudo cargar el comentario.', 'error');
          var actions = document.createElement('div');
          actions.className = 'detailReconComments__actions';
          var btn = createSecondaryButton('Abrir detalle completo');
          btn.addEventListener('click', openCommentsModal);
          actions.appendChild(btn);
          wrap.appendChild(actions);
        });
    }

    function openDrawer(row) {
      var cells = Array.prototype.slice.call(row.querySelectorAll('td[data-label]'));
      if (!cells.length) return;

      body.innerHTML = '';
      var drawerTitle = 'Detalle de alarma';

      cells.forEach(function (cell) {
        var label = cell.getAttribute('data-label') || 'Dato';
        var raw = cell.getAttribute('data-raw-value');
        var value = raw !== null && raw !== '' ? raw : cell.textContent.trim();
        if (/^tag$/i.test(label.trim()) && value) drawerTitle = value;

        var field = document.createElement('div');
        field.className = 'detailDrawer__field';
        var fieldLabel = document.createElement('div');
        fieldLabel.className = 'detailDrawer__label';
        fieldLabel.textContent = label;
        var fieldValue = document.createElement('div');
        fieldValue.className = 'detailDrawer__value';
        fieldValue.textContent = value || '—';
        field.appendChild(fieldLabel);
        field.appendChild(fieldValue);
        body.appendChild(field);
      });

      appendReconCommentsSection(row);

      title.textContent = drawerTitle;
      overlay.hidden = false;
      drawer.classList.add('is-open');
      drawer.setAttribute('aria-hidden', 'false');
      document.body.classList.add('has-detail-drawer');
      closeButton.focus();
    }

    document.addEventListener('click', function (event) {
      var row = event.target.closest && event.target.closest('tr.js-detail-row');
      if (!row || event.target.closest('a, button, input, select, textarea, label')) return;
      openDrawer(row);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && drawer.classList.contains('is-open')) closeDrawer();
      var row = event.target.closest && event.target.closest('tr.js-detail-row');
      if (row && (event.key === 'Enter' || event.key === ' ')) {
        event.preventDefault();
        openDrawer(row);
      }
    });
    closeButton.addEventListener('click', closeDrawer);
    overlay.addEventListener('click', closeDrawer);
  })();

  // Gráficos diarios del Top semanal.
  var weeklyChartInstances = [];

  function destroyWeeklyCharts() {
    weeklyChartInstances.forEach(function (chart) {
      if (chart && typeof chart.destroy === 'function') chart.destroy();
    });
    weeklyChartInstances = [];
  }

  function initWeeklyCharts() {
    var dataNode = document.getElementById('weeklyChartsData');
    var priorityCanvas = document.getElementById('weeklyPriorityChart');
    var totalCanvas = document.getElementById('weeklyTotalChart');
    if (!dataNode || !priorityCanvas || !totalCanvas || typeof Chart === 'undefined') return;

    var data;
    try {
      data = JSON.parse(dataNode.textContent || '{}');
    } catch (e) {
      return;
    }

    destroyWeeklyCharts();

    var labels = Array.isArray(data.labels) ? data.labels : [];
    var high = Array.isArray(data.high) ? data.high : [];
    var medium = Array.isArray(data.medium) ? data.medium : [];
    var low = Array.isArray(data.low) ? data.low : [];
    var total = Array.isArray(data.total) ? data.total : [];
    var numberFormatter = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 2 });

    function makeSharedOptions() {
      return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            position: 'bottom',
            labels: { usePointStyle: true, boxWidth: 9, boxHeight: 9, padding: 18 }
          },
          tooltip: {
            callbacks: {
              label: function (context) {
                var value = context.parsed && typeof context.parsed.y !== 'undefined' ? context.parsed.y : context.raw;
                return ' ' + context.dataset.label + ': ' + numberFormatter.format(Number(value || 0));
              }
            }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#617483' } },
          y: {
            beginAtZero: true,
            ticks: {
              color: '#617483',
              precision: 0,
              callback: function (value) { return numberFormatter.format(Number(value || 0)); }
            },
            grid: { color: 'rgba(189, 204, 214, 0.45)' }
          }
        }
      };
    }

    weeklyChartInstances.push(new Chart(priorityCanvas, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Alta',
            data: high,
            backgroundColor: 'rgba(239, 68, 68, 0.78)',
            borderColor: 'rgba(220, 38, 38, 1)',
            borderWidth: 1,
            borderRadius: 5,
            maxBarThickness: 34
          },
          {
            label: 'Media',
            data: medium,
            backgroundColor: 'rgba(245, 158, 11, 0.78)',
            borderColor: 'rgba(217, 119, 6, 1)',
            borderWidth: 1,
            borderRadius: 5,
            maxBarThickness: 34
          },
          {
            label: 'Baja',
            data: low,
            backgroundColor: 'rgba(16, 185, 129, 0.72)',
            borderColor: 'rgba(5, 150, 105, 1)',
            borderWidth: 1,
            borderRadius: 5,
            maxBarThickness: 34
          }
        ]
      },
      options: makeSharedOptions()
    }));

    weeklyChartInstances.push(new Chart(totalCanvas, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          label: 'Total diario',
          data: total,
          borderColor: 'rgba(23, 79, 94, 1)',
          backgroundColor: 'rgba(23, 79, 94, 0.12)',
          pointBackgroundColor: 'rgba(23, 79, 94, 1)',
          pointBorderColor: '#ffffff',
          pointBorderWidth: 2,
          pointRadius: 4,
          pointHoverRadius: 6,
          borderWidth: 3,
          fill: true,
          tension: 0.28
        }]
      },
      options: makeSharedOptions()
    }));
  }

  initWeeklyCharts();

  // Actualización automática sin recargar la página completa.
  (function initAutoRefresh() {
    var select = document.getElementById('autoRefreshSelect');
    var status = document.getElementById('autoRefreshStatus');
    if (!select) return;

    var pageKey = select.getAttribute('data-page-key') || 'list';
    var defaultSeconds = select.getAttribute('data-default-seconds') || '0';
    // Nueva clave: aplica el perfil de rendimiento sin conservar intervalos
    // agresivos de versiones anteriores. El usuario puede volver a elegir 1 min.
    var storageKey = 'clear:autoRefresh:performance-safe-v2:' + pageKey;
    var intervalId = null;
    var refreshing = false;

    function readStoredValue() {
      try {
        var storedValue = localStorage.getItem(storageKey);
        return storedValue !== null ? storedValue : defaultSeconds;
      } catch (e) { return defaultSeconds; }
    }

    function saveValue(value) {
      try { localStorage.setItem(storageKey, value); }
      catch (e) { /* El sitio sigue funcionando aunque localStorage esté bloqueado. */ }
    }

    function replaceElementFromDocument(id, nextDocument) {
      var current = document.getElementById(id);
      var next = nextDocument.getElementById(id);
      if (current && next) current.innerHTML = next.innerHTML;
    }

    function replaceOptionalBlock(id, nextDocument, insertAfterId) {
      var current = document.getElementById(id);
      var next = nextDocument.getElementById(id);
      if (current && next) {
        current.replaceWith(next);
      } else if (current && !next) {
        current.remove();
      } else if (!current && next) {
        var anchor = document.getElementById(insertAfterId);
        if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(next, anchor);
      }
    }

    function refreshContent() {
      if (refreshing || document.hidden) return;
      refreshing = true;
      if (status) status.textContent = 'Actualizando…';

      var tableArea = document.getElementById('listTableArea');
      var scrollBox = tableArea ? tableArea.querySelector('.tablescroll') : null;
      var scrollTop = scrollBox ? scrollBox.scrollTop : 0;
      var scrollLeft = scrollBox ? scrollBox.scrollLeft : 0;

      fetch(window.location.href, {
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (response) {
          if (!response.ok) throw new Error('Error HTTP ' + response.status);
          return response.text();
        })
        .then(function (html) {
          var nextDocument = new DOMParser().parseFromString(html, 'text/html');
          if (!nextDocument.getElementById('listTableArea')) throw new Error('Respuesta incompleta');

          replaceElementFromDocument('tableResultCount', nextDocument);
          replaceElementFromDocument('listTableArea', nextDocument);
          replaceOptionalBlock('alarmas24Summary', nextDocument, 'listTableArea');
          replaceOptionalBlock('reconocidasSummary', nextDocument, 'listTableArea');
          replaceOptionalBlock('top20Summary', nextDocument, 'listTableArea');
          replaceOptionalBlock('weeklyHmlSummary', nextDocument, 'listTableArea');
          initWeeklyCharts();

          var currentPager = document.getElementById('listPager');
          var nextPager = nextDocument.getElementById('listPager');
          if (currentPager && nextPager) currentPager.replaceWith(nextPager);
          else if (currentPager && !nextPager) currentPager.remove();
          else if (!currentPager && nextPager) {
            var newTableArea = document.getElementById('listTableArea');
            if (newTableArea && newTableArea.parentNode) newTableArea.parentNode.insertBefore(nextPager, newTableArea.nextSibling);
          }

          var updatedScrollBox = document.querySelector('#listTableArea .tablescroll');
          if (updatedScrollBox) {
            updatedScrollBox.scrollTop = scrollTop;
            updatedScrollBox.scrollLeft = scrollLeft;
          }
          if (status) {
            status.textContent = 'Actualizado ' + new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
          }
        })
        .catch(function () {
          if (status) status.textContent = 'No se pudo actualizar';
        })
        .finally(function () { refreshing = false; });
    }

    function configureInterval() {
      if (intervalId) clearInterval(intervalId);
      intervalId = null;
      var seconds = Number(select.value || 0);
      saveValue(String(seconds));
      if (status) status.textContent = seconds > 0 ? 'Automática activa' : '';
      if (seconds > 0) intervalId = setInterval(refreshContent, seconds * 1000);
    }

    var stored = readStoredValue();
    if (Array.prototype.some.call(select.options, function (option) { return option.value === stored; })) {
      select.value = stored;
    }
    select.addEventListener('change', configureInterval);
    configureInterval();
  })();


  // Vista rápida de PI Histórico al hacer clic sobre un TAG.
  (function initPiTagModal() {
    var modal = document.getElementById('piModal');
    var overlay = document.getElementById('piModalOverlay');
    var closeButton = document.getElementById('piModalClose');
    var frame = document.getElementById('piModalFrame');
    var openPageLink = document.getElementById('piModalOpenPage');
    if (!modal || !overlay || !closeButton || !frame || !openPageLink) return;

    function closeModal() {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      overlay.hidden = true;
      document.body.classList.remove('has-pi-modal');
      window.setTimeout(function () {
        if (modal.classList.contains('is-open')) return;
        frame.setAttribute('src', 'about:blank');
      }, 180);
    }

    function openModal(tag, query) {
      var params = new URLSearchParams();
      params.set('embedded', '1');
      if (tag) { params.set('tag', tag); params.set('alarm_tag', tag); }
      if (query) params.set('query', query);
      var src = 'pi_historico.php?' + params.toString();
      frame.setAttribute('src', src);
      var pageParams = new URLSearchParams();
      if (tag) { pageParams.set('tag', tag); pageParams.set('alarm_tag', tag); }
      if (query) pageParams.set('query', query);
      openPageLink.setAttribute('href', 'pi_historico.php' + (pageParams.toString() ? '?' + pageParams.toString() : ''));
      overlay.hidden = false;
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('has-pi-modal');
      closeButton.focus();
    }

    document.addEventListener('click', function (event) {
      var trigger = event.target.closest && event.target.closest('[data-pi-tag]');
      if (trigger) {
        event.preventDefault();
        event.stopPropagation();
        openModal(trigger.getAttribute('data-pi-tag') || '', trigger.getAttribute('data-pi-query') || '');
        return;
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });
    closeButton.addEventListener('click', closeModal);
    overlay.addEventListener('click', closeModal);
  })();


  // Detalle y comentarios de Reconocidas por usuario.
  (function initReconCommentsModal() {
    var modal = document.getElementById('reconCommentsModal');
    var overlay = document.getElementById('reconCommentsOverlay');
    var closeBtn = document.getElementById('reconCommentsClose');
    var summary = document.getElementById('reconCommentsSummary');
    var rowsBox = document.getElementById('reconCommentsRows');
    var statusBox = document.getElementById('reconCommentsStatus');
    var form = document.getElementById('reconCommentForm');
    var cancelBtn = document.getElementById('reconCommentCancel');
    if (!modal || !overlay || !closeBtn || !summary || !rowsBox || !form) return;

    var currentTag = '';
    var currentOperator = '';
    var currentItems = [];
    var reasonOptions = [];
    var preferredEventDate = '';

    function escapeHtml(value) {
      return String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function status(message, type) {
      statusBox.hidden = !message;
      statusBox.textContent = message || '';
      statusBox.className = 'reconCommentsStatus' + (type ? ' is-' + type : '');
    }

    function closeModal() {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      overlay.hidden = true;
      document.body.classList.remove('has-recon-comments-modal');
      form.hidden = true;
    }

    function formatDate(value) {
      if (!value) return '—';
      var m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2}(?::\d{2})?)/);
      if (m) return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4];
      return String(value);
    }

    function badge(value, type) {
      var v = String(value || '').trim();
      if (!v) return '<span class="badge badge--gray">—</span>';
      var u = v.toUpperCase();
      var cls = 'gray';
      if (type === 'priority') {
        if (u === 'HIGH' || u === 'ALTA' || u === 'CRITICAL' || u === 'CRITICA' || u === 'CRÍTICA') cls = 'red';
        else if (u === 'MED' || u === 'MEDIUM' || u === 'MEDIA') cls = 'amber';
        else if (u === 'LOW' || u === 'BAJA') cls = 'cyan';
      } else {
        if (['OK','NORMAL','RTN'].indexOf(u) !== -1) cls = 'green';
        else if (['ALARMA','ALARM','HI','LO','HIHI','LOLO'].indexOf(u) !== -1) cls = 'red';
      }
      return '<span class="badge badge--' + cls + '">' + escapeHtml(v) + '</span>';
    }

    function renderSummary(data) {
      var s = data.summary || {};
      summary.innerHTML = [
        ['Tag', s.tag || currentTag],
        ['Operador', s.operator || currentOperator],
        ['Reconocimientos', s.total || 0],
        ['Primer reconocimiento', formatDate(s.first_date)],
        ['Último reconocimiento', formatDate(s.last_date)]
      ].map(function (item) {
        return '<div class="reconCommentsSummary__item"><div class="reconCommentsSummary__label">' +
          escapeHtml(item[0]) + '</div><div class="reconCommentsSummary__value">' + escapeHtml(item[1]) + '</div></div>';
      }).join('');
    }

    function renderRows() {
      rowsBox.innerHTML = '';
      if (!currentItems.length) {
        rowsBox.innerHTML = '<tr><td colspan="6">No se encontraron reconocimientos para este TAG y operador.</td></tr>';
        return;
      }
      currentItems.forEach(function (item, index) {
        var tr = document.createElement('tr');
        tr.setAttribute('tabindex', '0');
        tr.setAttribute('data-index', String(index));
        tr.innerHTML = '<td>' + escapeHtml(formatDate(item.event_date)) + '</td>' +
          '<td class="comment-preview">' + escapeHtml(item.description || item.ack_text || '—') + '</td>' +
          '<td>' + badge(item.status, 'status') + '</td>' +
          '<td>' + badge(item.priority, 'priority') + '</td>' +
          '<td class="reason-value">' + escapeHtml(item.reason || 'Sin motivo') + '</td>' +
          '<td class="comment-preview">' + escapeHtml(item.comment || 'Sin comentario') + '</td>';
        tr.addEventListener('click', function () { selectItem(index, tr); });
        tr.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); selectItem(index, tr); }
        });
        rowsBox.appendChild(tr);
      });
    }

    function selectItem(index, row) {
      var item = currentItems[index];
      if (!item) return;
      rowsBox.querySelectorAll('tr').forEach(function (tr) { tr.classList.remove('is-selected'); });
      if (row) row.classList.add('is-selected');
      document.getElementById('reconEventKey').value = item.event_key || '';
      document.getElementById('reconTag').value = currentTag;
      document.getElementById('reconOperator').value = currentOperator;
      document.getElementById('reconEventDate').value = item.event_date || '';
      document.getElementById('reconDescription').value = item.description || '';
      document.getElementById('reconPriority').value = item.priority || '';
      var reason = document.getElementById('reconReason');
      reason.innerHTML = '<option value="">Seleccionar motivo</option>' + reasonOptions.map(function (option) {
        return '<option value="' + escapeHtml(option) + '">' + escapeHtml(option) + '</option>';
      }).join('');
      reason.value = item.reason || '';
      document.getElementById('reconComment').value = item.comment || '';
      var meta = [];
      if (item.user) meta.push('Usuario: ' + item.user);
      if (item.created_at) meta.push('Carga: ' + formatDate(item.created_at));
      if (item.updated_at) meta.push('Última modificación: ' + formatDate(item.updated_at));
      document.getElementById('reconCommentMeta').textContent = meta.join(' · ');
      form.hidden = false;
      form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function loadDetails(tag, operator, eventDate) {
      currentTag = tag;
      currentOperator = operator;
      preferredEventDate = eventDate || '';
      currentItems = [];
      summary.innerHTML = '';
      rowsBox.innerHTML = '';
      form.hidden = true;
      status('Cargando reconocimientos…', 'loading');

      var params = new URLSearchParams({ action: 'details', tag: tag, operator: operator });
      fetch('reconocidas_usuario_api.php?' + params.toString(), {
        cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (response) {
        return response.json().then(function (data) { if (!response.ok) throw new Error(data.error || 'No se pudo cargar'); return data; });
      }).then(function (data) {
        currentItems = data.items || [];
        reasonOptions = data.reason_options || [];
        renderSummary(data);
        renderRows();
        if (preferredEventDate && currentItems.length) {
          var preferredIndex = -1;
          for (var i = 0; i < currentItems.length; i++) {
            if (String(currentItems[i].event_date || '') === String(preferredEventDate)) { preferredIndex = i; break; }
          }
          if (preferredIndex >= 0) {
            var preferredRow = rowsBox.querySelector('tr[data-index="' + preferredIndex + '"]');
            selectItem(preferredIndex, preferredRow);
          }
        }
        status('', '');
      }).catch(function (error) {
        status(error.message || 'No se pudieron cargar los reconocimientos.', 'error');
      });
    }

    function openModal(tag, operator, eventDate) {
      if (!tag || !operator) return;
      overlay.hidden = false;
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('has-recon-comments-modal');
      closeBtn.focus();
      loadDetails(tag, operator, eventDate || '');
    }

    window.CLEAR_reconCommentsOpen = openModal;

    document.addEventListener('click', function (event) {
      var button = event.target.closest && event.target.closest('[data-recon-open="true"]');
      if (!button) return;
      event.preventDefault();
      event.stopPropagation();
      openModal(
        button.getAttribute('data-tag') || '',
        button.getAttribute('data-operator') || '',
        button.getAttribute('data-event-date') || ''
      );
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var submit = form.querySelector('button[type="submit"]');
      if (submit) submit.disabled = true;
      status('Guardando comentario…', 'loading');
      var data = new FormData(form);
      data.append('action', 'save_comment');
      fetch('reconocidas_usuario_api.php', { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (response) {
          return response.json().then(function (payload) { if (!response.ok) throw new Error(payload.error || 'No se pudo guardar'); return payload; });
        }).then(function (payload) {
          status(payload.message || 'Comentario guardado.', 'ok');
          if (typeof window.CLEAR_updateReconButtons === 'function') {
            window.CLEAR_updateReconButtons(currentTag, currentOperator, Number(payload.comment_count || 0));
          } else {
            document.querySelectorAll('[data-recon-open="true"]').forEach(function (btn) {
              if (btn.getAttribute('data-tag') === currentTag && btn.getAttribute('data-operator') === currentOperator) {
                btn.innerHTML = '<span aria-hidden="true">💬</span> ' + String(payload.comment_count || 0) + ' Ver';
              }
            });
          }
          loadDetails(currentTag, currentOperator);
        }).catch(function (error) {
          status(error.message || 'No se pudo guardar el comentario.', 'error');
        }).finally(function () { if (submit) submit.disabled = false; });
    });

    cancelBtn.addEventListener('click', function () { form.hidden = true; });
    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });
  })();

  // Orden de columnas ajustable y persistente.
  // Arrastrá el control de cada encabezado; el orden se conserva por pantalla.
  (function initReorderableGrids() {
    var SAVE_PREFIX = 'clear:grid-column-order:v1:';

    function clean(value) {
      return String(value || '').replace(/[↕▲▼]/g, '').replace(/\s+/g, ' ').trim();
    }
    function safeKey(value) {
      return (value || 'columna').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'columna';
    }
    function storageKey(table, index) {
      var identity = table.id || table.getAttribute('data-grid-key') || ('grid_' + index);
      return SAVE_PREFIX + window.location.pathname + ':' + identity;
    }
    function headerRow(table) {
      return table.tHead && table.tHead.rows && table.tHead.rows[0] ? table.tHead.rows[0] : null;
    }
    function assignKeys(table) {
      var row = headerRow(table);
      if (!row) return [];
      var used = {};
      return Array.prototype.slice.call(row.cells).map(function (th, index) {
        var label = clean(th.getAttribute('data-column-label') || th.textContent) || ('Columna ' + (index + 1));
        var base = th.getAttribute('data-column-key') || safeKey(label);
        used[base] = (used[base] || 0) + 1;
        var key = used[base] > 1 ? base + '_' + used[base] : base;
        th.setAttribute('data-column-key', key);
        return key;
      });
    }
    function moveColumn(table, fromIndex, toIndex) {
      if (fromIndex === toIndex || fromIndex < 0 || toIndex < 0) return;
      var row = headerRow(table);
      if (!row) return;
      var expected = row.cells.length;
      Array.prototype.forEach.call(table.rows, function (tr) {
        if (tr.cells.length !== expected) return;
        var cell = tr.cells[fromIndex];
        if (!cell) return;
        if (fromIndex < toIndex) {
          var after = tr.cells[toIndex];
          tr.insertBefore(cell, after ? after.nextSibling : null);
        } else {
          tr.insertBefore(cell, tr.cells[toIndex] || null);
        }
      });
      refreshSortIndexes(table);
    }
    function refreshSortIndexes(table) {
      var row = headerRow(table);
      if (!row) return;
      Array.prototype.forEach.call(row.cells, function (th, index) {
        th.querySelectorAll('[data-sort-column]').forEach(function (button) {
          button.setAttribute('data-sort-column', String(index));
        });
      });
    }
    function currentOrder(table) {
      var row = headerRow(table);
      if (!row) return [];
      return Array.prototype.slice.call(row.cells).map(function (th) {
        return th.getAttribute('data-column-key') || '';
      }).filter(Boolean);
    }
    function readOrder(key) {
      try {
        var parsed = JSON.parse(localStorage.getItem(key) || '[]');
        return Array.isArray(parsed) ? parsed : [];
      } catch (e) { return []; }
    }
    function saveOrder(key, order) {
      try { localStorage.setItem(key, JSON.stringify(order)); } catch (e) {}
    }
    function applyStored(table, key) {
      var desired = readOrder(key);
      if (!desired.length) return;
      desired.forEach(function (columnKey, targetIndex) {
        var row = headerRow(table);
        if (!row || targetIndex >= row.cells.length) return;
        var currentIndex = Array.prototype.findIndex.call(row.cells, function (th) {
          return th.getAttribute('data-column-key') === columnKey;
        });
        if (currentIndex >= 0 && currentIndex !== targetIndex) moveColumn(table, currentIndex, targetIndex);
      });
    }
    function initTable(table, tableIndex) {
      if (table.getAttribute('data-column-order-ready') === 'true') return;
      var row = headerRow(table);
      if (!row || row.cells.length < 2) return;
      table.setAttribute('data-column-order-ready', 'true');
      assignKeys(table);
      var key = storageKey(table, tableIndex);
      applyStored(table, key);
      refreshSortIndexes(table);

      var draggingKey = '';
      Array.prototype.slice.call(row.cells).forEach(function (th) {
        if (th.querySelector(':scope > .column-drag-handle')) return;
        var handle = document.createElement('span');
        handle.className = 'column-drag-handle';
        handle.draggable = true;
        handle.setAttribute('role', 'button');
        handle.setAttribute('tabindex', '0');
        handle.setAttribute('aria-label', 'Mover columna ' + clean(th.textContent));
        handle.title = 'Arrastrá para mover esta columna';
        th.appendChild(handle);

        handle.addEventListener('dragstart', function (event) {
          draggingKey = th.getAttribute('data-column-key') || '';
          th.classList.add('is-dragging-column');
          document.body.classList.add('is-dragging-grid-column');
          if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', draggingKey);
          }
          event.stopPropagation();
        });
        handle.addEventListener('dragend', function () {
          draggingKey = '';
          th.classList.remove('is-dragging-column');
          document.body.classList.remove('is-dragging-grid-column');
          Array.prototype.forEach.call(row.cells, function (cell) { cell.classList.remove('is-column-drop-target'); });
        });
        handle.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
        });
        th.addEventListener('dragover', function (event) {
          if (!draggingKey) return;
          event.preventDefault();
          th.classList.add('is-column-drop-target');
          if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
        });
        th.addEventListener('dragleave', function () {
          th.classList.remove('is-column-drop-target');
        });
        th.addEventListener('drop', function (event) {
          if (!draggingKey) return;
          event.preventDefault();
          event.stopPropagation();
          var currentRow = headerRow(table);
          if (!currentRow) return;
          var fromIndex = Array.prototype.findIndex.call(currentRow.cells, function (cell) {
            return cell.getAttribute('data-column-key') === draggingKey;
          });
          var toIndex = th.cellIndex;
          th.classList.remove('is-column-drop-target');
          if (fromIndex >= 0 && toIndex >= 0 && fromIndex !== toIndex) {
            moveColumn(table, fromIndex, toIndex);
            saveOrder(key, currentOrder(table));
            document.dispatchEvent(new CustomEvent('clear-grid-columns-reordered', { detail: { table: table } }));
          }
        });
      });
    }

    document.querySelectorAll('table.grid').forEach(initTable);
  })();

  // Ancho ajustable y persistente para todas las grillas.
  // Se guarda en localStorage por ruta, tabla y nombre de columna.
  (function initResizableGrids() {
    var MIN_WIDTH = 70;
    var MAX_WIDTH = 900;
    var SAVE_PREFIX = 'clear:grid-column-widths:v1:';

    function cleanHeaderText(th) {
      var clone = th.cloneNode(true);
      clone.querySelectorAll('.column-resizer,.sort-indicator,.sortarrow').forEach(function (node) { node.remove(); });
      return (clone.textContent || '')
        .replace(/[↕▲▼]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
    }

    function safeKey(value) {
      return (value || 'columna')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/gi, '_')
        .replace(/^_+|_+$/g, '') || 'columna';
    }

    function tableStorageKey(table, tableIndex) {
      var identity = table.id || table.getAttribute('data-grid-key') || ('grid_' + tableIndex);
      return SAVE_PREFIX + window.location.pathname + ':' + identity;
    }

    function readWidths(storageKey) {
      try {
        var parsed = JSON.parse(localStorage.getItem(storageKey) || '{}');
        return parsed && typeof parsed === 'object' ? parsed : {};
      } catch (error) {
        return {};
      }
    }

    function saveWidths(storageKey, widths) {
      try { localStorage.setItem(storageKey, JSON.stringify(widths)); } catch (error) {}
    }

    function visibleHeaderWidth(th) {
      if (!th || getComputedStyle(th).display === 'none') return 0;
      var value = parseFloat(th.style.width || th.getBoundingClientRect().width || 0);
      return Number.isFinite(value) ? value : 0;
    }

    function setColumnWidth(table, headers, index, width) {
      width = Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, Math.round(width)));
      var th = headers[index];
      if (!th) return width;
      th.style.width = width + 'px';
      th.style.minWidth = width + 'px';
      th.style.maxWidth = width + 'px';
      Array.prototype.forEach.call(table.rows, function (row) {
        var cell = row.cells[index];
        if (!cell) return;
        cell.style.width = width + 'px';
        cell.style.minWidth = width + 'px';
        cell.style.maxWidth = width + 'px';
      });
      return width;
    }

    function clearColumnWidth(table, headers, index) {
      var th = headers[index];
      if (!th) return;
      th.style.width = '';
      th.style.minWidth = '';
      th.style.maxWidth = '';
      Array.prototype.forEach.call(table.rows, function (row) {
        var cell = row.cells[index];
        if (!cell) return;
        cell.style.width = '';
        cell.style.minWidth = '';
        cell.style.maxWidth = '';
      });
    }

    function updateTableWidth(table, headers) {
      var total = 0;
      headers.forEach(function (th) { total += visibleHeaderWidth(th); });
      var viewport = table.parentElement ? table.parentElement.clientWidth : 0;
      table.style.width = Math.max(total, viewport || 0) + 'px';
      table.style.minWidth = '100%';
    }

    function initTable(table, tableIndex) {
      if (table.getAttribute('data-column-resize-ready') === 'true') return;
      var headerRow = table.tHead && table.tHead.rows && table.tHead.rows[0];
      if (!headerRow || !headerRow.cells.length) return;

      table.setAttribute('data-column-resize-ready', 'true');
      table.classList.add('grid--resizable');

      var headers = Array.prototype.slice.call(headerRow.cells);
      var storageKey = tableStorageKey(table, tableIndex);
      var widths = readWidths(storageKey);
      var duplicateKeys = {};
      var columnKeys = headers.map(function (th, index) {
        var base = safeKey(th.getAttribute('data-column-key') || th.getAttribute('data-label') || cleanHeaderText(th) || ('col_' + index));
        duplicateKeys[base] = (duplicateKeys[base] || 0) + 1;
        return duplicateKeys[base] > 1 ? base + '_' + duplicateKeys[base] : base;
      });

      // Aplicar anchos guardados antes de crear los controles.
      headers.forEach(function (th, index) {
        var saved = Number(widths[columnKeys[index]]);
        if (Number.isFinite(saved) && saved >= MIN_WIDTH) setColumnWidth(table, headers, index, saved);
      });

      headers.forEach(function (th, index) {
        if (th.querySelector(':scope > .column-resizer')) return;
        var handle = document.createElement('span');
        handle.className = 'column-resizer';
        handle.setAttribute('role', 'separator');
        handle.setAttribute('aria-orientation', 'vertical');
        handle.setAttribute('aria-label', 'Ajustar ancho de ' + (cleanHeaderText(th) || 'columna'));
        handle.title = 'Arrastrá para ajustar el ancho. Doble clic para restablecer.';
        th.appendChild(handle);

        var startX = 0;
        var startWidth = 0;
        var moved = false;

        function onMove(event) {
          moved = true;
          var clientX = event.touches && event.touches[0] ? event.touches[0].clientX : event.clientX;
          var liveHeaders = Array.prototype.slice.call(headerRow.cells);
          var liveIndex = Array.prototype.indexOf.call(headerRow.cells, th);
          if (liveIndex < 0) return;
          var width = setColumnWidth(table, liveHeaders, liveIndex, startWidth + (clientX - startX));
          widths[columnKeys[index]] = width;
          updateTableWidth(table, liveHeaders);
          event.preventDefault();
        }

        function onEnd() {
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup', onEnd);
          document.removeEventListener('touchmove', onMove);
          document.removeEventListener('touchend', onEnd);
          document.body.classList.remove('is-resizing-grid-column');
          th.classList.remove('is-resizing');
          if (moved) saveWidths(storageKey, widths);
          window.setTimeout(function () { moved = false; }, 0);
        }

        function onStart(event) {
          if (event.button !== undefined && event.button !== 0) return;
          startX = event.touches && event.touches[0] ? event.touches[0].clientX : event.clientX;
          startWidth = th.getBoundingClientRect().width;
          moved = false;
          document.body.classList.add('is-resizing-grid-column');
          th.classList.add('is-resizing');
          document.addEventListener('mousemove', onMove, { passive: false });
          document.addEventListener('mouseup', onEnd);
          document.addEventListener('touchmove', onMove, { passive: false });
          document.addEventListener('touchend', onEnd);
          event.preventDefault();
          event.stopPropagation();
        }

        handle.addEventListener('mousedown', onStart);
        handle.addEventListener('touchstart', onStart, { passive: false });
        handle.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
        });
        handle.addEventListener('dblclick', function (event) {
          event.preventDefault();
          event.stopPropagation();
          delete widths[columnKeys[index]];
          var liveHeaders = Array.prototype.slice.call(headerRow.cells);
          var liveIndex = Array.prototype.indexOf.call(headerRow.cells, th);
          if (liveIndex < 0) return;
          clearColumnWidth(table, liveHeaders, liveIndex);
          saveWidths(storageKey, widths);
          // Volver a medir el ancho natural de la columna.
          window.requestAnimationFrame(function () {
            var currentHeaders = Array.prototype.slice.call(headerRow.cells);
            var currentIndex = Array.prototype.indexOf.call(headerRow.cells, th);
            if (currentIndex < 0) return;
            var natural = Math.max(MIN_WIDTH, Math.ceil(th.getBoundingClientRect().width));
            setColumnWidth(table, currentHeaders, currentIndex, natural);
            updateTableWidth(table, currentHeaders);
          });
        });
      });

      updateTableWidth(table, headers);

      // Las pantallas con selector de columnas cambian display dinámicamente.
      var observer = new MutationObserver(function () { updateTableWidth(table, headers); });
      headers.forEach(function (th) { observer.observe(th, { attributes: true, attributeFilter: ['style', 'class', 'hidden'] }); });
      window.addEventListener('resize', function () { updateTableWidth(table, headers); });
    }

    document.querySelectorAll('table.grid').forEach(initTable);
  })();

  document.querySelectorAll('table.js-sortable').forEach(initSortableTable);
})();
