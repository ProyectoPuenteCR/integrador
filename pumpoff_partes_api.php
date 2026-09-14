<?php
require_once __DIR__.'/includes/db.php';require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';require_once __DIR__.'/includes/pumpoff_partes.php';
auth_require();permissions_require_menu('novedades_semanales_monitoreo');
header('Content-Type: application/json; charset=UTF-8');
function pfp_reply(array $value,int $status=200){http_response_code($status);echo nm_json($value);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')pfp_reply(['ok'=>false,'error'=>'Usá Agregar parte o Editar desde la pantalla.'],405);
$multipart=stripos($_SERVER['CONTENT_TYPE']??'','multipart/form-data')===0;
if((int)($_SERVER['CONTENT_LENGTH']??0)>6*1024*1024)pfp_reply(['ok'=>false,'error'=>'El envío es demasiado grande. Un archivo de hasta 5 MB por parte.'],413);
if($multipart){
    $input=$_POST;
    if(!$input)pfp_reply(['ok'=>false,'error'=>'PHP no recibió el formulario. Revisá post_max_size (8M o más) y upload_max_filesize (5M o más).'],413);
}else{
    $body=(string)file_get_contents('php://input',false,null,0,65537);
    if(strlen($body)>65536)pfp_reply(['ok'=>false,'error'=>'El formulario excede el tamaño admitido.'],413);
    $input=json_decode($body,true);
}
if(!is_array($input)||!is_string($input['token']??null)||empty($_SESSION['pumpoff_partes_token'])||!hash_equals($_SESSION['pumpoff_partes_token'],$input['token']))pfp_reply(['ok'=>false,'error'=>'La sesión del formulario venció. Actualizá la pantalla.'],403);
$cfg=require __DIR__.'/config.php';date_default_timezone_set($cfg['app']['tz']??'UTC');
try{
    if(count($_FILES)>1||($_FILES&&!isset($_FILES['ADJUNTO'])))throw new RuntimeException('Adjuntá un solo archivo por parte.');
    $upload=$_FILES['ADJUNTO']??null;
    if($upload!==null&&!is_array($upload))throw new RuntimeException('El adjunto no es válido.');
    $result=pfp_store(clear_db(),$input,new DateTimeImmutable('now'),(string)auth_user(),$upload);
    pfp_reply(['ok'=>true,'message'=>'Parte guardado.','result'=>$result]);
}catch(Throwable $e){pfp_reply(['ok'=>false,'error'=>$e instanceof RuntimeException?$e->getMessage():'No se pudo guardar el parte. Revisá la instalación y los permisos SQL.'],409);}
