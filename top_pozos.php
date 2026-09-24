<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/alarm_actions.php';
require_once __DIR__ . '/includes/novedades_semanales_common.php';

auth_require();
permissions_require_menu('top_pozos');

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'America/Argentina/Buenos_Aires');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = 'top_pozos';

$db = clear_db();
$dbError = $db->ok() ? '' : $db->error();
$table = 'dbo.FIXALARMS';
$dateColumn = 'ALM_NATIVETIMEIN';
$tagColumn = 'ALM_TAGNAME';
$priorityColumn = 'ALM_ALMPRIORITY';
$wellColumn = 'ALM_ALMEXTFLD2';

function tp_terms($value) {
    $value = trim((string)$value);
    if ($value === '') return [];
    return array_values(array_filter(preg_split('/\s+/', $value), static function ($v) { return trim((string)$v) !== ''; }));
}
function tp_num($value) { return number_format((int)$value, 0, ',', '.'); }
function tp_pct($value) { return number_format((float)$value, 1, ',', '.') . '%'; }
function tp_hour_label($value) { return str_pad((string)$value, 2, '0', STR_PAD_LEFT) . ':00'; }
function tp_valid_date($value, $fallback) {
    $value = trim((string)$value);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : $fallback;
}
function tp_valid_time($value, $fallback) {
    $value = trim((string)$value);
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $fallback;
}

$prefix = trim((string)($_GET['prefijo_pozo'] ?? 'YPF.SC'));
$wellSearch = trim((string)($_GET['pozo'] ?? ''));

$total = 0;
$high = 0;
$medium = 0;
$low = 0;
$other = 0;
$uniqueWells = 0;
$uniqueTags = 0;
$topWell = '';
$topWellCount = 0;
$hourlyLabels = [];
$hourlyTotals = [];
$hourlyHigh = [];
$hourlyMedium = [];
$hourlyLow = [];
$hourlyOther = [];
$wellDetailRows = [];
$wellComments = [];
$canViewComments = permissions_can('comments.view');
$canCreateComments = permissions_can('comments.create');
$reportEnabled = permissions_can_menu('novedades_semanales_reporte');

$now = new DateTimeImmutable('now');
$defaultFrom = $now->sub(new DateInterval('PT24H'));
$fromDate = tp_valid_date($_GET['desde'] ?? $defaultFrom->format('Y-m-d'), $defaultFrom->format('Y-m-d'));
$fromTime = tp_valid_time($_GET['hora_desde'] ?? $defaultFrom->format('H:i'), $defaultFrom->format('H:i'));
$toDate = tp_valid_date($_GET['hasta'] ?? $now->format('Y-m-d'), $now->format('Y-m-d'));
$toTime = tp_valid_time($_GET['hora_hasta'] ?? $now->format('H:i'), $now->format('H:i'));
$from = new DateTimeImmutable($fromDate . ' ' . $fromTime . ':00');
$to = new DateTimeImmutable($toDate . ' ' . $toTime . ':59');
if ($from > $to) {
    [$from, $to] = [$to, $from];
    $fromDate = $from->format('Y-m-d');
    $fromTime = $from->format('H:i');
    $toDate = $to->format('Y-m-d');
    $toTime = $to->format('H:i');
}
$fromSql = $from->format('Y-m-d H:i:s');
$toSql = $to->format('Y-m-d H:i:s');
$isDefault24h = !isset($_GET['desde'], $_GET['hasta'], $_GET['hora_desde'], $_GET['hora_hasta']);

