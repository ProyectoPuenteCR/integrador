<?php
/* =============================================================
   CLEAR PLATAFORMA — sin_telemetria_zafiro.php
   Pozos con Zafiro Activo = "Sin dato" (sin telemetría en Zafiro).
   Captura diaria luego de la sincronización Zafiro y comparativa
   semanal (lunes a domingo) para seguir la normalización.
   Requiere SQL/CLEAR_ZAFIRO_SIN_TELEMETRIA_JOB.sql
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/novedades_semanales_common.php';
require_once __DIR__ . '/includes/zafiro_sin_telemetria.php';

$ZST_MENU_KEY = 'sin_telemetria_zafiro';
auth_require();
permissions_require_menu($ZST_MENU_KEY);

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = $ZST_MENU_KEY;
$isAdmin = auth_es_admin();
$db = clear_db();
$dbError = $db->ok() ? '' : $db->error();
$ready = !$dbError && zst_ready($db);
$reportEnabled = $ready && ns_report_ready($db) && permissions_can_menu('novedades_semanales_reporte');
$autoReportEnabled = permissions_can_menu('reportes');

/* Captura manual (solo administradores). */
if (empty($_SESSION['zst_csrf'])) $_SESSION['zst_csrf'] = bin2hex(random_bytes(16));
$flash = $_SESSION['zst_flash'] ?? null;
unset($_SESSION['zst_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'capturar') {
    $message = ['type' => 'error', 'text' => 'No se pudo ejecutar la captura.'];
    if (!$isAdmin || !hash_equals((string)$_SESSION['zst_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $message['text'] = 'La captura manual solo está disponible para administradores.';
    } elseif ($ready) {
        $result = $db->all('EXEC dbo.SP_CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA @Forzar=1');
        if ($result) {
            $message = ['type' => 'ok', 'text' => (string)zst_value($result[0], 'RESULTADO', 'Captura guardada.')];
            audit_log('ACTUALIZACION', $ZST_MENU_KEY, 'Captura manual de pozos sin telemetría en Zafiro');
        } elseif ($db->error()) {
            $message['text'] = 'La captura falló: ' . trim(strip_tags((string)$db->error()));
        }
    }
    $_SESSION['zst_flash'] = $message;
    header('Location: sin_telemetria_zafiro.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

$filters = zst_filters($_GET);
$data = $ready ? zst_load($db, $filters) : null;
if ($data && !$data['ok'] && $data['error'] !== '') $dbError = $data['error'];

function zst_url(array $set = [], array $unset = [])
{
    $query = $_GET;
    foreach ($unset as $key) unset($query[$key]);
    foreach ($set as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]); else $query[$key] = $value;
    }
    return 'sin_telemetria_zafiro.php' . ($query ? '?' . http_build_query($query) : '');
}

function zst_num($value)
{
    return number_format((float)$value, 0, ',', '.');
}

$hasData = $data && $data['hasData'];
$current = $hasData ? count($data['current']) : 0;
$previousCount = ($hasData && $data['previous'] !== null) ? count($data['previous']) : null;
$delta = $previousCount === null ? null : $current - $previousCount;
$normalizedCount = $hasData ? count($data['normalized']) : 0;
$newCount = $hasData ? count($data['newWells']) : 0;
$weekRun = $hasData ? $data['weekRun'] : null;
$latestRun = $hasData ? $data['latestRun'] : null;
$productionOilTotal = $hasData ? (float)($data['productionOilTotal'] ?? 0) : 0.0;
$productionLiquidTotal = $hasData ? (float)($data['productionLiquidTotal'] ?? 0) : 0.0;
$dimensionFiltered = $filters['zone'] !== '' || $filters['battery'] !== '' || $filters['telemetry'] !== '';
$totalGrid = $weekRun['totalGrid'] ?? 0;
$pctWithout = (!$dimensionFiltered && $totalGrid > 0) ? $current * 100 / $totalGrid : null;
$weekStart = $hasData ? $data['weekStart'] : null;
$weekEnd = $hasData ? $data['weekEnd'] : null;
$weekKey = $weekStart ? $weekStart->format('Y-m-d') : '';
$canPrev = $hasData && $weekKey > $data['minWeek'];
$canNext = $hasData && $weekKey < $data['maxWeek'];
$weekLabel = $weekStart ? zst_week_label($weekStart) : '';
$chartLabels = []; $chartCounts = []; $chartNew = []; $chartNormalized = []; $chartWeeks = [];
if ($hasData) {
    foreach ($data['chart'] as $point) {
        $chartLabels[] = [$point['label'], $point['weekLabel']];
        $chartCounts[] = $point['count'];
        $chartNew[] = $point['newWells'];
        $chartNormalized[] = $point['normalized'];
        $chartWeeks[] = $point['week'];
    }
}
$viewTitles = [
    'sin' => 'Pozos sin telemetría (Zafiro Activo sin dato)',
    'nuevos' => 'Pozos que pasaron a sin telemetría esta semana',
    'normalizados' => 'Pozos normalizados: volvieron a tener telemetría',
];
$zafiroStale = $latestRun && !$latestRun['zafiroSameDay'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sin telemetría en Zafiro · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260824-as1">
  <link rel="stylesheet" href="assets/css/sin_telemetria_zafiro.css?v=20260922-2">
  <?php if($reportEnabled): ?><link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260826-report-common-1"><?php endif; ?>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <header class="zstHead">
      <div class="zstHead__title">
        <span class="zstHead__icon"><?php echo icon('signal-off'); ?></span>
        <div>
          <h1>Sin telemetría en Zafiro</h1>
          <p>Pozos con Zafiro Activo sin dato. Se guarda una captura por día después de la sincronización de Zafiro y se compara semana a semana.</p>
        </div>
      </div>
      <div class="zstHead__side">
        <?php if ($latestRun): ?>
          <div class="zstSync <?php echo $zafiroStale ? 'is-stale' : ''; ?>" title="<?php echo $zafiroStale ? 'La última captura se tomó sin una sincronización Zafiro del día.' : 'Captura tomada con la sincronización Zafiro del día.'; ?>">
            <?php echo icon('calendar'); ?>
            <div><span>Última sincronización Zafiro</span><b><?php echo h(zst_fmt_datetime($latestRun['zafiro'])); ?></b></div>
            <span class="zstSync__state"><?php echo $zafiroStale ? '!' : icon('check'); ?></span>
          </div>
        <?php endif; ?>
        <a class="zstBtn is-ghost" href="<?php echo h(zst_url()); ?>"><?php echo icon('history'); ?> Actualizar</a>
        <?php if ($autoReportEnabled): ?><a class="zstBtn is-primary" href="reportes.php?pantalla=sin_telemetria_zafiro"><?php echo icon('file'); ?> Agregar a reporte automático</a><?php endif; ?>
        <?php if ($isAdmin && $ready): ?>
          <form method="post" action="<?php echo h(zst_url()); ?>" class="zstCaptureForm" onsubmit="return confirm('¿Capturar ahora el listado del día? Reemplaza la captura de hoy.');">
            <input type="hidden" name="accion" value="capturar">
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['zst_csrf']); ?>">
            <button type="submit" class="zstBtn is-ghost" title="Solo administradores">Capturar ahora</button>
          </form>
        <?php endif; ?>
      </div>
    </header>

    <?php if ($flash): ?><div class="zstNotice <?php echo $flash['type'] === 'ok' ? 'is-ok' : 'is-error'; ?>"><?php echo h($flash['text']); ?></div><?php endif; ?>

    <?php if ($dbError): ?>
      <div class="zstNotice is-error"><b>Sin conexión:</b> <?php echo h($dbError); ?></div>
    <?php elseif (!$ready): ?>
      <div class="zstNotice is-warning"><b>Falta instalar la captura diaria.</b> Ejecutá una sola vez <code>SQL/CLEAR_ZAFIRO_SIN_TELEMETRIA_JOB.sql</code> en LC_MDB. Crea las tablas, toma la primera captura y programa el job.</div>
    <?php elseif (!$hasData): ?>
      <div class="zstNotice is-warning"><b>Todavía no hay capturas.</b> El job <code>CLEAR - Pozos sin telemetria Zafiro</code> guarda la primera cuando Zafiro sincronice. Un administrador puede usar <b>Capturar ahora</b>.</div>
    <?php else: ?>

      <section class="zstKpis">
        <a class="zstKpi is-red <?php echo $filters['view']==='sin'?'is-active':''; ?>" href="<?php echo h(zst_url(['vista'=>null])); ?>">
          <span class="zstKpi__icon"><?php echo icon('signal-off'); ?></span>
          <div>
            <span class="zstKpi__label">Pozos sin telemetría</span>
            <b class="zstKpi__value"><?php echo zst_num($current); ?></b>
            <span class="zstKpi__detail">
              <?php if ($delta === null): ?>Sin semana anterior para comparar
              <?php elseif ($delta === 0): ?>Igual que la semana anterior
              <?php else: ?><em class="<?php echo $delta > 0 ? 'is-up' : 'is-down'; ?>"><?php echo $delta > 0 ? '▲ +' : '▼ '; ?><?php echo zst_num($delta); ?></em> vs. semana anterior<?php endif; ?>
            </span>
          </div>
        </a>
        <a class="zstKpi is-green <?php echo $filters['view']==='normalizados'?'is-active':''; ?>" href="<?php echo h(zst_url(['vista'=>'normalizados'])); ?>">
          <span class="zstKpi__icon"><?php echo icon('check'); ?></span>
          <div>
            <span class="zstKpi__label">Normalizados (última semana)</span>
            <b class="zstKpi__value"><?php echo $previousCount === null ? '—' : zst_num($normalizedCount); ?></b>
            <span class="zstKpi__detail">Pozos que volvieron con telemetría</span>
          </div>
        </a>
        <a class="zstKpi is-amber <?php echo $filters['view']==='nuevos'?'is-active':''; ?>" href="<?php echo h(zst_url(['vista'=>'nuevos'])); ?>">
          <span class="zstKpi__icon"><?php echo icon('history'); ?></span>
          <div>
            <span class="zstKpi__label">Nuevos sin telemetría</span>
            <b class="zstKpi__value"><?php echo $previousCount === null ? '—' : zst_num($newCount); ?></b>
            <span class="zstKpi__detail">Detectados esta semana</span>
          </div>
        </a>
        <a class="zstKpi is-blue" href="telemetria_general.php">
          <span class="zstKpi__icon"><?php echo icon('oil'); ?></span>
          <div>
            <span class="zstKpi__label">Total pozos en grilla general</span>
            <b class="zstKpi__value"><?php echo zst_num($totalGrid); ?></b>
            <span class="zstKpi__detail">
              <?php if ($pctWithout !== null): ?><b><?php echo number_format($pctWithout, 1, ',', '.'); ?> %</b> sin telemetría · <?php endif; ?>
              <?php echo zst_num($weekRun['totalZafiroActive'] ?? 0); ?> activos en Zafiro
            </span>
          </div>
        </a>
        <div class="zstKpi is-oil">
          <span class="zstKpi__icon"><?php echo icon('oil'); ?></span>
          <div>
            <span class="zstKpi__label">Producción petróleo</span>
            <b class="zstKpi__value"><?php echo number_format($productionOilTotal, 2, ',', '.'); ?></b>
            <span class="zstKpi__detail">Total de los pozos mostrados · Query 164</span>
          </div>
        </div>
        <div class="zstKpi is-bruta">
          <span class="zstKpi__icon"><?php echo icon('gauge'); ?></span>
          <div>
            <span class="zstKpi__label">Producción Bruta</span>
            <b class="zstKpi__value"><?php echo number_format($productionLiquidTotal, 2, ',', '.'); ?></b>
            <span class="zstKpi__detail">Suma de Producción Líquido · Query 164</span>
          </div>
        </div>
      </section>

      <section class="zstChartCard">
        <div class="zstChartMain">
          <div class="zstCardHead">
            <h2><?php echo icon('chart'); ?> Evolución semanal de pozos sin telemetría</h2>
            <div class="zstCardHead__tools">
              <?php if ($reportEnabled) echo ns_report_pick(ns_report_key('zafiro-sin-telemetria-semanal', [$weekKey, $filters, $chartCounts]), 'chart', 'Evolución semanal de pozos sin telemetría en Zafiro', ['kind'=>'chart','chart_type'=>'bar','labels'=>array_map(function($l){return $l[0].' '.$l[1];}, $chartLabels),'datasets'=>[['label'=>'Sin telemetría','values'=>$chartCounts],['label'=>'Normalizados','values'=>$chartNormalized],['label'=>'Nuevos','values'=>$chartNew]]]); ?>
            </div>
          </div>
          <div class="zstChartBox"><canvas id="zstWeeklyChart" aria-label="Pozos sin telemetría por semana"></canvas></div>
          <p class="zstChartHint">Cada barra es el cierre de la semana (última captura de lunes a domingo). Tocá una barra para ver esa semana.</p>
        </div>
        <aside class="zstTrend <?php echo ($data['trend'] && $data['trend']['diff'] > 0) ? 'is-worse' : ''; ?>">
          <span class="zstTrend__icon"><?php echo icon('trend'); ?></span>
          <span class="zstTrend__label">Tendencia</span>
          <?php if ($data['trend']): $t = $data['trend']; ?>
            <b class="zstTrend__value"><?php echo $t['pct'] === null ? '—' : (($t['pct'] > 0 ? '+' : '') . number_format($t['pct'], 1, ',', '.') . ' %'); ?> <?php echo $t['diff'] > 0 ? '▲' : ($t['diff'] < 0 ? '▼' : ''); ?></b>
            <span class="zstTrend__detail">Respecto a <?php echo (int)$t['weeks']; ?> semana<?php echo $t['weeks'] === 1 ? '' : 's'; ?> atrás</span>
            <span class="zstTrend__wells"><b><?php echo zst_num(abs($t['diff'])); ?> pozo<?php echo abs($t['diff']) === 1 ? '' : 's'; ?> <?php echo $t['diff'] <= 0 ? 'menos' : 'más'; ?></b><br>(<?php echo zst_num($t['from']); ?> → <?php echo zst_num($t['to']); ?>)</span>
          <?php else: ?>
            <b class="zstTrend__value">—</b>
            <span class="zstTrend__detail">Se calcula cuando haya al menos dos semanas con captura.</span>
          <?php endif; ?>
        </aside>
      </section>

      <form class="zstFilters" method="get" action="sin_telemetria_zafiro.php" id="zstFilters">
        <input type="hidden" name="vista" value="<?php echo h($filters['view'] === 'sin' ? '' : $filters['view']); ?>">
        <div class="zstField zstField--week">
          <span>Semana de análisis</span>
          <div class="zstWeek">
            <?php if($canPrev): ?><a class="zstWeek__nav" href="<?php echo h(zst_url(['semana'=>$weekStart->modify('-7 days')->format('Y-m-d')])); ?>" aria-label="Semana anterior">‹</a><?php else: ?><span class="zstWeek__nav is-disabled" aria-hidden="true">‹</span><?php endif; ?>
            <label class="zstWeek__date"><?php echo icon('calendar'); ?><input type="date" name="semana" value="<?php echo h($weekKey); ?>" min="<?php echo h($data['minWeek']); ?>" max="<?php echo h((new DateTimeImmutable($data['maxWeek']))->modify('+6 days')->format('Y-m-d')); ?>" title="Elegí cualquier día: se toma su semana de lunes a domingo"></label>
            <?php if($canNext): ?><a class="zstWeek__nav" href="<?php echo h(zst_url(['semana'=>$weekStart->modify('+7 days')->format('Y-m-d')])); ?>" aria-label="Semana siguiente">›</a><?php else: ?><span class="zstWeek__nav is-disabled" aria-hidden="true">›</span><?php endif; ?>
          </div>
          <small><?php echo h($weekLabel); ?>: <?php echo h($weekStart->format('d/m/Y')); ?> al <?php echo h($weekEnd->format('d/m/Y')); ?><?php if($weekRun): ?> · cierre <?php echo h(zst_fmt_date($weekRun['date'])); ?><?php endif; ?></small>
        </div>
        <label class="zstField"><span>Zona</span><select name="zona"><option value="">Todas</option><?php foreach($data['zones'] as $zone): ?><option value="<?php echo h($zone); ?>" <?php echo strcasecmp($filters['zone'],$zone)===0?'selected':''; ?>><?php echo h($zone); ?></option><?php endforeach; ?></select></label>
        <label class="zstField"><span>Batería</span><select name="bateria"><option value="">Todas</option><?php foreach($data['batteries'] as $battery): ?><option value="<?php echo h($battery); ?>" <?php echo strcasecmp($filters['battery'],$battery)===0?'selected':''; ?>><?php echo h($battery); ?></option><?php endforeach; ?></select></label>
        <label class="zstField"><span>Telemetría</span><select name="telemetria"><option value="">Todas</option><option value="SCADA" <?php echo $filters['telemetry']==='SCADA'?'selected':''; ?>>SCADA</option><option value="TECSS" <?php echo $filters['telemetry']==='TECSS'?'selected':''; ?>>TECSS</option></select></label>
        <label class="zstField zstField--search"><span>Buscar pozo</span><input type="search" name="q" value="<?php echo h($filters['q']); ?>" placeholder="Código de pozo o batería"></label>
        <label class="zstField zstField--small"><span>Gráfico</span><select name="semanas"><?php foreach([6,8,12] as $n): ?><option value="<?php echo $n; ?>" <?php echo $filters['weeks']===$n?'selected':''; ?>><?php echo $n; ?> semanas</option><?php endforeach; ?></select></label>
        <div class="zstFilters__actions">
          <button type="submit" class="zstBtn is-primary">Aplicar filtros</button>
          <a class="zstBtn is-ghost" href="sin_telemetria_zafiro.php">Limpiar</a>
        </div>
        <div class="zstFilters__exports">
          <a class="zstBtn is-ghost" href="<?php echo h(str_replace('sin_telemetria_zafiro.php', 'sin_telemetria_zafiro_export.php', zst_url(['semana'=>$weekKey]))); ?>"><?php echo icon('download'); ?> Exportar Excel</a>
          <?php if($reportEnabled): ?>
            <button type="button" class="zstBtn is-ghost" data-clear-report-add-selected><?php echo icon('file'); ?> Generar reporte</button>
            <button type="button" class="zstBtn is-ghost" data-ns-report-open>Ver reporte <span class="nsReportCount" data-ns-report-count hidden>0</span></button>
          <?php endif; ?>
        </div>
      </form>

      <section class="zstGridCard">
        <div class="zstCardHead">
          <h2><?php echo icon('grid'); ?> <?php echo h($viewTitles[$filters['view']]); ?></h2>
          <div class="zstGridMeta">
            <?php if($filters['view']!=='sin'): ?><a class="zstChip" href="<?php echo h(zst_url(['vista'=>null])); ?>">Ver todos los sin telemetría ×</a><?php endif; ?>
            Mostrando <b data-zst-visible><?php echo zst_num(count($data['rows'])); ?></b> de <b><?php echo zst_num($data['total']); ?></b> pozos
          </div>
        </div>
        <?php if($data['previous']===null && $filters['view']!=='sin'): ?>
          <div class="zstNotice is-warning">No hay captura de la semana anterior, por eso todavía no se pueden calcular nuevos ni normalizados.</div>
        <?php endif; ?>
        <div class="zstTableScroll">
          <table class="zstTable" id="zstTable">
            <thead>
              <tr>
                <th class="zstCheck"><input type="checkbox" data-zst-check-all aria-label="Seleccionar todos"></th>
                <th><button type="button" data-zst-sort="1" data-type="text">Pozo <span>↕</span></button></th>
                <th><button type="button" data-zst-sort="2" data-type="text">Batería <span>↕</span></button></th>
                <th><button type="button" data-zst-sort="3" data-type="text">Zona <span>↕</span></button></th>
                <th><button type="button" data-zst-sort="4" data-type="text">Telemetría <span>↕</span></button></th>
                <th><button type="button" data-zst-sort="5" data-type="text">Comunicación <span>↕</span></button></th>
                <th><button type="button" data-zst-sort="6" data-type="text">Sem. anterior <span>↕</span></button></th>
                <th><button type="button" data-zst-sort="7" data-type="text">Estado actual <span>↕</span></button></th>
                <th><button type="button" data-zst-sort="8" data-type="number">Semanas sin telemetría <span>↕</span></button></th>
                <th><button type="button" data-zst-sort="9" data-type="text">Sin telemetría desde <span>↕</span></button></th>
                <th><button type="button" data-zst-sort="10" data-type="number">Producción líquido <span>↕</span></button></th>
                <th><button type="button" data-zst-sort="11" data-type="number">Producción petróleo <span>↕</span></button></th>
                <th>Observaciones</th>
              </tr>
              <tr class="zstTable__filters">
                <th></th>
                <th><input type="search" data-zst-filter="1" placeholder="Filtrar…" aria-label="Filtrar pozo"></th>
                <th><select data-zst-filter="2" aria-label="Filtrar batería"><option value="">Todas</option></select></th>
                <th><select data-zst-filter="3" aria-label="Filtrar zona"><option value="">Todas</option></select></th>
                <th><select data-zst-filter="4" aria-label="Filtrar telemetría"><option value="">Todas</option></select></th>
                <th><select data-zst-filter="5" aria-label="Filtrar comunicación"><option value="">Todas</option></select></th>
                <th><select data-zst-filter="6" aria-label="Filtrar semana anterior"><option value="">Todos</option></select></th>
                <th><select data-zst-filter="7" aria-label="Filtrar estado actual"><option value="">Todos</option></select></th>
                <th></th><th></th><th></th><th></th><th></th>
              </tr>
            </thead>
            <tbody>
            <?php if(!$data['rows']): ?>
              <tr><td colspan="13" class="zstEmpty">
                <?php if($filters['view']==='normalizados'): ?>Ningún pozo volvió a tener telemetría esta semana con los filtros elegidos.
                <?php elseif($filters['view']==='nuevos'): ?>No aparecieron pozos nuevos sin telemetría esta semana con los filtros elegidos.
                <?php else: ?>No hay pozos sin telemetría con los filtros elegidos.<?php endif; ?>
              </td></tr>
            <?php else: foreach($data['rows'] as $row):
              $prevText = $row['prevStatus']==='without' ? 'Sin dato' : ($row['prevStatus']==='new' ? 'Nuevo' : '—');
              $prevClass = $row['prevStatus']==='without' ? 'is-red' : ($row['prevStatus']==='new' ? 'is-amber' : 'is-muted');
              $curText = $row['currentStatus']==='normalized' ? 'Con telemetría' : 'Sin dato';
              $curClass = $row['currentStatus']==='normalized' ? 'is-green' : 'is-red';
              $weeksWithout = $row['weeksWithout'];
              $weeksClass = $weeksWithout===null ? '' : ($weeksWithout>=4 ? 'is-red' : ($weeksWithout>=2 ? 'is-amber' : 'is-green'));
              $commClass = $row['comm']==='Comunicando' ? 'is-green' : ($row['comm']==='Sin comunicación' ? 'is-red' : 'is-muted');
              $reportColumns = ['Pozo'=>$row['well'],'Batería'=>$row['battery'],'Zona'=>$row['zone'],'Telemetría'=>$row['telemetry'],'Comunicación'=>$row['comm'],'Sem. anterior'=>$prevText,'Estado actual'=>$curText,'Semanas sin telemetría'=>$weeksWithout===null?'—':$weeksWithout,'Sin telemetría desde'=>zst_fmt_date($row['firstSeen']),'Producción líquido'=>$row['productionLiquid']===null?'—':number_format((float)$row['productionLiquid'],2,',','.'),'Producción petróleo'=>$row['productionOil']===null?'—':number_format((float)$row['productionOil'],2,',','.'),'Observaciones'=>$row['notes']!==''?$row['notes']:'—'];
            ?>
              <tr>
                <td class="zstCheck"><?php if($reportEnabled): echo ns_report_pick(ns_report_key('zafiro-sin-telemetria',[$weekKey,$row['key'],$filters['view']]),'row','Sin telemetría en Zafiro · '.$row['well'],ns_report_row_payload($reportColumns),'Incluir'); else: ?><input type="checkbox" data-zst-row-check value="<?php echo h($row['key']); ?>" aria-label="Seleccionar <?php echo h($row['well']); ?>"><?php endif; ?></td>
                <td data-v="<?php echo h($row['well']); ?>"><b><?php echo h($row['well']); ?></b></td>
                <td data-v="<?php echo h($row['battery']); ?>"><?php echo h($row['battery']!==''?$row['battery']:'—'); ?></td>
                <td data-v="<?php echo h($row['zone']); ?>"><?php echo h($row['zone']!==''?$row['zone']:'—'); ?></td>
                <td data-v="<?php echo h($row['telemetry']); ?>"><?php echo h($row['telemetry']); ?></td>
                <td data-v="<?php echo h($row['comm']); ?>"><span class="zstBadge <?php echo $commClass; ?>"><?php echo h($row['comm']); ?></span></td>
                <td data-v="<?php echo h($prevText); ?>"><span class="zstBadge <?php echo $prevClass; ?>"><?php echo h($prevText); ?></span></td>
                <td data-v="<?php echo h($curText); ?>"><span class="zstBadge <?php echo $curClass; ?>"><?php echo h($curText); ?></span></td>
                <td data-v="<?php echo $weeksWithout===null?'0':(int)$weeksWithout; ?>" class="zstCenter"><?php if($weeksWithout===null): ?>—<?php else: ?><span class="zstWeeks <?php echo $weeksClass; ?>"><?php echo (int)$weeksWithout; ?></span><?php endif; ?></td>
                <td data-v="<?php echo h($row['firstSeen']); ?>" class="zstMono"><?php echo h(zst_fmt_date($row['firstSeen'])); ?></td>
                <td data-v="<?php echo $row['productionLiquid']===null?'':h((string)$row['productionLiquid']); ?>" class="zstMono"><?php echo $row['productionLiquid']===null?'—':number_format((float)$row['productionLiquid'],2,',','.'); ?></td>
                <td data-v="<?php echo $row['productionOil']===null?'':h((string)$row['productionOil']); ?>" class="zstMono"><?php echo $row['productionOil']===null?'—':number_format((float)$row['productionOil'],2,',','.'); ?></td>
                <td data-v="<?php echo h($row['notes']); ?>" class="zstNotes"><?php echo h($row['notes']!==''?$row['notes']:'—'); ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <p class="zstTip"><?php echo icon('gauge'); ?> <span><b>Cómo se cuenta:</b> un pozo de la grilla general queda en esta lista cuando su Zafiro Activo figura sin dato. Cuando vuelve a tener dato en la sincronización diaria sale de la lista y se cuenta como normalizado en la comparación semanal.<?php if($latestRun): ?> Última captura: <?php echo h(zst_fmt_datetime($latestRun['updated'])); ?>.<?php endif; ?></span></p>

    <?php endif; ?>
  </main>
</div>

<script src="assets/js/app.js?v=20260824-as1"></script>
<?php if ($hasData): ?>
<script>
window.CLEAR_ZST = <?php echo json_encode([
  'labels' => $chartLabels,
  'weeks' => $chartWeeks,
  'counts' => $chartCounts,
  'newWells' => $chartNew,
  'normalized' => $chartNormalized,
  'selectedWeek' => $weekKey,
  'baseUrl' => zst_url([], ['semana']),
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/js/chart.umd.js"></script>
<script src="assets/js/sin_telemetria_zafiro.js?v=20260922-1"></script>
<?php if($reportEnabled): ?><script src="assets/js/novedades_semanales.js?v=20260901-report-chart-2"></script><?php endif; ?>
<?php endif; ?>
</body>
</html>
