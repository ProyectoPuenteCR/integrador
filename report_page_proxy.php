<?php
/*
 * CLEAR - Render seguro de una pantalla real para PDF automático.
 * La URL sólo acepta pantallas declaradas en report_screen_definitions().
 * La sesión creada dura como máximo unos minutos y queda restringida al
 * permiso de la pantalla que se está imprimiendo.
 */
require_once __DIR__ . '/includes/reporting.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';

$id=(int)($_GET['id']??0);
$screen=trim((string)($_GET['screen']??''));
$expires=(int)($_GET['exp']??0);
$token=trim((string)($_GET['token']??''));

if($id<=0 || $screen==='' || $expires<=0 || $token===''){
    http_response_code(400);
    exit('Solicitud de reporte incompleta.');
}
if($expires<time() || $expires>time()+600){
    http_response_code(403);
    exit('El acceso temporal al reporte venció.');
}
$expected=report_page_token($id,$screen,$expires);
if(!hash_equals($expected,$token)){
    http_response_code(403);
    exit('Acceso denegado.');
}

$target=report_screen_native_target($screen);
if($target===''){
    http_response_code(404);
    exit('La pantalla no admite render directo.');
}

$db=clear_db();
if(!$db->ok()){
    http_response_code(500);
    exit('Sin conexión a la base.');
}
$schedules=$db->all("SELECT TOP 1 ID,USUARIO_CARGA,ACTIVO FROM dbo.CLEAR_REPORT_SCHEDULES WHERE ID=?",[$id]);
if(!$schedules){
    http_response_code(404);
    exit('Programación no encontrada.');
}
$schedule=$schedules[0];
$user=trim((string)($schedule['USUARIO_CARGA']??''));
if($user===''){
    http_response_code(403);
    exit('La programación no tiene un usuario válido.');
}
$access=permissions_get_user($user);
if(empty($access['active'])){
    http_response_code(403);
    exit('El usuario de la programación no está activo.');
}

/* Preparar una sesión corta, de sólo lectura operativa. */
auth_start();
if(session_status()===PHP_SESSION_ACTIVE){
    session_regenerate_id(true);
}
$_SESSION['clear_user']=$user;
$_SESSION['clear_login_time']=time();
$_SESSION['clear_last_activity']=time();
$_SESSION['clear_rol']='operador';
$_SESSION['clear_report_mode']=1;
$_SESSION['clear_report_exp']=$expires;
$_SESSION['clear_report_menu']=$screen;

$parts=parse_url($target);
$path=ltrim((string)($parts['path']??''),'/');
$query=[];
if(!empty($parts['query'])) parse_str((string)$parts['query'],$query);

$root=realpath(__DIR__);
$file=realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'],DIRECTORY_SEPARATOR,$path));
if($root===false || $file===false || strpos($file,$root.DIRECTORY_SEPARATOR)!==0 || strtolower(pathinfo($file,PATHINFO_EXTENSION))!=='php'){
    http_response_code(404);
    exit('Pantalla no disponible.');
}

/*
 * La pantalla incluida recibe únicamente sus parámetros reales; id/token del
 * programador no quedan visibles para su lógica interna.
 */
$_GET=$query;
$_POST=[];
$_REQUEST=$query;
$_SERVER['QUERY_STRING']=http_build_query($query);
$_SERVER['SCRIPT_NAME']='/' . basename($file);
$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_URI']=$_SERVER['SCRIPT_NAME'] . ($query ? '?' . $_SERVER['QUERY_STRING'] : '');
$_SERVER['HTTP_X_CLEAR_REPORT_RENDER']='1';

define('CLEAR_AUTO_REPORT_RENDER',true);

ob_start();
include $file;
$html=ob_get_clean();

$isDashboard=in_array($screen,['dashboard_inst_sup','dashboard_pozos'],true);

