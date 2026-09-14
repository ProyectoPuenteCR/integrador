<?php
require_once __DIR__.'/includes/db.php';require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';require_once __DIR__.'/includes/icons.php';
require_once __DIR__.'/includes/pumpoff_partes.php';
auth_require();permissions_require_menu('novedades_semanales_monitoreo');
$cfg=require __DIR__.'/config.php';date_default_timezone_set($cfg['app']['tz']??'UTC');
$APP_USER=auth_user();$APP_ROLE=auth_es_admin()?'Administrador':'Operador';$ACTIVE='novedades_semanales_monitoreo';
$db=clear_db();$now=new DateTimeImmutable('now');$week=ns_week_for_date($now)['start'];
$selected=ns_parse_date($_GET['lectura']??'')?:$week;$selected=ns_week_for_date($selected)['start'];
$selectedValue=$selected->format('Y-m-d');
$period=['from'=>$selected->modify('-49 days'),'to'=>$selected,'weeks'=>8];
if($selected<$period['from']){$period['from']=$selected;$period['to']=$selected->modify('+77 days');}
if($selected>$period['to']){$period['to']=$selected;$period['from']=$selected->modify('-77 days');}
if($period['from']<$period['to']->modify('-77 days'))$period['from']=$period['to']->modify('-77 days');
$period['from_value']=$period['from']->format('Y-m-d');$period['to_value']=$period['to']->format('Y-m-d');
$period['weeks']=(int)floor($period['from']->diff($period['to'])->days/7)+1;
$period['selected_value']=$selectedValue;
$history=pfp_history($db,$period);$columns=pf_columns()+['OBSERVACIONES'=>'OBSERVACIONES','ADJUNTO'=>'ADJUNTO'];$error=$history['error'];$rows=[];
foreach($history['rows'] as $row)if($row['SEMANA_DESDE']===$selectedValue)$rows[]=$row;
$configured=$history['ready']&&!empty($history['details_ready'])&&$error==='';$live=false;
$reportReady=$db->ok()&&ns_report_ready($db)&&permissions_can_menu('novedades_semanales_reporte');
$canCreate=pfp_can_create();$canForm=$canCreate||auth_es_admin()||permissions_can('comments.edit_own')||permissions_can('comments.edit_all');
if(empty($_SESSION['pumpoff_partes_token']))$_SESSION['pumpoff_partes_token']=bin2hex(random_bytes(24));
$partToken=$canForm?$_SESSION['pumpoff_partes_token']:'';$token='';$zones=pfp_zones();
$weeks=[];for($i=0;$i<$period['weeks'];$i++){$date=$period['from']->modify('+'.($i*7).' days');$weeks[]=['date'=>$date->format('Y-m-d'),'label'=>ns_week_label($date,false).' · '.$date->format('Y')];}
$stamp=$rows?max(array_column($rows,'CAPTURADO_EN')):'Sin partes';$label='Partes · '.ns_week_label($selected);
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reporte de Pozos · CLEAR</title>
<link rel="stylesheet" href="assets/css/app.css"><link rel="stylesheet" href="assets/css/novedades_semanales.css">
<link rel="stylesheet" href="assets/css/novedades_monitoreo.css?v=20260828-reporte6">
</head><body><div class="app"><?php include __DIR__.'/includes/sidebar.php'; ?>
<main class="main"><?php include __DIR__.'/includes/topbar.php'; ?><section class="page nsPage pfPage">
<header class="pfHeading"><div><span class="pfEyebrow">NOVEDADES SEMANALES</span><h1>Reporte de Pozos</h1><p>Parte de monitoreo arriba · selección de pozos abajo</p></div><span class="pfWeekBadge"><?php echo h($label); ?></span></header>
<?php ns_render_module_nav($ACTIVE); ?>
<form class="nsToolbar pfToolbar" method="get">
  <?php if($canCreate): ?><button type="button" class="nsButton" id="pfAddPart" <?php echo $configured?'':'disabled'; ?>>+ Agregar parte</button><?php endif; ?>
  <label class="nsControl">Semana del parte<input type="date" name="lectura" value="<?php echo h($selectedValue); ?>"></label>
  <button type="submit" class="nsButton">Actualizar</button><button type="button" class="nsButton is-secondary" id="pfReset">Limpiar filtros</button>
  <?php if($reportReady): ?><button type="button" class="nsButton is-secondary" data-ns-report-open>Ver reporte <span data-ns-report-count class="nsReportCount" hidden>0</span></button><?php endif; ?>

