<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';
require_once __DIR__.'/includes/reporting.php';
require_once __DIR__.'/includes/novedades_semanales_common.php';
auth_require();
permissions_require_menu('reportes_guardados');
$cfg=require __DIR__.'/config.php';date_default_timezone_set($cfg['app']['tz']??'UTC');
$APP_USER=auth_user();$APP_ROLE=auth_es_admin()?'Administrador':'Operador';$ACTIVE='reportes_guardados';
$db=clear_db();$ready=$db->ok()&&report_ensure_tables();
$rows=$ready?$db->all("SELECT ID,NOMBRE,DESTINATARIOS,ASUNTO,CUERPO,CONVERT(varchar(19),FECHA_CARGA,120) AS FECHA_CARGA,CONVERT(varchar(19),FECHA_MODIFICACION,120) AS FECHA_MODIFICACION,CONVERT(varchar(19),ULTIMO_ENVIO,120) AS ULTIMO_ENVIO FROM dbo.CLEAR_REPORTES_GUARDADOS WHERE USUARIO=? AND ACTIVO=1 ORDER BY COALESCE(FECHA_MODIFICACION,FECHA_CARGA) DESC,ID DESC",[$APP_USER]):[];
function rg_date($value){$value=trim((string)$value);if($value==='')return '—';$date=DateTimeImmutable::createFromFormat('Y-m-d H:i:s',$value);return $date?$date->format('d/m/Y H:i'):$value;}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>REPORTES · CLEAR</title><link rel="stylesheet" href="assets/css/app.css?v=20260826-ns3"><link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260904-dashboard-full-1"></head><body>
<div class="app"><?php include __DIR__.'/includes/sidebar.php'; ?><main class="main"><?php include __DIR__.'/includes/topbar.php'; ?>
<div class="page__head nsHead"><div><h1 class="page__title">REPORTES</h1><div class="page__sub">Reportes guardados de <?php echo h($APP_USER); ?>. Cada usuario ve y modifica únicamente los propios.</div></div><div class="page__live"><span class="dot"></span><?php echo count($rows); ?> guardados</div></div>
<?php if(!$ready): ?><div class="nsNotice is-error">No se pudo preparar la tabla de reportes guardados. Ejecutá <code>SQL/CLEAR_REPORTES_GUARDADOS.sql</code>.</div><?php else: ?>
<div class="nsToolbar"><div class="nsControl nsControl--wide"><span>Buscar reporte</span><input type="search" id="savedReportSearch" placeholder="Nombre, asunto o destinatario"></div><button class="nsButton" type="button" data-ns-report-open>Nuevo reporte</button></div>
<section class="savedReports" id="savedReports">
<?php if(!$rows): ?><div class="nsNotice">Todavía no guardaste reportes. Después de enviar uno, el sistema te preguntará si querés conservarlo.</div><?php endif; ?>
<?php foreach($rows as $row):$search=strtolower((string)ns_value($row,'NOMBRE').' '.ns_value($row,'ASUNTO').' '.ns_value($row,'DESTINATARIOS')); ?>
<article class="savedReport" data-saved-report data-search="<?php echo h($search); ?>">
  <div><h2><?php echo h(ns_value($row,'NOMBRE')); ?></h2><div class="savedReport__meta"><span>Creado: <?php echo h(rg_date(ns_value($row,'FECHA_CARGA'))); ?></span><span>Modificado: <?php echo h(rg_date(ns_value($row,'FECHA_MODIFICACION'))); ?></span><span>Último envío: <?php echo h(rg_date(ns_value($row,'ULTIMO_ENVIO'))); ?></span></div><div class="savedReport__to"><b><?php echo h(ns_value($row,'ASUNTO')?:'Sin asunto'); ?></b><br><?php echo h(ns_value($row,'DESTINATARIOS')?:'Sin destinatarios guardados'); ?></div></div>
  <div class="savedReport__actions"><button class="nsButton" type="button" data-ns-report-open data-saved-id="<?php echo (int)ns_value($row,'ID'); ?>">Modificar / enviar</button><button class="nsButton is-secondary is-danger" type="button" data-delete-saved="<?php echo (int)ns_value($row,'ID'); ?>">Eliminar</button></div>
</article>
<?php endforeach; ?>
</section><?php endif; ?></main></div>
    <script>window.CLEAR_WEEKLY_NEWS={};</script><script src="assets/js/app.js?v=20260826-ns3"></script><script src="assets/js/novedades_semanales.js?v=20260904-dashboard-full-1"></script>
<script>(function(){var dirty=false,search=document.getElementById('savedReportSearch');if(search)search.addEventListener('input',function(){var q=search.value.trim().toLowerCase();document.querySelectorAll('[data-saved-report]').forEach(function(card){card.hidden=!!q&&card.getAttribute('data-search').indexOf(q)<0;});});document.addEventListener('click',function(event){var button=event.target.closest('[data-delete-saved]');if(!button)return;if(!window.confirm('¿Eliminar este reporte guardado? Esta acción no elimina las alarmas ni los comentarios.'))return;button.disabled=true;fetch('novedades_semanales_report_api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'delete_saved',id:button.getAttribute('data-delete-saved')})}).then(function(r){return r.json();}).then(function(res){if(!res.ok)throw new Error(res.error||'No se pudo eliminar.');window.location.reload();}).catch(function(error){window.alert(error.message);button.disabled=false;});});window.addEventListener('message',function(event){if(event.data&&event.data.type==='clear-saved-reports-updated')dirty=true;});window.addEventListener('clear-report-popup-closed',function(){if(dirty)window.location.reload();});})();</script>
</body></html>
