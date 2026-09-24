<?php
/* La página contenedora define $AS_TYPE ('P', 'I' o 'U') y $AS_MENU_KEY. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/alarm_actions.php';
require_once __DIR__ . '/alarmas_semanal_common.php';
require_once __DIR__ . '/novedades_semanales_common.php';

$AS_UNIFIED = !empty($AS_UNIFIED) || (isset($AS_TYPE) && $AS_TYPE === 'U');
$requestedInstallationType = strtoupper(trim((string)($_GET['tipo_instalacion'] ?? '')));
$AS_TYPE = $AS_UNIFIED ? ($requestedInstallationType === 'POZO' ? 'P' : 'I') : (isset($AS_TYPE) && $AS_TYPE === 'I' ? 'I' : 'P');
$AS_MENU_KEY = isset($AS_MENU_KEY) ? (string)$AS_MENU_KEY : ($AS_TYPE === 'P' ? 'pozos_alarmas_semanal' : 'instalaciones_alarmas_semanal');
auth_require();
permissions_require_menu($AS_MENU_KEY);

$cfg = require dirname(__DIR__) . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = $AS_MENU_KEY;
$db = clear_db();
$reportEnabled=permissions_can_menu('novedades_semanales_reporte');
$dbError = $db->ok() ? '' : $db->error();
$isWells = $AS_TYPE === 'P';
$showTypeColumn = $AS_UNIFIED || !$isWells;
$entityLabel = $isWells ? 'Pozo' : 'Instalación';
$pageLabel = $AS_UNIFIED ? 'Alarmas' : ($isWells ? 'Pozos' : 'Instalaciones');
$pageFile = $AS_UNIFIED ? 'instalaciones_alarmas_semanal.php' : ($isWells ? 'pozos_alarmas_semanal.php' : 'instalaciones_alarmas_semanal.php');

$today = new DateTimeImmutable('now');
$week = as_selected_week($_GET, $today);
$currentWeek = as_week_for_date($today);
$previousWeekDate = $week['start']->modify('-7 days')->format('Y-m-d');
$nextWeekDate = $week['start']->modify('+7 days')->format('Y-m-d');
$canGoNextWeek = $week['start'] < $currentWeek['start'];
$filters = as_build_filters($AS_TYPE, $_GET, $week);
$installationTypeOptions = as_installation_type_options();
if (!$isWells) unset($installationTypeOptions['POZO']);
if ($AS_UNIFIED && $isWells) $filters['installation_type'] = 'POZO';
if (!$isWells && $filters['installation_type'] === 'POZO') $filters['installation_type'] = '';
$selectedInstallationTypeLabel = $filters['installation_type'] !== '' ? ($installationTypeOptions[$filters['installation_type']] ?? '') : '';
$historyRangeStart = $filters['range_from'];
$historyRangeEnd = (new DateTimeImmutable($filters['range_to']))->modify('-1 second')->format('Y-m-d\TH:i:s');
$chartFilters = $filters;
$chartFilters['day'] = '';
$chartFilters['hour'] = '';
$perPage = (int)($_GET['por_pagina'] ?? 100);
if (!in_array($perPage, [50,100,200], true)) $perPage = 100;
$page = max(1, (int)($_GET['pagina'] ?? 1));
$offset = ($page - 1) * $perPage;

$cacheReady = false;
$cacheSupportsInstallationType = false;
$cacheFilters = $filters;
$cacheUpdated = '';
$daily = array_fill(0, 7, 0);
$dailyDates = [];
$hourlyTotals = array_fill(0, 24, 0);
$hourlyAverages = array_fill(0, 24, 0.0);
$entities = [];
$gridRows = [];
$gridTotal = 0;
$gridTotalCapped = false;
$sourceError = '';
$repeatCounts = [];
$commentKeys = [];
$statusOptions = [];
$priorityOptions = [];

for ($i=0; $i<7; $i++) $dailyDates[] = $week['start']->modify('+' . $i . ' days')->format('Y-m-d');

if ($db->ok()) {
    $cacheReady = (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_ALARMAS_SEMANA_CACHE',N'U') IS NULL THEN 0 ELSE 1 END") === 1;
    if ($cacheReady) {
        $cacheSupportsInstallationType = (int)$db->scalar("SELECT CASE WHEN COL_LENGTH(N'dbo.CLEAR_ALARMAS_SEMANA_CACHE',N'TIPO_INSTALACION') IS NULL THEN 0 ELSE 1 END") === 1;
    }
}

if (!$cacheSupportsInstallationType) {
    $chartFilters['installation_type'] = '';
    $cacheFilters['installation_type'] = '';
}

if ($db->ok() && $cacheReady) {
    $surfaceCacheCondition = (!$isWells && $cacheSupportsInstallationType) ? "UPPER(ISNULL(TIPO_INSTALACION,N''))<>N'POZO'" : '';
    $params = [];
    $conditions = as_cache_conditions($AS_TYPE, $chartFilters, $params);
    if ($surfaceCacheCondition !== '') $conditions[] = $surfaceCacheCondition;
    $where = 'WHERE ' . implode(' AND ', $conditions);

    foreach ($db->all("SELECT CONVERT(varchar(10),FECHA,23) AS FECHA,SUM(TOTAL) AS TOTAL FROM dbo.CLEAR_ALARMAS_SEMANA_CACHE $where GROUP BY FECHA ORDER BY FECHA", $params) as $row) {
        $date = (string)as_value($row, 'FECHA');
        $index = array_search($date, $dailyDates, true);
        if ($index !== false) $daily[$index] = (int)as_value($row, 'TOTAL', 0);
    }
    foreach ($db->all("SELECT HORA,SUM(TOTAL) AS TOTAL FROM dbo.CLEAR_ALARMAS_SEMANA_CACHE $where GROUP BY HORA ORDER BY HORA", $params) as $row) {
        $hour = (int)as_value($row, 'HORA', -1);
        if ($hour >= 0 && $hour <= 23) $hourlyTotals[$hour] = (int)as_value($row, 'TOTAL', 0);
    }
    $cacheUpdated = (string)$db->scalar("SELECT CONVERT(varchar(19),MAX(FECHA_ACTUALIZACION),120) FROM dbo.CLEAR_ALARMAS_SEMANA_CACHE WHERE TIPO=?", [$AS_TYPE]);

    $entityParams = [$AS_TYPE, $filters['from'], $filters['to_exclusive']];
    $entitySurfaceSql = $surfaceCacheCondition !== '' ? " AND $surfaceCacheCondition" : '';
    foreach ($db->all("SELECT DISTINCT ENTIDAD FROM dbo.CLEAR_ALARMAS_SEMANA_CACHE WHERE TIPO=? AND FECHA>=CONVERT(date,?,23) AND FECHA<CONVERT(date,?,23) AND ENTIDAD<>N''$entitySurfaceSql ORDER BY ENTIDAD", $entityParams) as $row) {
        $entity = trim((string)as_value($row, 'ENTIDAD'));
        if ($entity !== '') $entities[] = $entity;
    }

    $columns = as_column_lookup($db);
    $sourceParams = [];
    $source = as_source_parts($AS_TYPE, $filters, $columns, $sourceParams);
    if (!$source['ok']) {
        $sourceError = $source['error'];
    } else {
        $needsSourceCount = $filters['status'] !== '' || $filters['priority'] !== '' || ($filters['installation_type'] !== '' && !$cacheSupportsInstallationType);
        if ($needsSourceCount) {
            $countRows = $db->all("SELECT COUNT_BIG(*) AS TOTAL FROM (SELECT TOP 10001 1 AS X FROM dbo.FIXALARMS " . $source['where'] . ") C", $sourceParams);
            $gridTotal = $countRows ? (int)as_value($countRows[0], 'TOTAL', 0) : 0;
            if ($gridTotal > 10000) { $gridTotal = 10000; $gridTotalCapped = true; }
        } else {
            $countParams = [];
            $countConditions = as_cache_conditions($AS_TYPE, $cacheFilters, $countParams);
            if ($surfaceCacheCondition !== '') $countConditions[] = $surfaceCacheCondition;
            $gridTotal = (int)$db->scalar("SELECT COALESCE(SUM(TOTAL),0) FROM dbo.CLEAR_ALARMAS_SEMANA_CACHE WHERE " . implode(' AND ', $countConditions), $countParams);
        }

        $maxPage = max(1, (int)ceil($gridTotal / $perPage));
        if ($page > $maxPage) { $page = $maxPage; $offset = ($page - 1) * $perPage; }
        $select = ($showTypeColumn ? ($isWells ? "N'POZO'" : $source['installation_type']) . " AS TIPO_INSTALACION," : '') .
                  $source['date'] . " AS FECHA_HORA," .
                  $source['entity'] . " AS ENTIDAD," .
                  $source['tag'] . " AS TAG," .
                  $source['description'] . " AS DESCRIPCION," .
                  $source['status'] . " AS ESTADO," .
                  $source['priority'] . " AS PRIORIDAD," .
                  $source['value'] . " AS VALOR," .
                  $source['unit'] . " AS UNIDAD";
        $gridRows = $db->all("SELECT $select FROM dbo.FIXALARMS " . $source['where'] . " ORDER BY " . $source['date'] . " DESC OFFSET $offset ROWS FETCH NEXT $perPage ROWS ONLY", $sourceParams);

        /* Repeticiones por TAG desde el caché pequeño: nunca agrupa FIXALARMS. */
        $visibleTags = [];
        foreach ($gridRows as $gridRow) {
            $visibleTag = trim((string)as_value($gridRow, 'TAG'));
            if ($visibleTag !== '') $visibleTags[$visibleTag] = true;
            $visibleStatus = trim((string)as_value($gridRow, 'ESTADO'));
            $visiblePriority = trim((string)as_value($gridRow, 'PRIORIDAD'));
            if ($visibleStatus !== '') $statusOptions[$visibleStatus] = true;
            if ($visiblePriority !== '') $priorityOptions[$visiblePriority] = true;
        }
        if ($filters['status'] !== '') $statusOptions[$filters['status']] = true;
        if ($filters['priority'] !== '') $priorityOptions[$filters['priority']] = true;
        if ($visibleTags) {
            $repeatParams = [];
            $repeatConditions = as_cache_conditions($AS_TYPE, $cacheFilters, $repeatParams);
            if ($surfaceCacheCondition !== '') $repeatConditions[] = $surfaceCacheCondition;
            $placeholders = implode(',', array_fill(0, count($visibleTags), '?'));
            foreach (array_keys($visibleTags) as $visibleTag) $repeatParams[] = $visibleTag;
            foreach ($db->all("SELECT TAG,SUM(TOTAL) AS REPETICIONES FROM dbo.CLEAR_ALARMAS_SEMANA_CACHE WHERE " . implode(' AND ', $repeatConditions) . " AND TAG IN ($placeholders) GROUP BY TAG", $repeatParams) as $repeatRow) {
                $repeatCounts[trim((string)as_value($repeatRow,'TAG'))] = (int)as_value($repeatRow,'REPETICIONES',0);
            }

            /* Señalar comentarios centrales existentes sin consultar uno por uno. */
            if (permissions_can('comments.view') && (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.FIXALARMS_COMENTARIOS',N'U') IS NULL THEN 0 ELSE 1 END") === 1) {
                $commentKeys = clear_alarm_comments_load_subjects_sql(array_keys($visibleTags));
            }
        }
    }
}

