<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/gestion_telemetria.php';

$GT_MENU_KEY = 'gestion_telemetria';
auth_require();
permissions_require_menu($GT_MENU_KEY);
$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = $GT_MENU_KEY;
$db = clear_db();
$data = gt_load($db, $_GET);
$dbError = !$db->ok() ? (string)$db->error() : ($data['error'] ?? '');
$view = $data['filters']['view'];

function gt_url(array $set = [], array $unset = [])
{
    $query = $_GET;
    foreach ($unset as $key) unset($query[$key]);
    foreach ($set as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]); else $query[$key] = $value;
    }
    return 'gestion_telemetria.php' . ($query ? '?' . http_build_query($query) : '');
}
function gt_num($value, $decimals = 0){ return number_format((float)$value, $decimals, ',', '.'); }
function gt_prod($value){ return $value === null ? '—' : gt_num($value, 2); }

$chartLabels=[];$chartCounts=[];$chartNorm=[];$chartNew=[];
foreach ($data['chart'] as $point) {
    $chartLabels[] = ($point['weekLabel'] ?? '') . ' · ' . ($point['label'] ?? '');
    $chartCounts[] = $point['count'];
    $chartNorm[] = $point['normalized'];
    $chartNew[] = $point['newWells'];
}
$lastChartPoint = $data['chart'] ? $data['chart'][count($data['chart']) - 1] : null;
$stateCards = ['abandonado','sin_producir','inyector','cerrado'];
$tabs = [
    'resumen'=>'Resumen',
    'necesitan'=>'Necesitan telemetría',
    'disponible'=>'Telemetría disponible',
    'evolucion'=>'Evolución semanal',
    'energia'=>'Líneas de energía'
];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gestión de Telemetría · CLEAR Plataforma</title>
<link rel="stylesheet" href="assets/css/app.css?v=20260824-as1">
<link rel="stylesheet" href="assets/css/sin_telemetria_zafiro.css?v=20260922-2">
<link rel="stylesheet" href="assets/css/gestion_telemetria.css?v=20260923-1">
</head>
<body>
<div class="app">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
<?php include __DIR__ . '/includes/topbar.php'; ?>

<header class="zstHead">
  <div class="zstHead__title">
    <span class="zstHead__icon"><?php echo icon('chart'); ?></span>
    <div>
      <h1>Gestión de Telemetría</h1>
      <p>Balance entre pozos que necesitan telemetría y equipos instalados en pozos inactivos. Integra Zafiro, producción Q164, histórico semanal y líneas de energía.</p>
    </div>
  </div>
  <div class="zstHead__side">
    <?php if($data['latestZafiro']): ?><div class="zstSync"><?php echo icon('calendar'); ?><div><span>Última sincronización Zafiro</span><b><?php echo h(gt_fmt_datetime($data['latestZafiro'])); ?></b></div></div><?php endif; ?>
    <a class="zstBtn is-ghost" href="sin_telemetria_zafiro.php"><?php echo icon('signal-off'); ?> Ver detalle sin telemetría</a>
    <?php if(permissions_can_menu('reportes')): ?><a class="zstBtn is-primary" href="reportes.php?pantalla=gestion_telemetria"><?php echo icon('file'); ?> Agregar a reporte automático</a><?php endif; ?>
  </div>
</header>

<?php if($dbError): ?>
<div class="zstNotice is-error"><b>No se pudo cargar Gestión de Telemetría:</b> <?php echo h($dbError); ?></div>
<?php else: ?>