if ($db->ok()) {
    $meta = $db->all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS'");
    $columns = [];
    foreach ($meta as $row) {
        $name = trim((string)($row['COLUMN_NAME'] ?? $row['column_name'] ?? reset($row)));
        if ($name !== '') $columns[strtoupper($name)] = $name;
    }
    $dateColumn = $columns['ALM_NATIVETIMEIN'] ?? $dateColumn;
    $tagColumn = $columns['ALM_TAGNAME'] ?? $tagColumn;
    $priorityColumn = $columns['ALM_ALMPRIORITY'] ?? $priorityColumn;
    $wellColumn = $columns['ALM_ALMEXTFLD2'] ?? $wellColumn;

    $wellText = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$wellColumn])))";
    $tagText = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$tagColumn])))";
    $priorityText = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100), [$priorityColumn]))))";
    $priorityClass = "CASE
        WHEN $priorityText LIKE '%CRIT%' OR $priorityText IN ('HIGH','HI','ALTA') THEN 'Alta'
        WHEN $priorityText IN ('MEDIUM','MED','MEDIA') THEN 'Media'
        WHEN $priorityText IN ('LOW','LO','BAJA','INFO') THEN 'Baja'
        ELSE 'Otra'
    END";

    $conditions = [
        "[$wellColumn] IS NOT NULL",
        "$wellText <> ''",
        "[$dateColumn] >= ?",
        "[$dateColumn] <= ?"
    ];
    $params = [$fromSql, $toSql];
    if ($prefix !== '') {
        $conditions[] = "UPPER($wellText) LIKE ?";
        $params[] = strtoupper($prefix) . '%';
    }
    foreach (tp_terms($wellSearch) as $term) {
        $conditions[] = "($wellText LIKE ? OR REPLACE(REPLACE(REPLACE($wellText,'_',''),'-',''),' ','') LIKE ?)";
        $params[] = '%' . $term . '%';
        $params[] = '%' . str_replace(['_', '-', ' '], '', $term) . '%';
    }
    $where = 'WHERE ' . implode(' AND ', $conditions);

    $total = (int)$db->scalar("SELECT COUNT(*) FROM $table $where", $params);
    $uniqueWells = (int)$db->scalar("SELECT COUNT(DISTINCT $wellText) FROM $table $where", $params);
    $uniqueTags = (int)$db->scalar("SELECT COUNT(DISTINCT $tagText) FROM $table $where AND [$tagColumn] IS NOT NULL AND $tagText <> ''", $params);

    $priorityRows = $db->all("SELECT $priorityClass AS clase, COUNT(*) AS cantidad FROM $table $where GROUP BY $priorityClass", $params);
    foreach ($priorityRows as $row) {
        $class = trim((string)($row['clase'] ?? $row['CLASE'] ?? 'Otra'));
        $count = (int)($row['cantidad'] ?? $row['CANTIDAD'] ?? 0);
        if ($class === 'Alta') $high = $count;
        elseif ($class === 'Media') $medium = $count;
        elseif ($class === 'Baja') $low = $count;
        else $other += $count;
    }

    $topWellRows = $db->all("SELECT TOP 1 $wellText AS pozo, COUNT(*) AS cantidad FROM $table $where GROUP BY $wellText ORDER BY COUNT(*) DESC", $params);
    if ($topWellRows) {
        $r = $topWellRows[0];
        $topWell = trim((string)($r['pozo'] ?? $r['POZO'] ?? ''));
        $topWellCount = (int)($r['cantidad'] ?? $r['CANTIDAD'] ?? 0);
    }

    $hourMap = [];
    for ($i = 0; $i < 24; $i++) {
        $hourMap[$i] = ['Alta' => 0, 'Media' => 0, 'Baja' => 0, 'Otra' => 0, 'Total' => 0];
    }
    $hourRows = $db->all("SELECT DATEPART(hour, [$dateColumn]) AS hora, $priorityClass AS clase, COUNT(*) AS cantidad FROM $table $where GROUP BY DATEPART(hour, [$dateColumn]), $priorityClass ORDER BY hora", $params);
    foreach ($hourRows as $row) {
        $h = (int)($row['hora'] ?? $row['HORA'] ?? 0);
        $class = trim((string)($row['clase'] ?? $row['CLASE'] ?? 'Otra'));
        $count = (int)($row['cantidad'] ?? $row['CANTIDAD'] ?? 0);
        if (!isset($hourMap[$h])) continue;
        $hourMap[$h][$class] = $count;
        $hourMap[$h]['Total'] += $count;
    }
    foreach ($hourMap as $hour => $counts) {
        $hourlyLabels[] = tp_hour_label($hour);
        $hourlyTotals[] = $counts['Total'];
        $hourlyHigh[] = $counts['Alta'];
        $hourlyMedium[] = $counts['Media'];
        $hourlyLow[] = $counts['Baja'];
        $hourlyOther[] = $counts['Otra'];
    }

    /* Detalle agregado por pozo para la grilla operativa. */
    $wellDetailRows = $db->all(
        "SELECT $wellText AS pozo, " .
        "COUNT(*) AS total_alarmas, " .
        "SUM(CASE WHEN $priorityClass='Alta' THEN 1 ELSE 0 END) AS prioridad_alta, " .
        "SUM(CASE WHEN $priorityClass='Media' THEN 1 ELSE 0 END) AS prioridad_media, " .
        "SUM(CASE WHEN $priorityClass='Baja' THEN 1 ELSE 0 END) AS prioridad_baja, " .
        "SUM(CASE WHEN $priorityClass='Otra' THEN 1 ELSE 0 END) AS prioridad_otra, " .
        "COUNT(DISTINCT CASE WHEN [$tagColumn] IS NOT NULL AND $tagText<>'' THEN $tagText END) AS tags_unicos, " .
        "CONVERT(varchar(19),MAX([$dateColumn]),120) AS ultima_alarma " .
        "FROM $table $where GROUP BY $wellText ORDER BY COUNT(*) DESC, $wellText ASC",
        $params
    );

    if (($canViewComments || $canCreateComments) && $wellDetailRows) {
        $commentSubjects = [];
        foreach ($wellDetailRows as $commentSourceRow) {
            $commentWell = trim((string)($commentSourceRow['pozo'] ?? $commentSourceRow['POZO'] ?? ''));
            if ($commentWell !== '') $commentSubjects[] = clear_alarm_comment_subject($commentWell, 'pozo');
        }
        $wellComments = clear_alarm_comments_load_subjects_sql($commentSubjects);
    }
}

