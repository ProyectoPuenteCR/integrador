<?php
require_once __DIR__.'/includes/db.php';require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';require_once __DIR__.'/includes/icons.php';
require_once __DIR__.'/includes/pumpoff.php';
auth_require();permissions_require_menu('novedades_semanales_monitoreo');
$cfg=require __DIR__.'/config.php';date_default_timezone_set($cfg['app']['tz']??'UTC');
$APP_USER=auth_user();$APP_ROLE=auth_es_admin()?'Administrador':'Operador';$ACTIVE='novedades_semanales_monitoreo';
$db=clear_db();$now=new DateTimeImmutable('now');$week=ns_week_for_date($now)['start'];
$period=ns_period($_GET,$now,['period_weeks'=>8]);
if($period['weeks']>12){$period['from']=$period['to']->modify('-77 days');$period['from_value']=$period['from']->format('Y-m-d');$period['weeks']=12;}
$history=pf_history($db,$period);$columns=pf_columns();$error='';$warnings=[];$config=[];
try{$config=pf_config();}catch(Throwable $e){$error='No se pudo cargar la configuración PUMP OFF. Revisá pumpoff_config.php.';}
$selected=ns_parse_date($_GET['lectura']??'');$selectedValue=$selected?$selected->format('Y-m-d'):'';
$live=$selectedValue==='';$rows=[];$source=['rows'=>[],'queried_at'=>$now->format('Y-m-d H:i:s'),'error'=>'','warnings'=>[]];
$project=['configured'=>false,'can_save'=>false,'rows'=>[]];
if($error===''&&$live){
    if($config['scope']!=='pending')$source=nm_load($db);
    try{$project=pf_project($source,$config,$now);$rows=$project['rows'];$warnings=array_merge($source['warnings'],$project['warnings']);}
    catch(Throwable $e){$error='La configuración del listado PUMP OFF no es válida.';}
    if($source['error']!=='')$error=$source['error'];
}elseif(!$live){foreach($history['rows'] as $row)if($row['SEMANA_DESDE']===$selectedValue)$rows[]=$row;}
$configured=$live?$project['configured']:!empty($rows);
$reportReady=$db->ok()&&ns_report_ready($db)&&permissions_can_menu('novedades_semanales_reporte');
$token='';
if($live&&auth_es_admin()&&$history['ready']&&$history['error']===''&&$project['can_save']&&$error===''){
    $token=bin2hex(random_bytes(24));$_SESSION['pumpoff_snapshot_token']=$token;
    $_SESSION['pumpoff_snapshot']=['rows'=>$rows,'week'=>$week->format('Y-m-d'),'captured_at'=>$source['queried_at'],'created'=>time(),'config_hash'=>hash('sha256',serialize($config))];
}
$zones=array_keys($config['zones']??['LHCG'=>[],'CED I'=>[],'CED II'=>[]]);
$weeks=[];for($i=0;$i<$period['weeks'];$i++){$date=$period['from']->modify('+'.($i*7).' days');$weeks[]=['date'=>$date->format('Y-m-d'),'label'=>ns_week_label($date,false).' · '.$date->format('Y')];}
$savedWeeks=[];foreach($history['rows'] as $row)$savedWeeks[$row['SEMANA_DESDE']]=true;krsort($savedWeeks);
$stamp=$live?$source['queried_at']:($rows?max(array_column($rows,'CAPTURADO_EN')):'Sin lectura');
$label=$live?'Lectura actual · '.ns_week_label($week):($selected?ns_week_label($selected):'Sin lectura');
$chartKeys=[];foreach(['general','zone0','zone1','zone2','trend'] as $key)$chartKeys[$key]=ns_report_key('pumpoff_chart',[$stamp,$selectedValue,$period['from_value'],$period['to_value'],$key]);
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Monitoreo de pozos PUMP OFF · CLEAR</title>
<link rel="stylesheet" href="assets/css/app.css"><link rel="stylesheet" href="assets/css/novedades_semanales.css">
<link rel="stylesheet" href="assets/css/novedades_monitoreo.css?v=20260828-pumpoff2">
</head><body><div class="app"><?php include __DIR__.'/includes/sidebar.php'; ?>
<main class="main"><?php include __DIR__.'/includes/topbar.php'; ?><section class="page nsPage pfPage">
<header class="pfHeading"><div><span class="pfEyebrow">NOVEDADES SEMANALES</span><h1>Monitoreo de pozos</h1><p>PUMP OFF · estado de control y evolución semanal</p></div><span class="pfWeekBadge"><?php echo h($label); ?></span></header>
<?php ns_render_module_nav($ACTIVE); ?>
<form class="nsToolbar pfToolbar" method="get">
  <label class="nsControl">Lectura de la grilla<select name="lectura" onchange="this.form.submit()"><option value="">Actual</option><?php foreach(array_keys($savedWeeks) as $date): ?><option value="<?php echo h($date); ?>" <?php echo $date===$selectedValue?'selected':''; ?>><?php echo h(ns_week_label(ns_parse_date($date))); ?></option><?php endforeach; ?></select></label>
  <label class="nsControl">Comparar desde<input type="date" name="desde" value="<?php echo h($period['from_value']); ?>"></label>
  <label class="nsControl">Hasta<input type="date" name="hasta" value="<?php echo h($period['to_value']); ?>"></label>
  <button type="submit" class="nsButton">Actualizar</button><button type="button" class="nsButton is-secondary" id="pfReset">Limpiar filtros</button>
  <?php if($reportReady): ?><button type="button" class="nsButton is-secondary" data-ns-report-open>Ver reporte <span data-ns-report-count class="nsReportCount" hidden>0</span></button><?php endif; ?>
  <?php if($token!==''): ?><button type="button" class="nsButton is-secondary" id="pfSaveWeek">Guardar lectura semanal</button><?php endif; ?>
