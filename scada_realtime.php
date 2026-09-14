<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/scada_realtime.php';
require_once __DIR__ . '/includes/alarm_actions.php';

auth_require();
permissions_require_menu('scada_realtime');

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'America/Argentina/Buenos_Aires');
$APP_USER = auth_user() ?: 'CLEAR';
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = 'scada_realtime';
$ready = clear_scada_objects_ready();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>SCADA Real time · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260908-scada-fix1">
  <link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260908-scada-grid1">
  <link rel="stylesheet" href="assets/css/scada_realtime.css?v=20260908-scada-grid1">
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main scadaMain">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <header class="page__head scadaPageHead">
      <div>
        <h1 class="page__title">SCADA Real time</h1>
        <div class="page__sub">Valores actuales desde SQL · histórico bajo demanda desde PI Web API</div>
      </div>
      <div class="scadaLive" id="scadaLive"><span class="scadaLive__dot"></span><b>En línea</b><span id="refreshLabel">Actualización automática</span></div>
    </header>

    <?php if (!$ready): ?>
      <div class="scadaNotice scadaNotice--error">
        <b>El módulo todavía no está instalado.</b>
        Ejecutá <code>SQL/CLEAR_SCADA_REALTIME_20260908.sql</code> y actualizá esta página.
      </div>
    <?php endif; ?>

    <section class="scadaFilters" aria-label="Filtros SCADA">
      <label><span>Zona</span><select id="filterZone"><option value="">Todas</option></select></label>
      <label><span>Batería</span><select id="filterBattery"><option value="">Todas</option></select></label>
      <label><span>Nodo</span><select id="filterNode"><option value="">Todos</option></select></label>
      <label><span>Área / equipo</span><select id="filterArea"><option value="">Todos</option></select></label>
      <label><span>Calidad</span><select id="filterQuality"><option value="">Todas</option></select></label>
      <label><span>TAG</span><select id="filterTag"><option value="">Todos</option></select></label>
      <label class="scadaSearch"><span>Buscar</span><input id="filterSearch" type="search" placeholder="TAG o descripción…" autocomplete="off"></label>
      <button class="scadaIconButton" id="refreshNow" type="button" title="Actualizar ahora" aria-label="Actualizar ahora">↻</button>
    </section>

    <section class="batteryStrip" id="batteryStrip" aria-label="Baterías"></section>

    <section class="scadaMetrics" aria-label="Resumen actual">
      <article class="scadaMetric"><span>Variables</span><b id="metricVariables">—</b><small id="metricShown">Esperando datos</small></article>
      <article class="scadaMetric scadaMetric--good"><span>Calidad Good</span><b id="metricGood">—</b><small id="metricGoodPct">—</small></article>
      <article class="scadaMetric scadaMetric--warn"><span>Sin comunicación</span><b id="metricBad">—</b><small>Calidad incorrecta o dato vencido</small></article>
      <article class="scadaMetric scadaMetric--alarm"><span id="metricAlarmLabel">Alarmas últimas 24 h</span><b id="metricAlarms">—</b><small>Según el filtro actual</small></article>
      <article class="scadaMetric scadaMetric--time"><span>Fecha de datos</span><b id="metricTime">—</b><small id="metricAge">—</small></article>
    </section>

    <div class="scadaNotice" id="scadaStatus" role="status" hidden></div>

    <section class="scadaWorkspace">
      <article class="scadaPanel scadaValuesPanel">
        <div class="scadaPanel__head">
          <div><span class="scadaEyebrow">Valores actuales</span><h2 id="valuesTitle">Variables SCADA</h2></div>
          <div class="scadaPanel__meta"><span id="alarmWindowLabel">Alarmas en caché</span></div>
        </div>
        <div class="scadaTableScroll">
          <table class="scadaTable">
            <thead><tr>
              <th data-sort-column="status"><button class="scadaSort" type="button" data-sort="status">Estado <span aria-hidden="true"></span></button></th>
              <th data-sort-column="tag"><button class="scadaSort" type="button" data-sort="tag">TAG <span aria-hidden="true">▲</span></button></th>
              <th data-sort-column="description"><button class="scadaSort" type="button" data-sort="description">Descripción <span aria-hidden="true"></span></button></th>
              <th data-sort-column="value"><button class="scadaSort" type="button" data-sort="value">Valor <span aria-hidden="true"></span></button></th>
              <th data-sort-column="unit"><button class="scadaSort" type="button" data-sort="unit">Unidad <span aria-hidden="true"></span></button></th>
              <th data-sort-column="quality"><button class="scadaSort" type="button" data-sort="quality">Calidad <span aria-hidden="true"></span></button></th>
              <th>Historial</th>
            </tr></thead>
            <tbody id="valuesBody"><tr><td colspan="7" class="scadaEmpty">Cargando valores…</td></tr></tbody>
          </table>
        </div>
      </article>

      <article class="scadaPanel scadaHistoryPanel">
        <div class="scadaPanel__head scadaHistoryHead">
          <div><span class="scadaEyebrow">Histórico PI</span><h2 id="historyTitle">Seleccioná una variable</h2><p id="historySubtitle">El histórico se consulta solamente al seleccionar un TAG.</p></div>
          <span class="piLinkState" id="piLinkState">Sin selección</span>
        </div>

        <div class="historyMetrics">
          <div class="historyCurrent"><span>Valor actual</span><b id="historyCurrent">—</b><small id="historyCurrentAt">—</small></div>
          <div><span>Mínimo</span><b id="historyMin">—</b></div>
          <div><span>Promedio</span><b id="historyAvg">—</b></div>
          <div><span>Máximo</span><b id="historyMax">—</b></div>
        </div>

        <div class="historyControls">
          <div class="historyRanges" role="group" aria-label="Rango histórico">
            <button type="button" data-range="1h">1 h</button>
            <button type="button" data-range="8h">8 h</button>
            <button type="button" class="is-active" data-range="24h">24 h</button>
            <button type="button" data-range="7d">7 días</button>
            <button type="button" data-range="custom">Personalizado</button>
          </div>
          <div class="historyActions">
            <label class="historyMode"><span>Modo</span><select id="historyMode"><option value="recorded">Registrado</option><option value="interpolated">Interpolado</option></select></label>
            <button class="historyExcel" id="exportHistoryExcel" type="button" disabled><?php echo icon('download'); ?> Exportar Excel</button>
          </div>
        </div>
        <div class="customRange" id="customRange" hidden>
          <label><span>Desde</span><input id="historyFrom" type="datetime-local"></label>
          <label><span>Hasta</span><input id="historyTo" type="datetime-local"></label>
          <button type="button" id="applyCustomRange">Aplicar</button>
        </div>

        <div class="historyChartWrap">
          <canvas id="historyChart"></canvas>
          <div class="historyEmpty" id="historyEmpty">Seleccioná el ícono de historial de una variable.</div>
        </div>
        <div class="historyLegend"><span><i class="legendDot legendDot--good"></i>Good</span><span><i class="legendDot legendDot--warn"></i>Stale</span><span><i class="legendDot legendDot--bad"></i>Bad</span><span><i class="legendDot"></i>Sin datos</span></div>

        <div class="historyDetails">
          <dl><dt>TAG</dt><dd id="detailTag">—</dd><dt>Descripción</dt><dd id="detailDescription">—</dd><dt>Unidad</dt><dd id="detailUnit">—</dd></dl>
          <dl><dt>Nodo</dt><dd id="detailNode">—</dd><dt>Punto PI</dt><dd id="detailPiPoint">—</dd><dt>Última muestra PI</dt><dd id="detailPiTime">—</dd></dl>
          <a class="piVisionButton" id="piVisionButton" href="#" target="_blank" rel="noopener" hidden>Abrir en PI Vision ↗</a>
        </div>
      </article>
    </section>
  </main>
</div>

<?php clear_alarm_actions_modal(); ?>

<script>
window.CLEAR_SCADA_PAGE={ready:<?php echo $ready ? 'true' : 'false'; ?>};
</script>
<script src="assets/js/chart.umd.js"></script>
<script src="assets/js/alarm_actions.js?v=20260908-scada-grid1"></script>
<script src="assets/js/scada_realtime.js?v=20260908-scada-grid1"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