</form>
<?php if(($_GET['guardado']??'')==='1'): ?><div class="nsNotice" role="status"><b>Parte guardado.</b> La grilla superior muestra los registros de la semana seleccionada.</div><?php endif; ?>
<?php if($error!==''): ?><div class="nsNotice is-warning" role="alert"><?php echo h($error); ?></div><?php endif; ?>
<?php if(!$history['ready']): ?><div class="nsNotice is-warning"><b>Falta instalar la tabla de partes.</b> Ejecutá SQL/CLEAR_PUMPOFF_PARTES.sql una vez y recargá. No modifica Jobs ni tablas de BM.</div><?php else: ?><div class="nsNotice"><b>Armá el parte.</b> Podés cargar un registro con Agregar parte o seleccionar pozos en la grilla inferior y pasarlos al parte. Revisá los datos antes de guardar.</div><?php endif; ?>
<?php if($history['ready']&&empty($history['details_ready'])): ?><div class="nsNotice is-warning"><b>Falta actualizar la tabla del parte.</b> Ejecutá SQL/CLEAR_REPORTE_POZOS_OBSERVACIONES_ADJUNTO.sql. Los registros existentes se conservan; el guardado queda bloqueado hasta instalarlo.</div><?php endif; ?>
<?php if(!$canCreate): ?><div class="nsNotice">Tu perfil no permite agregar partes. La edición respeta los permisos de edición propia o general existentes.</div><?php endif; ?>
<?php if($canForm): include __DIR__.'/includes/pumpoff_parte_form.php'; endif; ?>
<?php if($canCreate): include __DIR__.'/includes/reporte_pozos_lote_form.php'; endif; ?>
<div class="pfContext"><span id="pfFilterSummary">Sin filtros</span><span>Última modificación: <?php echo h($stamp); ?> · <?php echo h(date_default_timezone_get()); ?></span></div>
<noscript><div class="nsNotice is-warning">Activá JavaScript para los filtros, el autocompletado y la selección de pozos.</div></noscript>
<section class="nsTableCard pfTableCard"><div class="nsTableHead"><div><h2>Parte de monitoreo de pozos</h2><p id="pfRowCount">Registros guardados de la semana seleccionada.</p></div><div class="nsTableHead__actions">
<?php if($reportReady): ?><button class="nsButton" type="button" id="pfAddSelected">Agregar seleccionados al reporte</button><?php endif; ?>
<button class="nsButton is-secondary" type="button" id="pfExport">Exportar CSV</button>
<div class="nsColumnTools"><button class="nsButton is-secondary" type="button" data-ns-columns-toggle>Columnas ▾</button><div class="nsColumnsMenu" data-ns-columns-menu></div></div></div></div>
<div class="tablescroll"><table class="grid nsTable" id="pfWellsTable" data-ns-custom-columns data-ns-no-auto-select-all>
<thead><tr><?php foreach($columns as $field=>$title):$key=$field==='SEMANA'?'report':$field; ?><th data-column-key="<?php echo h($key); ?>" data-column-label="<?php echo h($title); ?>"><?php if($field==='SEMANA'&&$reportReady): ?><input type="checkbox" id="pfSelectAll" aria-label="Seleccionar todos los pozos visibles" title="Seleccionar todos los visibles"><?php endif; ?><button type="button" class="nsClientSort" data-pf-sort="<?php echo h($field); ?>"><?php echo h($title); ?></button></th><?php endforeach; ?></tr>
<tr class="nsFilterRow"><?php foreach($columns as $field=>$title): ?><th data-column-key="<?php echo h($field==='SEMANA'?'report':$field); ?>"><?php if($field==='SEMANA'): ?><small>Miércoles a martes</small><?php elseif(in_array($field,['POZO','TAG','OBSERVACIONES','ADJUNTO'],true)): ?><input data-pf-filter="<?php echo h($field); ?>" aria-label="Filtrar <?php echo h($title); ?>" placeholder="Buscar"><?php else: ?><select data-pf-filter="<?php echo h($field); ?>" aria-label="Filtrar <?php echo h($title); ?>"><option value="">Todos</option></select><?php endif; ?></th><?php endforeach; ?></tr></thead>
<tbody><?php foreach($rows as $index=>$row):$payload=['Origen'=>'Reporte de Pozos · parte manual','Semana del parte'=>$label,'Modificado'=>$row['CAPTURADO_EN'],'Cargado por'=>$row['USUARIO_CARGA']];foreach($columns as $field=>$title)$payload[$title]=$row[$field]!==''?$row[$field]:'Sin asignar'; ?>
<tr data-pf-row="<?php echo $index; ?>" title="<?php echo h('Parte manual · Modificado: '.$row['CAPTURADO_EN'].' · Cargado por: '.$row['USUARIO_CARGA']); ?>"><?php foreach($columns as $field=>$title): ?><td data-column-key="<?php echo h($field==='SEMANA'?'report':$field); ?>">
<?php if($field==='SEMANA'&&$reportReady)echo ns_report_pick(ns_report_key('pumpoff_parte',[$row['ID']]),'row','Reporte de Pozos · '.$row['POZO'].' · '.$row['SEMANA'],ns_report_row_payload($payload),''); ?>
<?php if($field==='SEMANA'&&!empty($row['CAN_EDIT'])): ?><button class="pfEditPart" type="button" data-pf-edit-part="<?php echo $index; ?>" aria-label="Editar parte de <?php echo h($row['POZO']); ?>">Editar</button><?php endif; ?>
<?php if($field==='ADJUNTO'): ?><?php if($row['ADJUNTO_NOMBRE']!==''): ?><a class="rpAttachmentLink" href="reporte_pozos_adjunto.php?id=<?php echo (int)$row['ID']; ?>"><?php echo h($row['ADJUNTO_NOMBRE']); ?></a><small><?php echo h(number_format($row['ADJUNTO_BYTES']/1024,1,',','.')); ?> KB</small><?php else: ?>Sin adjunto<?php endif; ?><?php elseif($field==='OBSERVACIONES'): ?><div class="rpObservation"><?php echo h($row[$field]); ?></div><?php elseif($field==='ESTADO'): ?><span class="pfState pfState--<?php echo h(strtolower(str_replace(' ','-',$row[$field]))); ?>"><?php echo h($row[$field]==='AUTOMATICO'?'AUTOMÁTICO':$row[$field]); ?></span><?php else: ?><?php echo h($row[$field]!==''?$row[$field]:'Sin asignar'); ?><?php endif; ?></td><?php endforeach; ?></tr>
<?php endforeach; ?><tr id="pfEmpty" <?php echo $rows?'hidden':''; ?>><td colspan="10" class="nsEmpty"><?php echo $configured?'No hay partes para esta semana o filtro. Usá Agregar parte.':'Instalá la tabla de partes para comenzar.'; ?></td></tr></tbody></table></div></section>