<section class="zstKpis">
  <a class="zstKpi is-red" href="<?php echo h(gt_url(['vista'=>'necesitan'])); ?>"><span class="zstKpi__icon"><?php echo icon('signal-off'); ?></span><div><span class="zstKpi__label">Necesitan telemetría</span><b class="zstKpi__value"><?php echo gt_num($data['needCount']); ?></b><span class="zstKpi__detail">Pozos del seguimiento Zafiro</span></div></a>
  <a class="zstKpi is-green" href="<?php echo h(gt_url(['vista'=>'disponible'])); ?>"><span class="zstKpi__icon"><?php echo icon('monitor'); ?></span><div><span class="zstKpi__label">Telemetría disponible</span><b class="zstKpi__value"><?php echo gt_num($data['availableCount']); ?></b><span class="zstKpi__detail">Con telemetría y Zafiro Activo = false</span></div></a>
  <div class="zstKpi"><span class="zstKpi__icon"><?php echo icon('gauge'); ?></span><div><span class="zstKpi__label">Balance disponible - necesidad</span><b class="zstKpi__value <?php echo $data['balance']<0?'gtBalanceNegative':'gtBalancePositive'; ?>"><?php echo ($data['balance']>0?'+':'') . gt_num($data['balance']); ?></b><span class="zstKpi__detail">Referencia para redistribución de equipos</span></div></div>
  <div class="zstKpi is-bruta"><span class="zstKpi__icon"><?php echo icon('gauge'); ?></span><div><span class="zstKpi__label">Producción Bruta afectada</span><b class="zstKpi__value"><?php echo gt_num($data['needBrute'],2); ?></b><span class="zstKpi__detail">m³/d · Producción Líquido Q164</span></div></div>
  <div class="zstKpi is-oil"><span class="zstKpi__icon"><?php echo icon('oil'); ?></span><div><span class="zstKpi__label">Producción Neta afectada</span><b class="zstKpi__value"><?php echo gt_num($data['needNet'],2); ?></b><span class="zstKpi__detail">m³/d · Producción Petróleo Q164</span></div></div>
  <a class="zstKpi is-amber" href="<?php echo h(gt_url(['vista'=>'evolucion'])); ?>"><span class="zstKpi__icon"><?php echo icon('trend'); ?></span><div><span class="zstKpi__label">Normalizados última semana</span><b class="zstKpi__value"><?php echo $lastChartPoint ? gt_num($lastChartPoint['normalized'] ?? 0) : '—'; ?></b><span class="zstKpi__detail">Pozos que salieron del listado sin telemetría</span></div></a>
</section>

<nav class="gtTabs" aria-label="Secciones de Gestión de Telemetría">
<?php foreach($tabs as $key=>$label): ?><a class="<?php echo $view===$key?'is-active':''; ?>" href="<?php echo h(gt_url(['vista'=>$key])); ?>"><?php echo h($label); ?></a><?php endforeach; ?>
</nav>

<?php if($view==='resumen'): ?>
<section class="gtSummaryStrip">
<?php foreach($stateCards as $key): $s=$data['stateSummary'][$key]; ?>
  <div class="gtState">
    <div class="gtState__top"><span><?php echo h($s['label']); ?></span><b><?php echo gt_num($s['count']); ?></b></div>
    <div class="gtState__prod">
      <div><small>Bruta</small><strong><?php echo gt_num($s['brute'],2); ?> m³/d</strong></div>
      <div><small>Neta</small><strong><?php echo gt_num($s['net'],2); ?> m³/d</strong></div>
    </div>
  </div>
<?php endforeach; ?>
</section>

<div class="gtSplit">
<section class="gtPanel">
  <div class="gtPanel__head"><h2><?php echo icon('signal-off'); ?> Pozos que necesitan telemetría</h2><span class="gtPanel__meta">Total <?php echo gt_num($data['needCount']); ?></span></div>
  <div style="overflow:auto"><table class="gtMiniTable"><thead><tr><th>Pozo</th><th>Batería</th><th>Telemetría</th><th>Bruta</th><th>Neta</th></tr></thead><tbody>
  <?php foreach(array_slice($data['need'],0,8) as $r): ?><tr><td><b><?php echo h($r['well']); ?></b></td><td><?php echo h($r['battery']?:'—'); ?></td><td><?php echo h($r['telemetry']?:'—'); ?></td><td><?php echo gt_prod($r['productionLiquid']); ?></td><td><?php echo gt_prod($r['productionOil']); ?></td></tr><?php endforeach; ?>
  <?php if(!$data['need']): ?><tr><td colspan="5" class="gtEmpty">No hay datos de demanda disponibles.</td></tr><?php endif; ?>
  </tbody></table></div>
  <p class="gtFootNote"><a href="<?php echo h(gt_url(['vista'=>'necesitan'])); ?>">Ver listado completo →</a></p>
</section>

