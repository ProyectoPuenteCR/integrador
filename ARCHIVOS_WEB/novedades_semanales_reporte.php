<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';
require_once __DIR__.'/includes/novedades_semanales_common.php';
require_once __DIR__.'/includes/reporting.php';

auth_require();
permissions_require_menu('novedades_semanales_reporte');
$cfg=require __DIR__.'/config.php';
date_default_timezone_set($cfg['app']['tz']??'UTC');
$APP_USER=auth_user();
$APP_ROLE=auth_es_admin()?'Administrador':'Operador';
$ACTIVE='novedades_semanales_reporte';
$embedded=isset($_GET['embedded'])&&(string)$_GET['embedded']==='1';
$db=clear_db();
$ready=ns_module_ready($db);
$reportReady=$ready&&ns_report_ready($db);
$items=$reportReady?ns_report_items($db,$APP_USER):[];
$savedId=max(0,(int)($_GET['saved_id']??0));
$saved=[];
if($savedId&&report_ensure_tables()){
  $savedRows=$db->all("SELECT TOP 1 ID,NOMBRE,DESTINATARIOS,ASUNTO,CUERPO FROM dbo.CLEAR_REPORTES_GUARDADOS WHERE ID=? AND USUARIO=? AND ACTIVO=1",[$savedId,$APP_USER]);
  if($savedRows)$saved=$savedRows[0];else $savedId=0;
}
$charts=[];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reporte operativo · CLEAR</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260826-ns3">
  <link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260826-select-all-1">
  <link rel="stylesheet" href="assets/css/chart_preferences.css?v=20260826-chartprefs-2">
</head>
<body class="<?php echo $embedded?'nsReportEmbeddedBody':''; ?>">
<?php if(!$embedded): ?><div class="app"><?php include __DIR__.'/includes/sidebar.php'; ?><main class="main"><?php include __DIR__.'/includes/topbar.php'; ?>
<div class="page__head nsHead"><div><h1 class="page__title">Reporte operativo</h1><div class="page__sub">Compone un informe común con gráficos y filas marcados en las pantallas.</div></div><div class="page__live"><span class="dot"></span><?php echo count($items); ?> elementos</div></div>
<?php ns_render_module_nav($ACTIVE); ?>
<?php else: ?><main class="nsReportEmbedded">
<?php endif; ?>

<?php if(!$ready):ns_render_not_installed();elseif(!$reportReady): ?>
  <div class="nsNotice is-warning">Falta instalar el constructor de reportes. Volvé a ejecutar <code>SQL/CLEAR_NOVEDADES_SEMANALES.sql</code>.</div>