$statusOptions = array_keys($statusOptions);
$priorityOptions = array_keys($priorityOptions);
natcasesort($statusOptions);
natcasesort($priorityOptions);

$todayDate = $today->setTime(0,0,0);
if ($todayDate < $week['start']) $daysDivisor = 1;
elseif ($todayDate >= $week['end']) $daysDivisor = 7;
else $daysDivisor = min(7, max(1, (int)$week['start']->diff($todayDate)->format('%a') + 1));
for ($hour=0; $hour<24; $hour++) $hourlyAverages[$hour] = round($hourlyTotals[$hour] / $daysDivisor, 1);
$weeklyTotal = array_sum($daily);
$dailyAverage = $weeklyTotal / $daysDivisor;
$hourAverage = $weeklyTotal / ($daysDivisor * 24);
$peakIndex = 0;
for ($i=1; $i<7; $i++) if ($daily[$i] > $daily[$peakIndex]) $peakIndex = $i;
$dayNames = ['Miércoles','Jueves','Viernes','Sábado','Domingo','Lunes','Martes'];
$dayShort = ['Mié','Jue','Vie','Sáb','Dom','Lun','Mar'];
$dayLabels = [];
for ($i=0; $i<7; $i++) $dayLabels[] = $dayShort[$i] . ' ' . $week['start']->modify('+' . $i . ' days')->format('d');
$hourLabels = [];
for ($i=0; $i<24; $i++) $hourLabels[] = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
$lastRow = min($gridTotal, $offset + count($gridRows));
$totalPages = max(1, (int)ceil($gridTotal / $perPage));
$gridColumnCount = ($showTypeColumn ? 10 : 9)+($reportEnabled?1:0);
$sortOffset = $showTypeColumn ? 1 : 0;