<section class="gtPanel">
  <div class="gtPanel__head"><h2><?php echo icon('monitor'); ?> Telemetría potencialmente liberable</h2><span class="gtPanel__meta">Total <?php echo gt_num($data['availableCount']); ?></span></div>
  <div style="overflow:auto"><table class="gtMiniTable"><thead><tr><th>Pozo</th><th>Batería</th><th>Estado Zafiro</th><th>Tipo</th><th>Línea</th></tr></thead><tbody>
  <?php foreach(array_slice($data['available'],0,8) as $r): ?><tr><td><b><?php echo h($r['well']); ?></b></td><td><?php echo h($r['battery']?:'—'); ?></td><td><?php echo h($r['state']); ?></td><td><?php echo h($r['telemetry']); ?></td><td><?php echo h($r['line']?:'—'); ?></td></tr><?php endforeach; ?>
  <?php if(!$data['available']): ?><tr><td colspan="5" class="gtEmpty">No se detectaron pozos inactivos con telemetría.</td></tr><?php endif; ?>
  </tbody></table></div>
  <p class="gtFootNote"><a href="<?php echo h(gt_url(['vista'=>'disponible'])); ?>">Ver listado completo →</a></p>
</section>
</div>

<section class="gtChartCard">
  <div class="gtPanel__head"><h2><?php echo icon('chart'); ?> Evolución semanal</h2><a class="zstBtn is-ghost" href="<?php echo h(gt_url(['vista'=>'evolucion'])); ?>">Abrir análisis</a></div>
  <div class="gtChartBox"><canvas id="gtEvolutionChart"></canvas></div>
</section>

<?php elseif($view==='necesitan' || $view==='disponible'): ?>
<form class="zstFilters gtFiltersCompact" method="get">
  <input type="hidden" name="vista" value="<?php echo h($view); ?>">
  <label class="zstField"><span>Batería</span><select name="bateria"><option value="">Todas</option><?php foreach($data['batteries'] as $b): ?><option value="<?php echo h($b); ?>" <?php echo strcasecmp($data['filters']['battery'],$b)===0?'selected':''; ?>><?php echo h($b); ?></option><?php endforeach; ?></select></label>
  <label class="zstField"><span>Telemetría</span><select name="telemetria"><option value="">Todas</option><option value="SCADA" <?php echo $data['filters']['telemetry']==='SCADA'?'selected':''; ?>>SCADA</option><option value="TECSS" <?php echo $data['filters']['telemetry']==='TECSS'?'selected':''; ?>>TECSS</option></select></label>
  <?php if($view==='disponible'): ?><label class="zstField"><span>Estado Zafiro</span><select name="estado"><option value="">Todos</option><?php foreach($data['stateSummary'] as $key=>$s): ?><option value="<?php echo h($key); ?>" <?php echo $data['filters']['state']===$key?'selected':''; ?>><?php echo h($s['label']); ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <label class="zstField"><span>Línea de energía</span><select name="linea"><option value="">Todas</option><?php foreach($data['lines'] as $line): ?><option value="<?php echo h($line); ?>" <?php echo strcasecmp($data['filters']['line'],$line)===0?'selected':''; ?>><?php echo h($line); ?></option><?php endforeach; ?></select></label>
  <label class="zstField zstField--search"><span>Buscar</span><input type="search" name="q" value="<?php echo h($data['filters']['q']); ?>" placeholder="Pozo, batería, estado, método..."></label>
  <div class="zstFilters__actions"><button class="zstBtn is-primary">Aplicar</button><a class="zstBtn is-ghost" href="<?php echo h(gt_url(['vista'=>$view],['bateria','telemetria','estado','linea','q'])); ?>">Limpiar</a></div>
</form>

<?php if($view==='disponible'): ?>
<section class="gtSummaryStrip">
<?php foreach($stateCards as $key): $s=$data['stateSummary'][$key]; ?>
<div class="gtState"><div class="gtState__top"><span><?php echo h($s['label']); ?></span><b><?php echo gt_num($s['count']); ?></b></div><div class="gtState__prod"><div><small>Bruta</small><strong><?php echo gt_num($s['brute'],2); ?> m³/d</strong></div><div><small>Neta</small><strong><?php echo gt_num($s['net'],2); ?> m³/d</strong></div></div></div>
<?php endforeach; ?>
</section>
<?php endif; ?>

<section class="gtTableCard">
<div class="gtPanel__head">
  <h2><?php echo icon($view==='necesitan'?'signal-off':'monitor'); ?> <?php echo $view==='necesitan'?'Pozos que necesitan telemetría':'Telemetría potencialmente liberable'; ?></h2>
  <div class="gtActions"><span class="gtPanel__meta">Mostrando <?php echo gt_num(count($view==='necesitan'?$data['needFiltered']:$data['availableFiltered'])); ?></span><button type="button" class="zstBtn is-ghost" data-gt-export="#gtMainTable"><?php echo icon('download'); ?> Exportar Excel/CSV</button></div>