<?php else: ?>
  <div class="nsToolbar nsNoPrint">
    <div class="nsControl nsControl--wide"><span>Contenido seleccionado</span><input value="<?php echo count($items); ?> elementos agregados desde el módulo" readonly></div>
    <button class="nsButton" type="button" onclick="window.print()"><?php echo icon('download'); ?> Exportar PDF</button>
    <a class="nsButton is-secondary" href="novedades_semanales_export.php?vista=reporte"><?php echo icon('download'); ?> Exportar Excel</a>
    <button class="nsButton is-secondary" type="button" data-ns-save-report><?php echo $savedId?'Guardar cambios':'Guardar borrador'; ?></button>
    <button class="nsButton is-secondary" type="button" data-ns-clear-report>Vaciar reporte</button>
  </div>
  <section class="nsReportGrid">
    <div class="nsReportItems">
    <?php if(!$items): ?>
      <div class="nsNotice">Todavía no agregaste elementos. Seleccioná filas desde Micros contables, Malos actores, Comparativa, Seguimiento o el Panel semanal.</div>
    <?php else:foreach($items as $index=>$item):
      $payload=json_decode((string)ns_value($item,'PAYLOAD_JSON'),true);if(!is_array($payload))$payload=[];
      $kind=(string)($payload['kind']??'row');$id='nsReportChart'.$index;
    ?>
      <article class="nsReportItem">
        <div class="nsReportItem__head"><h2><?php echo h(ns_value($item,'TITULO')); ?></h2><div class="nsNoPrint"><?php echo ns_report_pick((string)ns_value($item,'ITEM_KEY'),(string)ns_value($item,'ITEM_TIPO'),(string)ns_value($item,'TITULO'),$payload,'Incluir'); ?></div></div>
        <div class="nsReportItem__body">
        <?php if(!empty($payload['context'])): ?><p class="nsCommentPreview" style="white-space:normal;max-width:none"><?php echo h((string)$payload['context']); ?></p><?php endif; ?>
        <?php if($kind==='chart'):
          $imageRef=(string)($payload['image_ref']??'');$imageOk=preg_match('#^data/report_images/[a-f0-9]{40}\.png$#',$imageRef)&&is_file(__DIR__.'/'.$imageRef);
          if(!$imageOk)$charts[]=['id'=>$id,'labels'=>$payload['labels']??[],'series'=>$payload['datasets']??[],'type'=>ns_chart_type($payload['chart_type']??'bar','bar'),'unit'=>(string)($payload['unit']??'alarmas'),'palette'=>(string)($payload['palette']??'blues')];
        ?><div class="nsChartBox"><?php if($imageOk): ?><img class="nsReportChartImage" src="<?php echo h($imageRef); ?>" alt="<?php echo h(ns_value($item,'TITULO')); ?>"><?php else: ?><canvas id="<?php echo h($id); ?>"></canvas><?php endif; ?></div>
        <?php else: ?><table class="nsReportData"><tbody><?php foreach(($payload['columns']??[]) as $label=>$value): ?><tr><th><?php echo h($label); ?></th><td><?php echo h(is_scalar($value)?$value:json_encode($value,JSON_UNESCAPED_UNICODE)); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        </div>
      </article>
    <?php endforeach;endif; ?>
    </div>
    <aside class="nsEmailCard nsNoPrint">
      <h2>Enviar por email</h2><p class="page__sub">Libreta compartida para todos los reportes de CLEAR.</p>
      <form id="nsReportEmailForm"><input type="hidden" name="saved_id" value="<?php echo (int)$savedId; ?>"><input type="hidden" name="saved_name" value="<?php echo h((string)ns_value($saved,'NOMBRE')); ?>"><label for="emailto">Destinatarios</label><input id="emailto" name="emailto" type="text" value="<?php echo h((string)ns_value($saved,'DESTINATARIOS')); ?>" placeholder="correo@empresa.com; otro@empresa.com" required><details class="nsAddressBook"><summary><span>Libreta de direcciones</span><small data-contact-count>abrir</small></summary><div class="nsAddressBook__content"><input class="nsContactSearch" type="search" placeholder="Buscar nombre o correo" data-contact-search><div class="nsContactList" data-report-contacts><p class="nsContactEmpty">Cargando contactos…</p></div><div id="nsContactForm" class="nsContactForm"><div class="nsAddressBook__title">Guardar contacto común</div><input name="contact_name" maxlength="150" placeholder="Nombre o área"><div class="nsContactForm__row"><input name="contact_email" type="email" maxlength="254" placeholder="correo@empresa.com"><button class="nsButton is-secondary" type="button" data-save-contact>Guardar</button></div></div></div></details><label for="subject">Asunto</label><input id="subject" name="subject" value="<?php echo h((string)ns_value($saved,'ASUNTO','CLEAR · Reporte operativo')); ?>"><label for="body">Mensaje</label><textarea id="body" name="body" placeholder="Comentario opcional para acompañar el reporte"><?php echo h((string)ns_value($saved,'CUERPO')); ?></textarea><div class="nsSendProgress" data-send-progress hidden><div class="nsSendProgress__head"><b data-send-status>Preparando el reporte…</b><span data-send-percent>0%</span></div><div class="nsSendProgress__track"><i data-send-bar></i></div><small>No cierres esta ventana hasta que finalice el envío.</small></div><button class="nsButton" type="submit" style="margin-top:12px;width:100%"><span data-send-label>Enviar reporte</span></button></form>
    </aside>
  </section>
<?php endif; ?>

</main><?php if(!$embedded): ?></div><?php endif; ?>
<?php if($reportReady): ?><script>window.CLEAR_WEEKLY_NEWS=<?php echo json_encode(['reportCharts'=>$charts,'savedReport'=>['id'=>$savedId,'name'=>(string)ns_value($saved,'NOMBRE')]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;</script><script src="assets/js/chart.umd.js"></script><?php endif; ?>
<script src="assets/js/app.js?v=20260826-ns3"></script><?php if($embedded): ?><script src="assets/js/chart_preferences.js?v=20260826-chartprefs-2"></script><?php endif; ?><script src="assets/js/novedades_semanales.js?v=20260826-select-all-1"></script>
</body></html>