</form>
<?php if($error!==''): ?><div class="nsNotice is-warning" role="alert"><?php echo h($error); ?></div><?php endif; ?>
<?php if($live&&!$project['configured']): ?><div class="nsNotice is-warning"><b>Listado PUMP OFF pendiente de confirmar.</b> El diseño está disponible. No se incluyen todos los pozos BM automáticamente; completá el listado y las reglas indicadas en LEEME.</div><?php endif; ?>
<?php foreach($warnings as $warning): ?><div class="nsNotice is-warning"><?php echo h($warning); ?></div><?php endforeach; ?>
<div class="pfContext"><span id="pfFilterSummary">Sin filtros</span><span>Captura: <?php echo h($stamp); ?> · <?php echo h(date_default_timezone_get()); ?></span></div>
<noscript><div class="nsNotice is-warning">Activá JavaScript para los gráficos, filtros y selección del reporte.</div></noscript>
<article class="nsCard pfGeneral" data-pf-chart="general">
  <div class="nsCard__head"><div><span class="pfEyebrow">DISTRIBUCIÓN GENERAL</span><h2>Pozos por estado</h2><p>Seleccioná un estado para ver sus pozos en la grilla.</p></div><?php if($reportReady): ?><button class="nsButton is-secondary" data-pf-add-chart="general" type="button" disabled>Agregar gráfico al reporte</button><?php endif; ?></div>
  <div class="pfGeneralBody"><div class="pfDonut"><canvas data-clear-chart-local="true" id="pfGeneralChart" role="img" aria-label="Distribución general MANUAL AUTOMÁTICO HOA"></canvas><div class="pfDonutCenter"><strong id="pfTotal">—</strong><span>POZOS</span></div></div><div class="pfStateSummary" id="pfStateSummary"></div></div><p class="pfChartMessage" id="pfGeneralMessage"></p>
</article>
<section class="pfZones" aria-label="Estados por zona">
<?php foreach($zones as $i=>$zone): ?><article class="nsCard" data-pf-chart="zone<?php echo $i; ?>">
  <div class="nsCard__head"><div><span class="pfEyebrow">ZONA</span><h2><?php echo h($zone); ?></h2></div><strong class="pfZoneCount" id="pfZoneCount<?php echo $i; ?>">—</strong></div>
  <div class="nsCard__body"><div class="pfSmallChart"><canvas data-clear-chart-local="true" id="pfZoneChart<?php echo $i; ?>" role="img" aria-label="Estados en <?php echo h($zone); ?>"></canvas></div><p class="pfChartMessage" id="pfZoneMessage<?php echo $i; ?>"></p><?php if($reportReady): ?><button class="nsButton is-secondary pfReportChart" data-pf-add-chart="zone<?php echo $i; ?>" type="button" disabled>Agregar gráfico al reporte</button><?php endif; ?></div>
</article><?php endforeach; ?>
</section>
<article class="nsCard pfTrend" data-pf-chart="trend">
  <div class="nsCard__head"><div><span class="pfEyebrow">COMPARACIÓN SEMANA A SEMANA</span><h2>Evolución de los estados</h2><p>Última lectura guardada de cada semana · miércoles a martes · hasta 12 semanas</p></div><?php if($reportReady): ?><button class="nsButton is-secondary" data-pf-add-chart="trend" type="button" disabled>Agregar gráfico al reporte</button><?php endif; ?></div>
  <div class="nsCard__body"><div class="pfLineChart"><canvas data-clear-chart-local="true" id="pfTrendChart" role="img" aria-label="Evolución semanal por estado"></canvas></div><p class="pfChartMessage" id="pfTrendMessage"></p></div>
  <?php if(!$history['ready']): ?><div class="pfHistoryNote">El histórico aún no está instalado. El SQL incluido crea solo la tabla semanal; no modifica Jobs ni vuelve a consultar RTQP.</div><?php elseif($history['error']!==''): ?><div class="pfHistoryNote"><?php echo h($history['error']); ?></div><?php else: ?><div class="pfHistoryNote">Una semana sin captura queda como “Sin datos”. Guardar nuevamente reemplaza únicamente la lectura de la semana actual. No es un cierre automático.</div><?php endif; ?>