</div>
<div class="gtTableScroll"><table class="gtTable" id="gtMainTable">
<thead>
<?php if($view==='necesitan'): ?>
<tr><th><button data-gt-sort="0">Pozo <span>↕</span></button></th><th><button data-gt-sort="1">Batería <span>↕</span></button></th><th><button data-gt-sort="2">Telemetría <span>↕</span></button></th><th><button data-gt-sort="3">Comunicación <span>↕</span></button></th><th><button data-gt-sort="4" data-type="number">Semanas sin telemetría <span>↕</span></button></th><th><button data-gt-sort="5" data-type="number">Bruta <span>↕</span></button></th><th><button data-gt-sort="6" data-type="number">Neta <span>↕</span></button></th><th><button data-gt-sort="7">Línea energía <span>↕</span></button></th><th>Observaciones</th></tr>
<tr class="gtTable__filters"><th><input data-gt-filter="0" placeholder="Filtrar…"></th><th><select data-gt-filter="1"><option value="">Todas</option></select></th><th><select data-gt-filter="2"><option value="">Todas</option></select></th><th><select data-gt-filter="3"><option value="">Todas</option></select></th><th></th><th></th><th></th><th><select data-gt-filter="7"><option value="">Todas</option></select></th><th></th></tr>
<?php else: ?>
<tr><th><button data-gt-sort="0">Pozo <span>↕</span></button></th><th><button data-gt-sort="1">Batería <span>↕</span></button></th><th><button data-gt-sort="2">Estado Zafiro <span>↕</span></button></th><th><button data-gt-sort="3">Método Zafiro <span>↕</span></button></th><th><button data-gt-sort="4">Telemetría <span>↕</span></button></th><th><button data-gt-sort="5">Comunicación <span>↕</span></button></th><th><button data-gt-sort="6" data-type="number">Bruta <span>↕</span></button></th><th><button data-gt-sort="7" data-type="number">Neta <span>↕</span></button></th><th><button data-gt-sort="8">Línea energía <span>↕</span></button></th><th>Último test</th></tr>
<tr class="gtTable__filters"><th><input data-gt-filter="0" placeholder="Filtrar…"></th><th><select data-gt-filter="1"><option value="">Todas</option></select></th><th><select data-gt-filter="2"><option value="">Todos</option></select></th><th><select data-gt-filter="3"><option value="">Todos</option></select></th><th><select data-gt-filter="4"><option value="">Todas</option></select></th><th><select data-gt-filter="5"><option value="">Todas</option></select></th><th></th><th></th><th><select data-gt-filter="8"><option value="">Todas</option></select></th><th></th></tr>
<?php endif; ?>
</thead><tbody>
<?php if($view==='necesitan'): foreach($data['needFiltered'] as $r): ?>
<tr><td data-v="<?php echo h($r['well']); ?>"><b><?php echo h($r['well']); ?></b></td><td data-v="<?php echo h($r['battery']); ?>"><?php echo h($r['battery']?:'—'); ?></td><td data-v="<?php echo h($r['telemetry']); ?>"><?php echo h($r['telemetry']?:'—'); ?></td><td data-v="<?php echo h($r['comm']); ?>"><span class="zstBadge <?php echo $r['comm']==='Comunicando'?'is-green':($r['comm']==='Sin comunicación'?'is-red':'is-muted'); ?>"><?php echo h($r['comm']); ?></span></td><td data-v="<?php echo (int)($r['weeksWithout']??0); ?>" class="gtRight"><?php echo $r['weeksWithout']===null?'—':(int)$r['weeksWithout']; ?></td><td data-v="<?php echo h((string)($r['productionLiquid']??'')); ?>" class="gtRight"><?php echo gt_prod($r['productionLiquid']); ?></td><td data-v="<?php echo h((string)($r['productionOil']??'')); ?>" class="gtRight"><?php echo gt_prod($r['productionOil']); ?></td><td data-v="<?php echo h($r['line']); ?>"><?php echo h($r['line']?:'—'); ?></td><td><?php echo h($r['notes']?:'—'); ?></td></tr>
<?php endforeach; if(!$data['needFiltered']): ?><tr><td colspan="9" class="gtEmpty">No hay pozos con los filtros seleccionados.</td></tr><?php endif; ?>
<?php else: foreach($data['availableFiltered'] as $r): ?>
<tr><td data-v="<?php echo h($r['well']); ?>"><b><?php echo h($r['well']); ?></b></td><td data-v="<?php echo h($r['battery']); ?>"><?php echo h($r['battery']?:'—'); ?></td><td data-v="<?php echo h($r['state']); ?>"><span class="zstBadge is-muted"><?php echo h($r['state']); ?></span></td><td data-v="<?php echo h($r['method']); ?>"><?php echo h($r['method']?:'—'); ?></td><td data-v="<?php echo h($r['telemetry']); ?>"><?php echo h($r['telemetry']); ?><small style="display:block;color:var(--text-mut)"><?php echo h($r['typeDetail']); ?></small></td><td data-v="<?php echo h($r['comm']); ?>"><span class="zstBadge <?php echo $r['comm']==='Comunicando'?'is-green':($r['comm']==='Sin comunicación'?'is-red':'is-muted'); ?>"><?php echo h($r['comm']); ?></span></td><td data-v="<?php echo h((string)($r['productionLiquid']??'')); ?>" class="gtRight"><?php echo gt_prod($r['productionLiquid']); ?></td><td data-v="<?php echo h((string)($r['productionOil']??'')); ?>" class="gtRight"><?php echo gt_prod($r['productionOil']); ?></td><td data-v="<?php echo h($r['line']); ?>"><?php echo h($r['line']?:'—'); ?></td><td><?php echo h(gt_fmt_date($r['productionDate'])); ?></td></tr>
<?php endforeach; if(!$data['availableFiltered']): ?><tr><td colspan="10" class="gtEmpty">No se detectaron equipos liberables con los filtros seleccionados.</td></tr><?php endif; endif; ?>
</tbody></table></div>
</section>