function as_page_date($value)
{
    if ($value instanceof DateTimeInterface) return $value->format('d/m/Y H:i:s');
    $timestamp = strtotime((string)$value);
    return $timestamp ? date('d/m/Y H:i:s', $timestamp) : (string)$value;
}

function as_page_badge_class($value, $kind)
{
    $value = strtoupper(trim((string)$value));
    if ($kind === 'priority') {
        if (strpos($value,'HIGH')!==false || strpos($value,'ALTA')!==false || strpos($value,'CRIT')!==false) return 'is-red';
        if (strpos($value,'MED')!==false) return 'is-amber';
        return 'is-blue';
    }
    if (strpos($value,'ACK')!==false || strpos($value,'RECON')!==false || strpos($value,'NORMAL')!==false) return 'is-blue';
    if (strpos($value,'ACT')!==false || strpos($value,'UNACK')!==false || strpos($value,'ALAR')!==false) return 'is-red';
    return 'is-muted';
}

function as_page_installation_type_class($value)
{
    $value = strtoupper(trim((string)$value));
    if ($value === 'POZO') return 'is-well';
    if ($value === 'BATERÍA' || $value === 'BATERIA') return 'is-battery';
    if ($value === 'SATÉLITE' || $value === 'SATELITE') return 'is-satellite';
    if ($value === 'GAS') return 'is-gas';
    if ($value === 'PIAS') return 'is-pias';
    if ($value === 'ENERGÍA' || $value === 'ENERGIA') return 'is-energy';
    if ($value === 'PLANTA TRAT.' || $value === 'PLANTA TRAT') return 'is-plant';
    if ($value === 'PLANTA LH') return 'is-plant-lh';
    return 'is-unknown';
}

