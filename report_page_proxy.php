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

$printCss='<style id="clear-auto-report-style">'
    .'@media print{'
    .'.side,.topbar,.topmenu,.clearGridToolsBtn,#globalPdfBtn,#themeToggleBtn,#favoritePageBtn,#densityBtn{display:none!important;}'
    .'.app{display:block!important;min-height:auto!important;}'
    .'.main{margin:0!important;padding:8px!important;width:100%!important;max-width:none!important;}'
    .'body{background:#fff!important;}'
    .'}'
    .'</style>';

if(stripos($html,'</head>')!==false){
    $html=preg_replace('~</head>~i',$printCss.'</head>',$html,1);
}else{
    $html=$printCss.$html;
}

echo $html;