<?php elseif($view==='evolucion'): ?>
<div class="gtEvolutionGrid">
<section class="gtChartCard">
  <div class="gtPanel__head"><h2><?php echo icon('chart'); ?> Evolución semanal de telemetría</h2><form method="get"><input type="hidden" name="vista" value="evolucion"><select name="semanas" onchange="this.form.submit()" style="height:36px;border:1px solid var(--line-strong);border-radius:8px;background:var(--bg-card);color:var(--text);padding:0 9px"><?php foreach([6,8,12] as $n): ?><option value="<?php echo $n; ?>" <?php echo $data['filters']['weeks']===$n?'selected':''; ?>>Últimas <?php echo $n; ?> semanas</option><?php endforeach; ?></select></form></div>
  <div class="gtChartBox"><canvas id="gtEvolutionChart"></canvas></div>
  <p class="gtFootNote">La serie usa el histórico diario ya instalado en “Sin telemetría en Zafiro”. “Normalizados” representa pozos que estaban en el listado y dejaron de estarlo en la semana siguiente.</p>
</section>
<aside class="gtCurrentPool"><span>Pool actual potencialmente redistribuible</span><b><?php echo gt_num($data['availableCount']); ?></b><p>Equipos instalados en pozos con Zafiro Activo = false. Este valor se calcula en tiempo real con la grilla general y Q158.</p><p style="margin-top:12px"><strong>Balance:</strong> <?php echo ($data['balance']>0?'+':'').gt_num($data['balance']); ?> equipos respecto a la necesidad actual.</p></aside>
</div>
<section class="gtPanel">
<div class="gtPanel__head"><h2><?php echo icon('history'); ?> Detalle por semana</h2></div>
<div style="overflow:auto"><table class="gtMiniTable"><thead><tr><th>Semana</th><th>Sin telemetría</th><th>Normalizados</th><th>Nuevos</th><th>Cierre</th></tr></thead><tbody>
<?php foreach($data['chart'] as $p): ?><tr><td><b><?php echo h(($p['weekLabel']??'').' · '.($p['label']??'')); ?></b></td><td><?php echo $p['count']===null?'—':gt_num($p['count']); ?></td><td><?php echo $p['normalized']===null?'—':gt_num($p['normalized']); ?></td><td><?php echo $p['newWells']===null?'—':gt_num($p['newWells']); ?></td><td><?php echo h(gt_fmt_date($p['close']??'')); ?></td></tr><?php endforeach; ?>
</tbody></table></div>
</section>