<?php include __DIR__.'/includes/reporte_pozos_origen.php'; ?>
<p class="pfFootnote">Los datos del parte los informa quien lo carga. No se deduce el modo de control a partir de marcha/paro.</p>
<p id="pfStatus" class="pfStatus" role="status" aria-live="polite"></p>
</section></main></div>
<script>window.CLEAR_PUMPOFF=<?php echo nm_json(['rows'=>$rows,'history'=>array_map(function($r){unset($r['OBSERVACIONES'],$r['ADJUNTO'],$r['ADJUNTO_NOMBRE'],$r['ADJUNTO_BYTES']);return $r;},$history['rows']),'weeks'=>$weeks,'zones'=>$zones,'columns'=>$columns,'configured'=>$configured&&$error==='','historyReady'=>$history['ready']&&$history['error']==='','live'=>$live,'label'=>$label,'stamp'=>$stamp,'reportReady'=>$reportReady,'token'=>$token,'manualParts'=>true,'partToken'=>$partToken,'selectedWeek'=>$selectedValue,'canCreate'=>$canCreate]); ?>;</script>
<script src="assets/js/app.js"></script><script src="assets/js/novedades_semanales.js?v=20260828-reporte6"></script>
<script src="assets/js/pumpoff_catalogo.js?v=20260828-reporte6"></script>
<script src="assets/js/pumpoff_partes.js?v=20260828-reporte6"></script>
<script src="assets/js/reporte_pozos.js?v=20260828-reporte6"></script>
</body></html>