$highPct = $total > 0 ? ($high * 100 / $total) : 0;
$mediumPct = $total > 0 ? ($medium * 100 / $total) : 0;
$lowPct = $total > 0 ? ($low * 100 / $total) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Top Pozos · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260924-toppozos-grid-2">
  <link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260807-2">
  <?php if($reportEnabled): ?><link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260826-select-all-1"><?php endif; ?>
  <script src="assets/js/chart.umd.js"></script>
  <style>
    .topTotalFilters{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}
    .topTotalFilter{display:flex;align-items:center;gap:8px;min-height:42px;padding:0 12px;background:#fff;border:1px solid var(--line-mid);border-radius:10px}
    .topTotalFilter svg{width:15px;height:15px;color:var(--petrol)}
    .topTotalFilter label{display:flex;align-items:center;gap:7px}
    .topTotalFilter span{font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--text-mut)}
    .topTotalFilter select,.topTotalFilter input{border:0;outline:0;background:transparent;color:var(--text);font:inherit;min-width:130px}
    .topTotalFilter--wide{flex:1 1 300px}.topTotalFilter--wide label{width:100%}.topTotalFilter--wide input{width:100%;min-width:180px}
    .topPozosDateRange{display:grid;grid-template-columns:auto 1fr 1fr auto 1fr 1fr;align-items:center;gap:7px;min-height:42px;padding:5px 10px;background:#fff;border:1px solid var(--line-mid);border-radius:10px;flex:1 1 560px}
    .topPozosDateRange span{font-size:9px;font-weight:800;color:var(--text-mut);letter-spacing:.7px;text-transform:uppercase}
    .topPozosDateRange input{min-width:0;border:0;background:transparent;color:var(--text);font:inherit;outline:0}
    .topTotalKpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}
    .topTotalCharts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:16px}
    .topTotalChart{background:#fff;border:1px solid var(--line-mid);border-top:3px solid var(--petrol);border-radius:14px;padding:16px;min-width:0}
    .topTotalChart__head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
    .topTotalChart__head small{display:block;color:var(--text-mut);font-size:10px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;margin-bottom:4px}
    .topTotalChart__head h3{font-family:var(--font-head);font-size:20px;color:var(--text);margin:0}
    .topTotalChart__hint{font-size:11px;color:var(--text-mut);border:1px solid var(--line-mid);border-radius:999px;padding:5px 9px;white-space:nowrap}
    .topTotalChart__canvas{height:300px;position:relative}
    .topTotalMeta{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 14px;color:var(--text-soft);font-size:12px}
    .topTotalMeta b{color:var(--petrol)}
    .tpDetailSummary{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:4px 0 10px;color:var(--text-soft);font-size:12px}
    .tpDetailSummary b{color:var(--petrol)}
    .tpDetailTable td[data-column="pozo"]{font-weight:800;color:var(--petrol)}
    .tpDetailTable .cell-num{text-align:right;font-variant-numeric:tabular-nums}
    .tpDetailTable .gridFilterRow input{width:100%;min-width:90px;height:34px;border:1px solid var(--line-mid);border-radius:8px;padding:0 10px;background:#fff;color:var(--text)}
    .tpDetailTable thead tr:first-child th{color:var(--petrol);font-weight:800}
    .tpDetailTable .tpCommentCell{min-width:54px}
    .tpDetailTable .tpCommentCell .alarmCell{justify-content:center}
    @media(max-width:1100px){.topTotalKpis{grid-template-columns:repeat(2,1fr)}.topTotalCharts{grid-template-columns:1fr}}
    @media(max-width:900px){.topPozosDateRange{grid-template-columns:auto 1fr 1fr;flex-basis:100%}}
    @media(max-width:700px){.topTotalKpis{grid-template-columns:1fr}.topTotalFilter{width:100%}.topTotalFilter label{width:100%}.topTotalFilter select,.topTotalFilter input{flex:1;min-width:0}.topPozosDateRange{grid-template-columns:auto 1fr}}
  </style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page__head">
      <div>
        <h1 class="page__title">Top Pozos</h1>
        <div class="page__sub">Estadísticas operativas filtradas por pozos · rango configurable</div>
      </div>
      <div class="page__live"><span class="dot"></span><?php echo $dbError ? 'Sin conexión' : 'En vivo'; ?></div>
    </div>

    <?php if ($dbError): ?>
      <div class="tablewrap"><div class="empty"><p><b>Sin conexión:</b> <?php echo h($dbError); ?></p></div></div>
    <?php else: ?>
      <form class="topTotalFilters" method="get" action="top_pozos.php">
        <div class="topTotalFilter">
          <?php echo icon('oil'); ?>
          <label><span>Prefijo de pozo</span><input type="text" name="prefijo_pozo" value="<?php echo h($prefix); ?>" placeholder="Ej. YPF.SC"></label>
        </div>
        <div class="topTotalFilter topTotalFilter--wide">
          <?php echo icon('search'); ?>
          <label><span>Pozo</span><input type="search" name="pozo" value="<?php echo h($wellSearch); ?>" placeholder="Buscar aproximado por pozo…" autocomplete="off"></label>
        </div>
        <div class="topPozosDateRange">
          <span>Desde</span>
          <input type="date" name="desde" value="<?php echo h($fromDate); ?>" aria-label="Fecha desde">
          <input type="time" name="hora_desde" value="<?php echo h($fromTime); ?>" aria-label="Hora desde">
          <span>Hasta</span>
          <input type="date" name="hasta" value="<?php echo h($toDate); ?>" aria-label="Fecha hasta">
          <input type="time" name="hora_hasta" value="<?php echo h($toTime); ?>" aria-label="Hora hasta">
        </div>
        <button type="submit" class="toolbar__dateApply">Aplicar</button>
        <a href="top_pozos.php" class="btn-clear-filters">↺ Últimas 24 hs</a>
      </form>

      <div class="topTotalMeta">
        <span>Ventana: <b><?php echo h($from->format('d/m/Y H:i')); ?> — <?php echo h($to->format('d/m/Y H:i')); ?></b></span>
        <span>Prefijo: <b><?php echo h($prefix !== '' ? $prefix : 'Todos'); ?></b></span>
        <span>Filtro pozo: <b><?php echo h($wellSearch !== '' ? $wellSearch : 'Todos'); ?></b></span>
        <span>Pozos únicos: <b><?php echo tp_num($uniqueWells); ?></b></span>
        <span>Tags únicos: <b><?php echo tp_num($uniqueTags); ?></b></span>
        <span>Pozo principal: <b><?php echo h($topWell !== '' ? $topWell : 'Sin datos'); ?></b><?php if ($topWellCount): ?> (<?php echo tp_num($topWellCount); ?>)<?php endif; ?></span>
      </div>

      <section class="topTotalKpis" aria-label="Resumen de criticidad de pozos">
        <article class="stat acc-blue">
          <div class="stat__label"><?php echo $isDefault24h ? 'Total 24 hs' : 'Total del período'; ?></div>
          <div class="stat__value is-blue"><?php echo tp_num($total); ?></div>
          <div class="stat__detail">Registros de pozos obtenidos desde <b>dbo.FIXALARMS</b>.</div>
        </article>
        <article class="stat acc-red">
          <div class="stat__label">Prioridad alta</div>
          <div class="stat__value is-red"><?php echo tp_num($high); ?></div>
          <div class="stat__detail"><b><?php echo tp_pct($highPct); ?></b> del total filtrado.</div>
        </article>
        <article class="stat acc-amber">
          <div class="stat__label">Prioridad media</div>
          <div class="stat__value is-amber"><?php echo tp_num($medium); ?></div>
          <div class="stat__detail"><b><?php echo tp_pct($mediumPct); ?></b> del total filtrado.</div>
        </article>
        <article class="stat acc-green">
          <div class="stat__label">Prioridad baja</div>
          <div class="stat__value is-green"><?php echo tp_num($low); ?></div>
          <div class="stat__detail"><b><?php echo tp_pct($lowPct); ?></b> del total filtrado.<?php if ($other): ?> Otras: <b><?php echo tp_num($other); ?></b>.<?php endif; ?></div>
        </article>
      </section>

      <section class="topTotalCharts">
        <article class="topTotalChart">
          <div class="topTotalChart__head"><div><small>Volumen horario</small><h3>Total de alarmas por hora</h3></div><span class="topTotalChart__hint"><?php echo $isDefault24h ? 'Últimas 24 hs' : 'Rango seleccionado'; ?></span></div>
          <div class="topTotalChart__canvas"><canvas id="tpHourlyTotal"></canvas></div>
        </article>
        <article class="topTotalChart">
          <div class="topTotalChart__head"><div><small>Criticidad horaria</small><h3>Alta, media y baja</h3></div><span class="topTotalChart__hint"><?php echo $isDefault24h ? 'Comparación 24 hs' : 'Rango seleccionado'; ?></span></div>
          <div class="topTotalChart__canvas"><canvas id="tpHourlyPriority"></canvas></div>
        </article>
      </section>

      <div class="tpDetailSummary">
        <span><b>Detalle de pozos</b> · mismo formato operativo que las grillas de alarmas.</span>
        <span><b><?php echo tp_num(count($wellDetailRows)); ?></b> pozos en la selección</span>
      </div>

      <?php if($reportEnabled): ?><div class="nsReportTools"><button class="nsButton" type="button" data-clear-report-add-selected>Agregar seleccionadas al reporte</button><button class="nsButton is-secondary" type="button" data-ns-report-open>Ver y enviar reporte <span class="nsReportCount" data-ns-report-count hidden>0</span></button></div><?php endif; ?>

      <div class="tablewrap">
        <?php if (!$wellDetailRows): ?>
          <div class="empty"><p>No se encontraron pozos para el rango y los filtros seleccionados.</p></div>
        <?php else: ?>
        <div class="tablescroll">
          <table class="grid grid--sortable js-sortable tpDetailTable" id="topPozosDetailTable">
            <thead>
              <tr>
                <?php if($reportEnabled): ?><th>REPORTE</th><?php endif; ?>
                <th>POZO ↕</th>
                <th>TOTAL ALARMAS ↕</th>
                <th>PRIORIDAD ALTA ↕</th>
                <th>PRIORIDAD MEDIA ↕</th>
                <th>PRIORIDAD BAJA ↕</th>
                <th>TAGS ÚNICOS ↕</th>
                <th>ÚLTIMA ALARMA ↕</th>
                <th>COMENTARIO</th>
              </tr>
              <tr class="gridFilterRow" aria-label="Filtros del detalle de pozos">
                <?php if($reportEnabled): ?><th class="gridFilterRow__empty"></th><?php endif; ?>
                <th><input type="text" data-tp-detail-filter="true" placeholder="Filtrar pozo" aria-label="Filtrar pozo"></th>
                <th><input type="text" data-tp-detail-filter="true" placeholder="Filtrar" aria-label="Filtrar total de alarmas"></th>
                <th><input type="text" data-tp-detail-filter="true" placeholder="Filtrar" aria-label="Filtrar prioridad alta"></th>
                <th><input type="text" data-tp-detail-filter="true" placeholder="Filtrar" aria-label="Filtrar prioridad media"></th>
                <th><input type="text" data-tp-detail-filter="true" placeholder="Filtrar" aria-label="Filtrar prioridad baja"></th>
                <th><input type="text" data-tp-detail-filter="true" placeholder="Filtrar" aria-label="Filtrar tags únicos"></th>
                <th><input type="text" data-tp-detail-filter="true" placeholder="Filtrar fecha" aria-label="Filtrar última alarma"></th>
                <th class="gridFilterRow__empty"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($wellDetailRows as $detailRow):
                $detailWell = trim((string)($detailRow['pozo'] ?? $detailRow['POZO'] ?? ''));
                $detailLast = trim((string)($detailRow['ultima_alarma'] ?? $detailRow['ULTIMA_ALARMA'] ?? ''));
                $commentSubject = clear_alarm_comment_subject($detailWell, 'pozo');
                $commentRow = $wellComments[strtoupper($commentSubject)] ?? [];
                $commentText = trim((string)($commentRow['COMENTARIO'] ?? $commentRow['comentario'] ?? ''));
                $hasComment = $commentText !== '';
                $totalAlarms = (int)($detailRow['total_alarmas'] ?? $detailRow['TOTAL_ALARMAS'] ?? 0);
                $priorityHigh = (int)($detailRow['prioridad_alta'] ?? $detailRow['PRIORIDAD_ALTA'] ?? 0);
                $priorityMedium = (int)($detailRow['prioridad_media'] ?? $detailRow['PRIORIDAD_MEDIA'] ?? 0);
                $priorityLow = (int)($detailRow['prioridad_baja'] ?? $detailRow['PRIORIDAD_BAJA'] ?? 0);
                $uniqueDetailTags = (int)($detailRow['tags_unicos'] ?? $detailRow['TAGS_UNICOS'] ?? 0);
                $displayLast = $detailLast !== '' ? date('d/m/Y H:i:s', strtotime($detailLast)) : '—';
                $reportColumns = [
                    'Pozo'=>$detailWell,
                    'Total alarmas'=>$totalAlarms,
                    'Prioridad alta'=>$priorityHigh,
                    'Prioridad media'=>$priorityMedium,
                    'Prioridad baja'=>$priorityLow,
                    'Tags únicos'=>$uniqueDetailTags,
                    'Última alarma'=>$displayLast,
                    'Comentario'=>$commentText,
                ];
              ?>
              <tr>
                <?php if($reportEnabled): ?><td><?php echo ns_report_pick(ns_report_key('top-pozos-24h',[$fromSql,$toSql,$detailWell]),'row','Top Pozos 24h · '.$detailWell,ns_report_row_payload($reportColumns),'Incluir'); ?></td><?php endif; ?>
                <td data-column="pozo" data-raw-value="<?php echo h($detailWell); ?>"><?php echo h($detailWell !== '' ? $detailWell : '—'); ?></td>
                <td class="cell-num" data-raw-value="<?php echo $totalAlarms; ?>"><?php echo tp_num($totalAlarms); ?></td>
                <td class="cell-num" data-raw-value="<?php echo $priorityHigh; ?>"><?php echo tp_num($priorityHigh); ?></td>
                <td class="cell-num" data-raw-value="<?php echo $priorityMedium; ?>"><?php echo tp_num($priorityMedium); ?></td>
                <td class="cell-num" data-raw-value="<?php echo $priorityLow; ?>"><?php echo tp_num($priorityLow); ?></td>
                <td class="cell-num" data-raw-value="<?php echo $uniqueDetailTags; ?>"><?php echo tp_num($uniqueDetailTags); ?></td>
                <td data-raw-value="<?php echo h($detailLast); ?>"><?php echo h($displayLast); ?></td>
                <td class="tpCommentCell" data-comment-subject="<?php echo h($commentSubject); ?>" title="<?php echo h($commentText); ?>">
                  <?php echo clear_alarm_actions_cell([
                    'display'=>'Comentario',
                    'subject'=>$commentSubject,
                    'subject_label'=>'Pozo '.$detailWell,
                    'context'=>'top_pozos',
                    'preserve_pi_link'=>false,
                    'show_history'=>false,
                    'show_comment'=>true,
                    'has_comment'=>$hasComment,
                    'icon_only'=>true
                  ]); ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>
<?php clear_alarm_actions_modal(); ?>
<script src="assets/js/app.js?v=20260924-columns-1"></script>
<script src="assets/js/alarm_actions.js?v=20260826-central-1"></script>
<?php if($reportEnabled): ?><script src="assets/js/novedades_semanales.js?v=20260826-select-all-1"></script><?php endif; ?>
<script>
(function(){
  if(typeof Chart==='undefined') return;
  var common={responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{usePointStyle:true,boxWidth:8}}},scales:{x:{grid:{color:'rgba(26,77,92,.06)'},ticks:{color:'#607487'}},y:{beginAtZero:true,grid:{color:'rgba(26,77,92,.08)'},ticks:{color:'#607487'}}}};
  var hourLabels=<?php echo json_encode($hourlyLabels, JSON_UNESCAPED_UNICODE); ?>;
  new Chart(document.getElementById('tpHourlyTotal'),{type:'line',data:{labels:hourLabels,datasets:[{label:'Total por hora',data:<?php echo json_encode($hourlyTotals); ?>,borderColor:'#1a4d5c',backgroundColor:'rgba(26,77,92,.12)',borderWidth:3,pointRadius:3,tension:.28,fill:true}]},options:common});
  new Chart(document.getElementById('tpHourlyPriority'),{type:'bar',data:{labels:hourLabels,datasets:[
    {label:'Alta',data:<?php echo json_encode($hourlyHigh); ?>,backgroundColor:'rgba(239,68,68,.78)',borderRadius:5},
    {label:'Media',data:<?php echo json_encode($hourlyMedium); ?>,backgroundColor:'rgba(245,158,11,.78)',borderRadius:5},
    {label:'Baja',data:<?php echo json_encode($hourlyLow); ?>,backgroundColor:'rgba(16,185,129,.72)',borderRadius:5},
    {label:'Otra',data:<?php echo json_encode($hourlyOther); ?>,backgroundColor:'rgba(100,116,139,.55)',borderRadius:5}
  ]},options:Object.assign({},common,{scales:{x:{stacked:true,grid:{display:false},ticks:{maxRotation:0,autoSkip:true,maxTicksLimit:12}},y:{stacked:true,beginAtZero:true,grid:{color:'rgba(26,77,92,.08)'}}}})});

})();
</script>
<script>
(function(){
  var table=document.getElementById('topPozosDetailTable');
  if(!table)return;
  var filters=Array.prototype.slice.call(table.querySelectorAll('[data-tp-detail-filter="true"]'));
  function apply(){
    var rows=table.tBodies[0]?table.tBodies[0].rows:[];
    Array.prototype.forEach.call(rows,function(row){
      var visible=filters.every(function(filter){
        var needle=(filter.value||'').trim().toLocaleLowerCase('es-AR');
        if(!needle)return true;
        var th=filter.closest?filter.closest('th'):null;
        var index=th&&typeof th.cellIndex==='number'?th.cellIndex:0;
        var cell=row.cells[index];
        return cell&&(cell.textContent||'').toLocaleLowerCase('es-AR').indexOf(needle)!==-1;
      });
      row.hidden=!visible;
    });
  }
  filters.forEach(function(filter){
    filter.addEventListener('input',apply);
    filter.addEventListener('click',function(event){event.stopPropagation();});
  });
})();
</script>
</body>
</html>
