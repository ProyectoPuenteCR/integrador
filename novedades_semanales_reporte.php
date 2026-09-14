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
$reportReady=ns_report_ready($db);
$items=$reportReady?ns_report_items($db,$APP_USER):[];
$chartItems=[];$rowItems=[];$charts=[];
foreach($items as $reportItem){
  $reportPayload=json_decode((string)ns_value($reportItem,'PAYLOAD_JSON'),true);if(!is_array($reportPayload))$reportPayload=[];
  if(strtolower((string)($reportPayload['kind']??'row'))==='chart')$chartItems[]=['item'=>$reportItem,'payload'=>$reportPayload];
  else $rowItems[]=$reportItem;
}
$sections=$reportReady?ns_report_grid_sections($rowItems):[];
$savedId=max(0,(int)($_GET['saved_id']??0));
$saved=[];
if($savedId&&report_ensure_tables()){
  $savedRows=$db->all("SELECT TOP 1 ID,NOMBRE,DESTINATARIOS,ASUNTO,CUERPO,ITEMS_JSON FROM dbo.CLEAR_REPORTES_GUARDADOS WHERE ID=? AND USUARIO=? AND ACTIVO=1",[$savedId,$APP_USER]);
  if($savedRows)$saved=$savedRows[0];else $savedId=0;
}
$reportTitleColumns=ns_report_title_columns($sections);
$reportTitleCandidate=array_key_exists('title_column',$_GET)?(string)$_GET['title_column']:ns_report_saved_title_column(ns_value($saved,'ITEMS_JSON'));
$reportTitleColumn=ns_report_title_column($reportTitleCandidate,$sections);
$reportTitle=ns_report_title($reportTitleColumn);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo h($reportTitle); ?> · CLEAR</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260826-ns3">
  <link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260904-dashboard-full-1">
  <link rel="stylesheet" href="assets/css/chart_preferences.css?v=20260826-chartprefs-2">
</head>
<body class="<?php echo $embedded?'nsReportEmbeddedBody':''; ?>">
<?php if(!$embedded): ?><div class="app"><?php include __DIR__.'/includes/sidebar.php'; ?><main class="main"><?php include __DIR__.'/includes/topbar.php'; ?>
<div class="page__head nsHead"><div class="nsReportPageHeading"><div><h1 class="page__title" data-ns-report-title><?php echo h($reportTitle); ?></h1><div class="page__sub">Mantiene los gráficos seleccionados con sus valores y agrupa las filas operativas en grillas.</div></div><label class="nsReportTitlePicker nsNoPrint"><span>Columna en el título</span><select data-ns-report-title-column <?php echo $reportTitleColumns?'':'disabled'; ?>><option value="">Sin agregar columna</option><?php foreach($reportTitleColumns as $column): ?><option value="<?php echo h($column); ?>" <?php echo $column===$reportTitleColumn?'selected':''; ?>><?php echo h($column); ?></option><?php endforeach; ?></select></label></div><div class="page__live"><span class="dot"></span><?php echo count($items); ?> elementos</div></div>
<?php ns_render_module_nav($ACTIVE); ?>
<?php else: ?><main class="nsReportEmbedded"><header class="nsReportDocumentTitle"><h1 data-ns-report-title><?php echo h($reportTitle); ?></h1></header>
<?php endif; ?>

<?php if(!$reportReady): ?>
  <div class="nsNotice is-warning">Falta instalar el constructor de reportes. Volvé a ejecutar <code>SQL/CLEAR_NOVEDADES_SEMANALES.sql</code>.</div>