$printCss='<style id="clear-auto-report-style">'
    .'@page{size:A4 landscape;margin:7mm;}'
    .'@media print{'
    .'html,body{margin:0!important;padding:0!important;width:auto!important;min-width:0!important;background:#fff!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}'
    .'.side,.topbar,.topmenu,.clearGridToolsBtn,#globalPdfBtn,#themeToggleBtn,#favoritePageBtn,#densityBtn,.dashboardReportHead,.dashboardReportTools,.dashboardReportStatus,.page__live,.nsButton,[data-ns-report-open],[data-dashboard-report-toggle]{display:none!important;}'
    .'.app{display:block!important;min-height:0!important;width:100%!important;}'
    .'.main{margin:0!important;padding:0!important;width:100%!important;max-width:none!important;}'
    .'.page__head{margin:0 0 8px!important;padding:0!important;break-inside:avoid!important;page-break-inside:avoid!important;}'
    .'.page__title{font-size:22px!important;line-height:1.05!important;}'
    .'.page__sub{font-size:9px!important;}'
    .'a{color:inherit!important;text-decoration:none!important;}'
    .'table{break-inside:auto!important;} thead{display:table-header-group!important;} tr{break-inside:avoid!important;page-break-inside:avoid!important;}'
    .'.stat,.ops-chip,.analytics-card,.attention-box,.coverage,.coverage__stat,.dp-card,.dp-panel{break-inside:avoid!important;page-break-inside:avoid!important;}'
    .($isDashboard
        ? '.main{width:1380px!important;zoom:.76!important;}'
          .'.stats--dashboard{grid-template-columns:repeat(6,minmax(0,1fr))!important;gap:7px!important;margin-bottom:9px!important;}'
          .'.stat{min-height:0!important;padding:9px 10px!important;border-radius:10px!important;}'
          .'.stat__label{font-size:9px!important;}.stat__value{font-size:24px!important;}.stat__detail{font-size:9px!important;margin-top:5px!important;}'
          .'.ops-strip{grid-template-columns:repeat(6,minmax(0,1fr))!important;gap:7px!important;margin:0 0 9px!important;}'
          .'.ops-chip{min-height:50px!important;padding:8px 9px!important;border-radius:10px!important;}.ops-chip b{font-size:12px!important;}.ops-chip span{font-size:8px!important;}'
          .'.analytics-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important;margin-bottom:9px!important;}'
          .'.analytics-card{padding:10px!important;border-radius:10px!important;}.analytics-card__head{margin-bottom:6px!important;}.analytics-card__head h3{font-size:15px!important;}.analytics-card__head p{font-size:9px!important;}'
          .'.analytics-canvas{height:190px!important;}.analytics-canvas--small{height:165px!important;}'
          .'.attention-box{padding:9px 11px!important;margin-bottom:9px!important;border-radius:10px!important;}.attention-box h3{font-size:15px!important;}.attention-box p{font-size:9px!important;}'
          .'.coverage{grid-template-columns:125px 1fr!important;gap:9px!important;}.coverage__ring{width:104px!important;height:104px!important;}.coverage__ring:after{inset:16px!important;}.coverage__ring strong{font-size:21px!important;}'
          .'.coverage__stats{gap:6px!important;}.coverage__stat{padding:7px!important;border-radius:9px!important;}.coverage__stat b{font-size:16px!important;}'
          .'.pending-table th,.pending-table td{padding:5px 6px!important;font-size:9px!important;}'
          .'.heatmap{min-width:0!important;grid-template-columns:62px repeat(24,minmax(22px,1fr))!important;gap:2px!important;}.heatmap__head,.heatmap__day,.heatmap__cell{height:21px!important;font-size:8px!important;}'
          .'.dp{padding:0!important;}.dp-kpis{grid-template-columns:repeat(7,minmax(0,1fr))!important;gap:7px!important;}'
          .'.dp-card{min-height:0!important;padding:10px!important;border-radius:10px!important;}.dp-card span{font-size:8px!important;}.dp-card b{font-size:24px!important;margin:5px 0 2px!important;}.dp-card small{font-size:8px!important;}.dp-prod-card b{font-size:19px!important;}'
          .'.dp-grid{grid-template-columns:1fr 1fr 1.15fr!important;gap:8px!important;margin-top:8px!important;}.dp-panel{padding:10px!important;border-radius:10px!important;}.dp-panel h2{font-size:11px!important;margin-bottom:8px!important;}'
          .'.dp-donutrow{gap:12px!important;}.dp-donut{width:102px!important;height:102px!important;}.dp-donut:after{inset:24px!important;}.dp-legend div{padding:4px 0!important;font-size:9px!important;}'
          .'.dp-bars .bar{margin:7px 0!important;font-size:9px!important;}.dp-lower{grid-template-columns:1.35fr 1fr!important;gap:8px!important;margin-top:8px!important;}'
          .'.dp-table{font-size:8px!important;}.dp-table th,.dp-table td{padding:5px!important;}.dp-production{margin-top:8px!important;}.dp-prod-chart{gap:5px!important;}.dp-prod-row{font-size:9px!important;grid-template-columns:24px minmax(110px,180px) minmax(150px,1fr) 72px!important;gap:6px!important;}.dp-prod-track{height:14px!important;}'
          .'.dp-bottom{grid-template-columns:repeat(4,1fr)!important;gap:8px!important;margin-top:8px!important;}.dp-mini{padding:10px!important;}.dp-mini b{font-size:22px!important;}'
        : '')
    .'}'
    .'</style>';

if(stripos($html,'</head>')!==false){
    $html=preg_replace('~</head>~i',$printCss.'</head>',$html,1);
}else{
    $html=$printCss.$html;
}

echo $html;