</article>
<section class="nsTableCard pfTableCard"><div class="nsTableHead"><div><h2>Detalle de pozos</h2><p id="pfRowCount">Filtrá por estado, zona, supervisor, batería, pozo o TAG.</p></div><div class="nsTableHead__actions">
<?php if($reportReady): ?><button class="nsButton" type="button" id="pfAddSelected">Agregar seleccionados al reporte</button><?php endif; ?>
<button class="nsButton is-secondary" type="button" id="pfExport">Exportar CSV</button>
<div class="nsColumnTools"><button class="nsButton is-secondary" type="button" data-ns-columns-toggle>Columnas ▾</button><div class="nsColumnsMenu" data-ns-columns-menu></div></div></div></div>
<div class="tablescroll"><table class="grid nsTable" id="pfWellsTable" data-ns-custom-columns data-ns-no-auto-select-all>
<thead><tr><?php foreach($columns as $field=>$title):$key=$field==='SEMANA'?'report':$field; ?><th data-column-key="<?php echo h($key); ?>" data-column-label="<?php echo h($title); ?>"><?php if($field==='SEMANA'&&$reportReady): ?><input type="checkbox" id="pfSelectAll" aria-label="Seleccionar todos los pozos visibles" title="Seleccionar todos los visibles"><?php endif; ?><button type="button" class="nsClientSort" data-pf-sort="<?php echo h($field); ?>"><?php echo h($title); ?></button></th><?php endforeach; ?></tr>
<tr class="nsFilterRow"><?php foreach($columns as $field=>$title): ?><th data-column-key="<?php echo h($field==='SEMANA'?'report':$field); ?>"><?php if($field==='SEMANA'): ?><small>Miércoles a martes</small><?php elseif(in_array($field,['POZO','TAG'],true)): ?><input data-pf-filter="<?php echo h($field); ?>" aria-label="Filtrar <?php echo h($title); ?>" placeholder="Buscar"><?php else: ?><select data-pf-filter="<?php echo h($field); ?>" aria-label="Filtrar <?php echo h($title); ?>"><option value="">Todos</option></select><?php endif; ?></th><?php endforeach; ?></tr></thead>
<tbody><?php foreach($rows as $index=>$row):$payload=['Lectura'=>$label,'Captura'=>$row['CAPTURADO_EN'],'Fecha original de la señal'=>$row['FECHA_FUENTE']];foreach($columns as $field=>$title)$payload[$title]=$row[$field]!==''?$row[$field]:'Sin asignar'; ?>
<tr data-pf-row="<?php echo $index; ?>" title="<?php echo h('Captura: '.$row['CAPTURADO_EN'].' · Fecha fuente: '.$row['FECHA_FUENTE']); ?>"><?php foreach($columns as $field=>$title): ?><td data-column-key="<?php echo h($field==='SEMANA'?'report':$field); ?>">
<?php if($field==='SEMANA'&&$reportReady)echo ns_report_pick(ns_report_key('pumpoff_row',[$row['SEMANA_DESDE'],$row['POZO'],$row['CAPTURADO_EN']]),'row','PUMP OFF · '.$row['POZO'].' · '.$row['SEMANA'],ns_report_row_payload($payload),''); ?>
<?php if($field==='ESTADO'): ?><span class="pfState pfState--<?php echo h(strtolower(str_replace(' ','-',$row[$field]))); ?>"><?php echo h($row[$field]==='AUTOMATICO'?'AUTOMÁTICO':$row[$field]); ?></span><?php else: ?><?php echo h($row[$field]!==''?$row[$field]:'Sin asignar'); ?><?php endif; ?></td><?php endforeach; ?></tr>
<?php endforeach; ?><tr id="pfEmpty" <?php echo $rows?'hidden':''; ?>><td colspan="8" class="nsEmpty"><?php echo $configured?'No hay pozos para esta lectura o filtro.':'Esperando el listado confirmado de pozos PUMP OFF.'; ?></td></tr></tbody></table></div></section>
<p class="pfFootnote">TAG y jefe de producción sin asignación se muestran como tales. No se usa ESTADO de marcha/paro para deducir el modo de control. La fecha de captura no garantiza la vigencia de la señal.</p>
<p id="pfStatus" class="pfStatus" role="status" aria-live="polite"></p>
</section></main></div>
<script>window.CLEAR_PUMPOFF=<?php echo nm_json(['rows'=>$rows,'history'=>$history['rows'],'weeks'=>$weeks,'zones'=>$zones,'columns'=>$columns,'configured'=>$configured&&$error==='','historyReady'=>$history['ready']&&$history['error']==='','live'=>$live,'label'=>$label,'stamp'=>$stamp,'chartKeys'=>$chartKeys,'reportReady'=>$reportReady,'token'=>$token]); ?>;</script>
<script src="assets/js/chart.umd.js"></script><script src="assets/js/app.js"></script><script src="assets/js/novedades_semanales.js?v=20260828-pumpoff2"></script><script src="assets/js/novedades_monitoreo.js?v=20260828-pumpoff2"></script>
</body></html>