function as_page_hidden_query(array $exclude)
{
    foreach ($_GET as $key=>$value) {
        if (in_array($key, $exclude, true) || is_array($value) || $value === '') continue;
        echo '<input type="hidden" name="' . h($key) . '" value="' . h($value) . '">';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo $AS_UNIFIED ? 'Alarmas semanal' : 'Alarmas semanal · '.h($pageLabel); ?> · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260924-columns-1">
  <link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260824-as3">
  <link rel="stylesheet" href="assets/css/alarmas_semanal.css?v=20260824-as6">
  <?php if($reportEnabled): ?><link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260826-report-common-1"><?php endif; ?>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <main class="main">
    <?php include __DIR__ . '/topbar.php'; ?>
    <div class="page__head asPageHead">
      <div>
        <h1 class="page__title"><?php echo $AS_UNIFIED ? 'Alarmas semanal' : 'Alarmas semanal · '.h($pageLabel); ?></h1>
        <div class="page__sub"><?php echo $AS_UNIFIED ? 'Análisis unificado de pozos e instalaciones' : ('Análisis de alarmas de '.($isWells ? 'pozos' : 'instalaciones de superficie')); ?> · semana de miércoles a martes</div>
      </div>
      <div class="page__live"><span class="dot"></span><?php echo ($dbError || !$cacheReady) ? 'Requiere configuración' : 'En vivo'; ?></div>
    </div>

    <?php if ($dbError): ?>
      <div class="asNotice is-error"><b>Sin conexión:</b> <?php echo h($dbError); ?></div>
    <?php elseif (!$cacheReady): ?>
      <div class="asNotice is-warning"><b>Falta instalar el caché semanal.</b> Ejecutá una sola vez <code>SQL/CLEAR_ALARMAS_SEMANAL_CACHE_JOB.sql</code> en la base de datos de la plataforma.</div>
    <?php else: ?>
      <form class="asToolbar" method="get" action="<?php echo h($pageFile); ?>" id="asToolbar">
        <div class="asWeekPicker">
          <span class="asWeekPicker__label">Semana</span>
          <div class="asWeekPicker__row">
            <a class="asWeekPicker__nav" href="<?php echo h($pageFile . '?' . as_query_string(['semana'=>$previousWeekDate], ['dia','hora','pagina'])); ?>" title="Semana anterior" aria-label="Semana anterior">‹</a>
            <label class="asWeekPicker__date" for="asWeekDate"><?php echo icon('calendar'); ?><input type="date" id="asWeekDate" name="semana" value="<?php echo h($filters['from']); ?>" max="<?php echo h($today->format('Y-m-d')); ?>" title="Elegí cualquier día; se mostrará su semana de miércoles a martes"></label>
            <?php if($canGoNextWeek): ?><a class="asWeekPicker__nav" href="<?php echo h($pageFile . '?' . as_query_string(['semana'=>$nextWeekDate], ['dia','hora','pagina'])); ?>" title="Semana siguiente" aria-label="Semana siguiente">›</a><?php else: ?><span class="asWeekPicker__nav is-disabled" aria-hidden="true">›</span><?php endif; ?>
          </div>
          <small><?php echo h($week['start']->format('d/m/Y')); ?> al <?php echo h($week['end']->format('d/m/Y')); ?></small>
        </div>
        <?php if ($isWells && !$AS_UNIFIED): ?>
          <label class="asControl"><span>Prefijo de pozo</span><input type="text" name="prefijo" value="<?php echo h($filters['prefix']); ?>" readonly title="Prefijo operativo de pozos"></label>
        <?php elseif (!$isWells): ?>
          <label class="asControl"><span>Instalación</span><select name="instalacion"><option value="">Todas</option><?php foreach($entities as $entity): ?><option value="<?php echo h($entity); ?>" <?php echo $filters['installation']===$entity?'selected':''; ?>><?php echo h($entity); ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <label class="asControl asControl--search"><?php echo icon('search'); ?><span>Buscar</span><input type="search" name="q" value="<?php echo h($filters['q']); ?>" placeholder="Buscar <?php echo $isWells?'pozo, TAG o descripción':'instalación, TAG o descripción'; ?>…"></label>
        <label class="asControl"><span>Actualizar</span><select name="refresh" id="asRefresh"><option value="0" selected>Desactivada</option><option value="300">5 minutos</option><option value="600">10 minutos</option></select></label>
        <button type="submit" class="toolbar__dateApply">Aplicar</button>
        <a class="btn-export" href="alarmas_semanal_export.php?<?php echo h(as_query_string(['tipo'=>$AS_TYPE,'unificada'=>$AS_UNIFIED?'1':null], ['pagina','refresh'])); ?>"><?php echo icon('download'); ?> Exportar Excel</a>
      </form>

      <?php if(!$isWells && !$cacheSupportsInstallationType): ?><div class="asNotice is-warning"><b>Falta actualizar el caché por tipo de instalación.</b> Ejecutá una vez <code>SQL/CLEAR_ALARMAS_SEMANAL_CACHE_JOB.sql</code>; hasta entonces el combo filtrará la grilla, pero no los gráficos.</div><?php endif; ?>

      <section class="asStats">
        <article class="stat acc-blue"><div class="stat__label">Total semanal</div><div class="stat__value is-blue"><?php echo as_num($weeklyTotal); ?></div><div class="stat__detail"><?php echo h($week['start']->format('d/m/Y')); ?> al <?php echo h($week['end']->format('d/m/Y')); ?></div></article>
        <article class="stat acc-green"><div class="stat__label">Promedio diario</div><div class="stat__value is-green"><?php echo as_num($dailyAverage,1); ?></div><div class="stat__detail">Calculado sobre <?php echo (int)$daysDivisor; ?> día<?php echo $daysDivisor===1?'':'s'; ?> de la semana.</div></article>
        <article class="stat acc-amber"><div class="stat__label">Promedio por hora</div><div class="stat__value is-amber"><?php echo as_num($hourAverage,1); ?></div><div class="stat__detail">Promedio general de la semana.</div></article>
        <article class="stat acc-red"><div class="stat__label">Día pico</div><div class="stat__value is-red asPeakValue"><?php echo $weeklyTotal ? h($dayNames[$peakIndex]) : 'Sin datos'; ?></div><div class="stat__detail"><b><?php echo as_num($weeklyTotal ? $daily[$peakIndex] : 0); ?></b> alarmas.</div></article>
      </section>

      <section class="asCharts">
        <article class="asChartCard asChartCard--daily">
          <div class="asChartHead"><div><h2>Cantidad de alarmas por día</h2><p><?php echo $selectedInstallationTypeLabel!==''?'Tipo: '.h($selectedInstallationTypeLabel).' · ':''; ?>Seleccioná una barra para filtrar la grilla.</p></div><div><?php if($reportEnabled)echo ns_report_pick(ns_report_key('alarmas-semanal-dia',[$filters['from'],$daily]),'chart','Alarmas semanales por día',['kind'=>'chart','chart_type'=>'bar','labels'=>$dayLabels,'datasets'=>[['label'=>'Alarmas','values'=>$daily]]]); ?><button type="button" class="asChartReset" data-clear-chart-filter <?php echo $filters['day']===''?'hidden':''; ?>>Quitar día</button></div></div>
          <div class="asChartBox"><canvas id="asDailyChart"></canvas></div>
        </article>
        <article class="asChartCard">
          <div class="asChartHead"><div><h2>Promedio de alarmas por hora</h2><p><?php echo $selectedInstallationTypeLabel!==''?'Tipo: '.h($selectedInstallationTypeLabel).' · ':''; ?>Promedio horario durante la semana seleccionada.</p></div><div><?php if($reportEnabled)echo ns_report_pick(ns_report_key('alarmas-semanal-hora',[$filters['from'],$hourlyAverages]),'chart','Alarmas semanales por hora',['kind'=>'chart','chart_type'=>'bar','labels'=>$hourLabels,'datasets'=>[['label'=>'Promedio','values'=>$hourlyAverages]]]); ?><button type="button" class="asChartReset" data-clear-hour-filter <?php echo $filters['hour']===''?'hidden':''; ?>>Quitar hora</button></div></div>
          <div class="asChartBox"><canvas id="asHourlyChart"></canvas></div>
        </article>
      </section>

      <section class="asGridCard">
        <div class="asGridHead">
          <div class="asGridTitle"><h2>Detalle de alarmas</h2>
            <?php if ($filters['day']!==''): ?><a class="asFilterChip" href="<?php echo h($pageFile . '?' . as_query_string([], ['dia','pagina'])); ?>">Día: <?php echo h(date('d/m/Y', strtotime($filters['day']))); ?> ×</a><?php endif; ?>
            <?php if ($filters['hour']!==''): ?><a class="asFilterChip" href="<?php echo h($pageFile . '?' . as_query_string([], ['hora','pagina'])); ?>">Hora: <?php echo h(str_pad($filters['hour'],2,'0',STR_PAD_LEFT)); ?>:00 ×</a><?php endif; ?>
            <?php if ($showTypeColumn && $selectedInstallationTypeLabel!==''): ?><a class="asFilterChip" href="<?php echo h($pageFile . '?' . as_query_string([], ['tipo_instalacion','pagina'])); ?>">Tipo: <?php echo h($selectedInstallationTypeLabel); ?> ×</a><?php endif; ?>
          </div>
          <div class="asGridMeta"><b><?php echo as_num($gridTotal); ?><?php echo $gridTotalCapped?'+':''; ?></b> registros · <?php echo $gridTotal ? as_num($offset+1) . '–' . as_num($lastRow) : '0'; ?></div>
        </div>
        <?php if($reportEnabled): ?><div class="nsReportTools"><button class="nsButton" type="button" data-clear-report-add-selected>Agregar seleccionadas al reporte</button><button class="nsButton is-secondary" type="button" data-ns-report-open>Ver y enviar reporte <span class="nsReportCount" data-ns-report-count hidden>0</span></button></div><?php endif; ?>

        <?php if ($sourceError): ?><div class="asNotice is-error"><?php echo h($sourceError); ?></div><?php endif; ?>
        <form method="get" action="<?php echo h($pageFile); ?>" id="asGridFilters">
          <?php as_page_hidden_query(['tipo_instalacion','entidad','tag','descripcion','estado','prioridad','pagina']); ?>
          <div class="tablescroll"><table class="grid asGrid">
            <thead>
              <tr class="asSortRow">
                <?php if($showTypeColumn): ?><th><button type="button" class="asSortButton" data-sort-column="0" data-sort-type="text">TIPO DE INSTALACIÓN <span>↕</span></button></th><?php endif; ?>
                <th><button type="button" class="asSortButton is-active" data-sort-column="<?php echo $sortOffset; ?>" data-sort-type="date" aria-sort="descending">FECHA Y HORA <span>↓</span></button></th>
                <th><button type="button" class="asSortButton" data-sort-column="<?php echo $sortOffset+1; ?>" data-sort-type="text"><?php echo strtoupper(h($entityLabel)); ?> <span>↕</span></button></th>
                <th><button type="button" class="asSortButton" data-sort-column="<?php echo $sortOffset+2; ?>" data-sort-type="text">TAG <span>↕</span></button></th>
                <th><button type="button" class="asSortButton" data-sort-column="<?php echo $sortOffset+3; ?>" data-sort-type="number">APARICIONES <span>↕</span></button></th>
                <th><button type="button" class="asSortButton" data-sort-column="<?php echo $sortOffset+4; ?>" data-sort-type="text">DESCRIPCIÓN <span>↕</span></button></th>
                <th><button type="button" class="asSortButton" data-sort-column="<?php echo $sortOffset+5; ?>" data-sort-type="text">ESTADO <span>↕</span></button></th>
                <th><button type="button" class="asSortButton" data-sort-column="<?php echo $sortOffset+6; ?>" data-sort-type="text">PRIORIDAD <span>↕</span></button></th>
                <th><button type="button" class="asSortButton" data-sort-column="<?php echo $sortOffset+7; ?>" data-sort-type="number">VALOR <span>↕</span></button></th>
                <th><button type="button" class="asSortButton" data-sort-column="<?php echo $sortOffset+8; ?>" data-sort-type="number">COMENTARIO <span>↕</span></button></th>
                <?php if($reportEnabled): ?><th>REPORTE</th><?php endif; ?>
              </tr>
              <tr class="asFilterRow">
                <?php if($showTypeColumn): ?><th><select name="tipo_instalacion" aria-label="Filtrar por tipo de instalación"><option value="">Todos</option><?php foreach($installationTypeOptions as $typeValue=>$typeLabel): ?><option value="<?php echo h($typeValue); ?>" <?php echo $filters['installation_type']===$typeValue?'selected':''; ?>><?php echo h($typeLabel); ?></option><?php endforeach; ?></select></th><?php endif; ?>
                <th><span class="asFilterHint"><?php echo $filters['day']!==''?h(date('d/m/Y',strtotime($filters['day']))):'Semana completa'; ?></span></th>
                <th><input name="entidad" value="<?php echo h($filters['entity']); ?>" placeholder="Buscar <?php echo strtolower(h($entityLabel)); ?>…"></th>
                <th><input name="tag" value="<?php echo h($filters['tag']); ?>" placeholder="Buscar TAG…"></th>
                <th><span class="asFilterHint">En la selección</span></th>
                <th><input name="descripcion" value="<?php echo h($filters['description']); ?>" placeholder="Buscar descripción…"></th>
                <th><select name="estado"><option value="">Todos</option><?php foreach($statusOptions as $option): ?><option value="<?php echo h($option); ?>" <?php echo $filters['status']===$option?'selected':''; ?>><?php echo h($option); ?></option><?php endforeach; ?></select></th>
                <th><select name="prioridad"><option value="">Todas</option><?php foreach($priorityOptions as $option): ?><option value="<?php echo h($option); ?>" <?php echo $filters['priority']===$option?'selected':''; ?>><?php echo h($option); ?></option><?php endforeach; ?></select></th>
                <th><button type="submit" class="asFilterButton">Filtrar</button></th>
                <th><span class="asFilterHint">Abrir comentario</span></th>
                <?php if($reportEnabled): ?><th><span class="asFilterHint">Seleccionar</span></th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
            <?php if (!$gridRows): ?><tr><td colspan="<?php echo $gridColumnCount; ?>" class="asEmpty">No se encontraron alarmas con los filtros seleccionados.</td></tr><?php else: foreach($gridRows as $row):
              $tag = trim((string)as_value($row,'TAG'));
              $timestamp = (string)as_value($row,'FECHA_HORA');
              $status = trim((string)as_value($row,'ESTADO'));
              $priority = trim((string)as_value($row,'PRIORIDAD'));
              $value = trim((string)as_value($row,'VALOR'));
              $unit = trim((string)as_value($row,'UNIDAD'));
              $description = trim((string)as_value($row,'DESCRIPCION'));
              $repetitions = (int)($repeatCounts[$tag] ?? 0);
              $normalizedTimestamp = clear_alarm_event_normalize_datetime($timestamp);
              $hasComment = isset($commentKeys[strtoupper($tag)]);
              $installationType = trim((string)as_value($row,'TIPO_INSTALACION'));
              $commentRow=$commentKeys[strtoupper($tag)]??[];$commentText=trim((string)($commentRow['COMENTARIO']??$commentRow['comentario']??''));
              $reportColumns=['Fecha y hora'=>as_page_date($timestamp),$entityLabel=>as_value($row,'ENTIDAD'),'TAG'=>$tag,'Apariciones'=>$repetitions,'Descripción'=>$description,'Estado'=>$status,'Prioridad'=>$priority,'Valor'=>trim($value.($unit!==''?' '.$unit:''))];if($commentText!=='')$reportColumns['Comentario']=$commentText;
            ?>
              <tr>
                <?php if($showTypeColumn): ?><td data-sort-value="<?php echo h($installationType); ?>"><span class="asTypeBadge <?php echo h(as_page_installation_type_class($installationType)); ?>"><?php echo h($installationType!==''?$installationType:'SIN CLASIFICAR'); ?></span></td><?php endif; ?>
                <td class="mono" data-sort-value="<?php echo h($normalizedTimestamp); ?>"><?php echo h(as_page_date($timestamp)); ?></td>
                <td data-sort-value="<?php echo h(as_value($row,'ENTIDAD')); ?>"><b><?php echo h(as_value($row,'ENTIDAD')); ?></b></td>
                <td data-sort-value="<?php echo h($tag); ?>"><span class="asTagText"><?php echo h($tag!==''?$tag:'—'); ?></span></td>
                <td data-sort-value="<?php echo (int)$repetitions; ?>"><button type="button" class="asRepeatButton" data-sql-history="true" data-sql-tag="<?php echo h($tag); ?>" data-sql-start="<?php echo h($historyRangeStart); ?>" data-sql-end="<?php echo h($historyRangeEnd); ?>" title="Ver las <?php echo as_num($repetitions); ?> apariciones y el gráfico histórico en SQL"><b><?php echo as_num($repetitions); ?></b><?php echo icon('trend'); ?></button></td>
                <td data-sort-value="<?php echo h($description); ?>"><?php echo h($description); ?></td>
                <td data-sort-value="<?php echo h($status); ?>"><span class="asBadge <?php echo h(as_page_badge_class($status,'status')); ?>"><?php echo h($status!==''?$status:'—'); ?></span></td>
                <td data-sort-value="<?php echo h($priority); ?>"><span class="asBadge <?php echo h(as_page_badge_class($priority,'priority')); ?>"><?php echo h($priority!==''?$priority:'—'); ?></span></td>
                <td data-sort-value="<?php echo h($value); ?>"><?php echo h(trim($value . ($unit!==''?' '.$unit:'')) ?: '—'); ?></td>
                <td class="asCommentCell" data-sort-value="<?php echo $hasComment ? '1' : '0'; ?>"><?php echo clear_alarm_actions_cell(['display'=>'Comentario','tag'=>$tag,'timestamp'=>$timestamp,'value'=>$value,'description'=>$description,'preserve_pi_link'=>false,'show_history'=>false,'show_comment'=>true,'has_comment'=>$hasComment,'context'=>'alarmas_semanal']); ?></td>
                <?php if($reportEnabled): ?><td><?php echo ns_report_pick(ns_report_key('alarmas-semanal',[$tag,$timestamp,$value]),'row','Alarma semanal · '.$tag,ns_report_row_payload($reportColumns),'Incluir'); ?></td><?php endif; ?>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table></div>
        </form>

        <div class="asPager">
          <label>Filas <select id="asPerPage"><option value="50" <?php echo $perPage===50?'selected':''; ?>>50</option><option value="100" <?php echo $perPage===100?'selected':''; ?>>100</option><option value="200" <?php echo $perPage===200?'selected':''; ?>>200</option></select></label>
          <div><a class="<?php echo $page<=1?'is-disabled':''; ?>" href="<?php echo h($pageFile.'?'.as_query_string(['pagina'=>max(1,$page-1)])); ?>">‹</a><span>Página <?php echo (int)$page; ?> de <?php echo (int)$totalPages; ?></span><a class="<?php echo $page>=$totalPages?'is-disabled':''; ?>" href="<?php echo h($pageFile.'?'.as_query_string(['pagina'=>min($totalPages,$page+1)])); ?>">›</a></div>
        </div>
      </section>
      <div class="asCacheNote">Datos gráficos resumidos · última actualización: <b><?php echo h($cacheUpdated!==''?as_page_date($cacheUpdated):'sin datos'); ?></b></div>
    <?php endif; ?>
  </main>
</div>

<?php clear_alarm_actions_modal(); ?>

<?php if ($cacheReady): ?>
<script>
window.CLEAR_WEEKLY_ALARMS = <?php echo json_encode([
  'page'=>$pageFile,
  'dailyLabels'=>$dayLabels,
  'dailyDates'=>$dailyDates,
  'dailyValues'=>$daily,
  'hourLabels'=>$hourLabels,
  'hourValues'=>$hourlyAverages,
  'selectedDay'=>$filters['day'],
  'selectedHour'=>$filters['hour'],
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/js/chart.umd.js"></script>
<script src="assets/js/app.js?v=20260924-columns-1"></script>
<script src="assets/js/alarm_actions.js?v=20260826-central-1"></script>
<script src="assets/js/alarmas_semanal.js?v=20260826-perf-safe-2"></script>
<?php if($reportEnabled): ?><script src="assets/js/novedades_semanales.js?v=20260901-report-chart-2"></script><?php endif; ?>
<?php else: ?><script src="assets/js/app.js?v=20260924-columns-1"></script><?php endif; ?>
</body>
</html>