<?php elseif($view==='energia'): ?>
<?php if($data['dynaReady']): ?>
<div class="gtSource"><?php echo icon('check'); ?><span><b>Fuente activa: DYNA.</b> La relación pozo → línea se está leyendo desde <code>dbo.CLEAR_DYNA_POZOS_ENERGIA</code>.</span></div>
<?php elseif($data['energySource']!==''): ?>
<div class="gtSource is-warning"><?php echo icon('gauge'); ?><span><b>DYNA todavía no está cargado.</b> Para no dejar la pantalla vacía se usa temporalmente <code><?php echo h($data['energySource']); ?></code>. Cuando exista <code>dbo.CLEAR_DYNA_POZOS_ENERGIA</code>, la pantalla cambia automáticamente a DYNA.</span></div>
<?php else: ?>
<div class="gtSource is-warning"><?php echo icon('gauge'); ?><span><b>Pendiente export DYNA.</b> Se espera un archivo con POZO y LINEA_ENERGIA; ALIMENTADOR y SUBESTACION son opcionales.</span></div>
<?php endif; ?>

<section class="gtTableCard">
<div class="gtPanel__head"><h2><?php echo icon('grid'); ?> Líneas de energía asociadas</h2><div class="gtActions"><button type="button" class="zstBtn is-ghost" data-gt-dyna-template><?php echo icon('download'); ?> Plantilla para export DYNA</button><button type="button" class="zstBtn is-ghost" data-gt-export="#gtEnergyTable"><?php echo icon('download'); ?> Exportar</button></div></div>
<div class="gtTableScroll"><table class="gtTable" id="gtEnergyTable"><thead><tr><th><button data-gt-sort="0">Línea <span>↕</span></button></th><th><button data-gt-sort="1" data-type="number">Pozos asociados <span>↕</span></button></th><th><button data-gt-sort="2" data-type="number">Necesitan telemetría <span>↕</span></button></th><th><button data-gt-sort="3" data-type="number">Telemetría disponible <span>↕</span></button></th><th>Alimentador</th><th>Subestación</th><th>Lectura operativa</th></tr><tr class="gtTable__filters"><th><input data-gt-filter="0" placeholder="Filtrar…"></th><th></th><th></th><th></th><th><select data-gt-filter="4"><option value="">Todos</option></select></th><th><select data-gt-filter="5"><option value="">Todas</option></select></th><th></th></tr></thead><tbody>
<?php foreach($data['energyRows'] as $r): ?><tr><td data-v="<?php echo h($r['line']); ?>"><span class="gtLineBadge"><?php echo h($r['line']); ?></span></td><td data-v="<?php echo (int)$r['associated']; ?>" class="gtRight"><?php echo gt_num($r['associated']); ?></td><td data-v="<?php echo (int)$r['need']; ?>" class="gtRight"><?php echo gt_num($r['need']); ?></td><td data-v="<?php echo (int)$r['available']; ?>" class="gtRight"><?php echo gt_num($r['available']); ?></td><td data-v="<?php echo h($r['feeder']); ?>"><?php echo h($r['feeder']?:'—'); ?></td><td data-v="<?php echo h($r['substation']); ?>"><?php echo h($r['substation']?:'—'); ?></td><td><?php echo $r['need']>0 ? 'Hay pozos con necesidad para correlacionar con eventos de la línea.' : 'Sin necesidad detectada en el listado actual.'; ?></td></tr><?php endforeach; ?>
<?php if(!$data['energyRows']): ?><tr><td colspan="7" class="gtEmpty">No hay relación pozo → línea disponible todavía. Descargá la plantilla y completala con el export de DYNA.</td></tr><?php endif; ?>
</tbody></table></div>
<p class="gtFootNote">Esta relación deja preparada la base para una etapa posterior de correlación de cortes simultáneos y análisis de posibles eventos de cableado; la pantalla no clasifica automáticamente un corte como robo.</p>
</section>
<?php endif; ?>

<?php endif; ?>
</main>
</div>
<script src="assets/js/app.js?v=20260824-as1"></script>
<?php if(!$dbError): ?>
<script>window.CLEAR_GT=<?php echo json_encode(['labels'=>$chartLabels,'counts'=>$chartCounts,'normalized'=>$chartNorm,'newWells'=>$chartNew],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="assets/js/chart.umd.js"></script>
<script src="assets/js/gestion_telemetria.js?v=20260923-1"></script>
<?php endif; ?>
</body></html>