<?php else: ?>
  <div class="nsToolbar nsNoPrint">
    <div class="nsControl nsControl--wide"><span>Contenido seleccionado</span><input value="<?php echo count($items); ?> elementos agregados desde el módulo" readonly></div>
    <button class="nsButton" type="button" onclick="window.print()"><?php echo icon('download'); ?> Exportar PDF</button>
    <a class="nsButton is-secondary" data-ns-report-excel href="novedades_semanales_export.php?vista=reporte<?php echo $reportTitleColumn!==''?'&amp;title_column='.rawurlencode($reportTitleColumn):''; ?>"><?php echo icon('download'); ?> Exportar Excel</a>
    <button class="nsButton is-secondary" type="button" data-ns-save-report><?php echo $savedId?'Guardar cambios':'Guardar borrador'; ?></button>
    <button class="nsButton is-secondary" type="button" data-ns-clear-report>Vaciar reporte</button>
  </div>
  <section class="nsReportGrid">
    <div class="nsReportItems">
    <?php if(!$items): ?>
      <div class="nsNotice">Todavía no agregaste elementos. Seleccioná filas desde Telemetría, Alarmas, Micros contables o Novedades semanales.</div>
    <?php else: ?>
      <?php if($chartItems): ?><div class="nsReportCharts">
      <?php foreach($chartItems as $chartIndex=>$chartEntry):
        $item=$chartEntry['item'];$payload=$chartEntry['payload'];$id='nsReportChart'.$chartIndex;
        $hasChartData=is_array($payload['labels']??null)&&is_array($payload['datasets']??null)&&count($payload['datasets'])>0;
        $imageRef=(string)($payload['image_ref']??'');$imageOk=preg_match('#^data/report_images/[a-f0-9]{40}\.png$#',$imageRef)&&is_file(__DIR__.'/'.$imageRef);
        if(!$imageOk&&$hasChartData)$charts[]=['id'=>$id,'labels'=>$payload['labels'],'series'=>$payload['datasets'],'type'=>ns_chart_type($payload['chart_type']??'bar','bar'),'unit'=>(string)($payload['unit']??'alarmas'),'palette'=>(string)($payload['palette']??'blues')];
      ?>
        <article class="nsReportItem nsReportItem--chart">
          <div class="nsReportItem__head"><h2><?php echo h(ns_value($item,'TITULO')); ?></h2><div class="nsNoPrint"><?php echo ns_report_pick((string)ns_value($item,'ITEM_KEY'),(string)ns_value($item,'ITEM_TIPO'),(string)ns_value($item,'TITULO'),$payload,'Incluir'); ?></div></div>
          <div class="nsReportItem__body">
            <div class="nsChartBox"><?php if($imageOk): ?><img class="nsReportChartImage" src="<?php echo h($imageRef); ?>" alt="<?php echo h(ns_value($item,'TITULO')); ?>"><?php elseif($hasChartData): ?><canvas id="<?php echo h($id); ?>"></canvas><?php else: ?><div class="nsNotice">No hay valores disponibles para reconstruir este gráfico. Volvé a seleccionarlo desde su pantalla.</div><?php endif; ?></div>
            <div class="nsReportChartComment"><b>Comentario:</b> <?php echo h(trim((string)($payload['context']??''))!==''?(string)$payload['context']:'—'); ?></div>
          </div>
        </article>
      <?php endforeach; ?>
      </div><?php endif; ?>
      <?php foreach($sections as $section): ?>
      <article class="nsReportTableSection">
        <div class="nsReportTableSection__head"><h2><?php echo h($section['title']); ?></h2><span><?php echo count($section['rows']); ?> fila<?php echo count($section['rows'])===1?'':'s'; ?></span></div>
        <div class="nsReportTableScroll"><table class="nsReportGridTable">
          <thead><tr><th class="nsNoPrint nsReportIncludeColumn">INCLUIR</th><?php foreach($section['headers'] as $header): ?><th><?php echo h($header); ?></th><?php endforeach; ?></tr></thead>
          <tbody><?php foreach($section['rows'] as $entry):$rowItem=$entry['item']; ?><tr>
            <td class="nsNoPrint nsReportIncludeColumn"><?php if(is_array($rowItem)):echo ns_report_pick((string)ns_value($rowItem,'ITEM_KEY'),(string)ns_value($rowItem,'ITEM_TIPO'),(string)ns_value($rowItem,'TITULO'),$entry['payload'],'Incluir');endif; ?></td>
            <?php foreach($section['headers'] as $header): ?><td><?php echo h(ns_report_grid_value($entry['values'][$header]??'')); ?></td><?php endforeach; ?>
          </tr><?php endforeach; ?></tbody>
        </table></div>
      </article>
      <?php endforeach; ?>
    <?php endif; ?>
    </div>
    <aside class="nsEmailCard nsNoPrint">
      <h2>Enviar por email</h2><p class="page__sub">Libreta compartida para todos los reportes de CLEAR.</p>
      <form id="nsReportEmailForm"><input type="hidden" name="saved_id" value="<?php echo (int)$savedId; ?>"><input type="hidden" name="saved_name" value="<?php echo h((string)ns_value($saved,'NOMBRE')); ?>"><label for="emailto">Destinatarios</label><input id="emailto" name="emailto" type="text" value="<?php echo h((string)ns_value($saved,'DESTINATARIOS')); ?>" placeholder="correo@empresa.com; otro@empresa.com" required><details class="nsAddressBook"><summary><span>Libreta de direcciones</span><small data-contact-count>abrir</small></summary><div class="nsAddressBook__content"><input class="nsContactSearch" type="search" placeholder="Buscar nombre o correo" data-contact-search><div class="nsContactList" data-report-contacts><p class="nsContactEmpty">Cargando contactos…</p></div><div id="nsContactForm" class="nsContactForm"><div class="nsAddressBook__title">Guardar contacto común</div><input name="contact_name" maxlength="150" placeholder="Nombre o área"><div class="nsContactForm__row"><input name="contact_email" type="email" maxlength="254" placeholder="correo@empresa.com"><button class="nsButton is-secondary" type="button" data-save-contact>Guardar</button></div></div></div></details><label for="subject">Asunto</label><input id="subject" name="subject" value="<?php echo h((string)ns_value($saved,'ASUNTO','CLEAR · Reporte operativo')); ?>"><label for="body">Mensaje</label><textarea id="body" name="body" placeholder="Comentario opcional para acompañar el reporte"><?php echo h((string)ns_value($saved,'CUERPO')); ?></textarea><div class="nsSendProgress" data-send-progress hidden><div class="nsSendProgress__head"><b data-send-status>Preparando el reporte…</b><span data-send-percent>0%</span></div><div class="nsSendProgress__track"><i data-send-bar></i></div><small>No cierres esta ventana hasta que finalice el envío.</small></div><button class="nsButton" type="submit" style="margin-top:12px;width:100%"><span data-send-label>Enviar reporte</span></button></form>
    </aside>
  </section>
<?php endif; ?>

</main><?php if(!$embedded): ?></div><?php endif; ?>
<?php if($reportReady): ?><script>window.CLEAR_WEEKLY_NEWS=<?php echo json_encode(['reportCharts'=>$charts,'savedReport'=>['id'=>$savedId,'name'=>(string)ns_value($saved,'NOMBRE')],'reportTitleColumns'=>$reportTitleColumns,'reportTitleColumn'=>$reportTitleColumn,'reportTitle'=>$reportTitle],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;</script><script src="assets/js/chart.umd.js"></script><?php endif; ?>
<script src="assets/js/app.js?v=20260826-ns3"></script><?php if($embedded): ?><script src="assets/js/chart_preferences.js?v=20260826-chartprefs-2"></script><?php endif; ?><script src="assets/js/novedades_semanales.js?v=20260904-dashboard-full-1"></script>
</body></html>
