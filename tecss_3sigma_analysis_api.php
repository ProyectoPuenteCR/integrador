<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/tecss_3sigma_admin.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function sigma_analysis_reply($status,array $data){http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(!auth_check())sigma_analysis_reply(401,['ok'=>false,'error'=>'La sesión venció. Volvé a ingresar.']);
$access=permissions_get_user(auth_user());
$allowed=sigma_analysis_admin_allowed(auth_es_admin(),$access,!empty($_SESSION['clear_report_mode']));
if(!$allowed||!permissions_can_menu('tecss_3sigma'))sigma_analysis_reply(403,['ok'=>false,'error'=>'Esta acción está reservada a administradores.']);
$method=$_SERVER['REQUEST_METHOD']??'';
try{
    $app=require __DIR__.'/config.php';
    if($method==='POST'){
        $body=file_get_contents('php://input',false,null,0,4097);
        $input=json_decode($body,true);
        if(strlen($body)>4096||!is_array($input))throw new RuntimeException('Solicitud inválida.',400);
        sigma_analysis_validate_request($method,$allowed,$_SESSION['clear_sigma_analysis_csrf']??'', $input);
        $well=$input['pozo']??'';
    }elseif($method==='GET'){$well=$_GET['pozo']??'';}
    else throw new RuntimeException('Método no permitido.',405);
    sigma_analysis_path($well);
    $transport=sigma_analysis_transport(sigma_af_connection(clear_db(),$app));
    $get=function($path)use($transport){return $transport('GET',$path);};
    if($method==='GET'){
        $analysis=sigma_analysis_resolve($get,$well);
        sigma_analysis_reply(200,['ok'=>true,'status'=>$analysis['Status']??null,'checked_at'=>(new DateTimeImmutable())->format(DATE_ATOM),'analysis'=>'3Sigma']);
    }
    $enable=function($path)use($transport){$transport('PATCH',$path);};
    $audit=function($action,$detail){return audit_log($action,'tecss_3sigma',json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));};
    sigma_analysis_reply(200,sigma_analysis_enable($get,$enable,$audit,$well));
}catch(Throwable $error){
    $code=(int)$error->getCode();if($code<400||$code>599)$code=502;
    sigma_analysis_reply($code,['ok'=>false,'error'=>$error instanceof RuntimeException?$error->getMessage():'No se pudo completar la operación AF.']);
}
