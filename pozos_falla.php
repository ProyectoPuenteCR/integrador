<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';
require_once __DIR__.'/includes/dashboard_pozos_data.php';
require_once __DIR__.'/includes/pozos_falla_report.php';
require_once __DIR__.'/includes/novedades_semanales_common.php';

auth_require();
// Es el detalle de la tarjeta del dashboard; conserva su permiso de lectura.
permissions_require_menu('dashboard_pozos');
$cfg=require __DIR__.'/config.php';
date_default_timezone_set($cfg['app']['tz']??'America/Argentina/Buenos_Aires');
$APP_USER=auth_user()?:'CLEAR';
$APP_ROLE=auth_es_admin()?'Administrador':'Operador';
$ACTIVE='dashboard_pozos';
$db=clear_db();
$data=dp_data_load($db);

$model=pf_build_report($data,$_GET);
extract($model,EXTR_SKIP);

if(pf_input($_GET,'exportar')==='csv'){
    if(!$hasTelemetry){
        http_response_code(503);header('Content-Type: text/plain; charset=UTF-8');
        exit('No hay una lectura de pozos disponible para exportar el reporte de fallas.');
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Pozos_en_falla_'.$dayKey.'.csv"');
    header('Cache-Control: no-store');
    $out=fopen('php://output','w');
    fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Día operativo',$dayLabel],';');
    $columns=pf_columns(['pozo'=>'','fallas_dia'=>null]);
    fputcsv($out,array_keys($columns),';');
    foreach($filtered as $row)fputcsv($out,array_map('pf_csv_value',array_values(pf_columns($row))),';');
    fclose($out);exit;
}

$reportReady=ns_report_ready($db)&&permissions_can_menu('novedades_semanales_reporte');
$reportItems=$reportReady?pf_report_items($model):[];
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>CLEAR · Pozos en falla</title>
<link rel="stylesheet" href="assets/css/app.css?v=3.0.0">
<?php pf_render_styles(); ?>
<?php if($reportReady): ?><link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260904-dashboard-full-1"><?php endif; ?>
</head><body><div class="app">
<?php include __DIR__.'/includes/sidebar.php'; ?>
<main class="main">
<?php include __DIR__.'/includes/topbar.php'; ?>
<div class="page__head pf-heading"><div><h1 class="page__title">Pozos en falla</h1><div class="page__sub">Detalle del estado actual · Día operativo: <?php echo h($dayLabel); ?></div></div>
<a class="pf-button pf-noprint" href="dashboard_pozos.php#pozos-en-falla">Volver al dashboard</a></div>
<section class="pf">
<?php foreach(($data['errors']??[]) as $error): ?><div class="pf-notice" role="status"><?php echo h($error); ?></div><?php endforeach; ?>
<?php pf_render_panel($model,$reportReady); ?>
</section></main></div>
<script src="assets/js/app.js"></script>
<script src="assets/js/pozos_falla.js?v=20261021-1"></script>
<?php if($reportReady): ?><script>window.CLEAR_WEEKLY_NEWS={};window.CLEAR_DASHBOARD_REPORT=<?php echo json_encode(['items'=>$reportItems,'addedMessage'=>'Pozos en falla agregados al reporte con los filtros actuales.','removedMessage'=>'Pozos en falla quitados del reporte.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script><script src="assets/js/novedades_semanales.js?v=20260904-dashboard-full-1"></script><script src="assets/js/dashboard_report.js?v=20260904-dashboard-full-1"></script><?php endif; ?>
<?php if($print): ?><script>window.addEventListener('load',function(){window.print();});</script><?php endif; ?>
</body></html>
