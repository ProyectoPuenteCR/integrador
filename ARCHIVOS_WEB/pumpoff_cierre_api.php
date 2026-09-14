<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/pumpoff.php';
auth_require();permissions_require_menu('novedades_semanales_monitoreo');
header('Content-Type: application/json; charset=UTF-8');
function pf_reply($ok,$message,$status=200){http_response_code($status);echo json_encode(['ok'=>$ok,'message'=>$message,'error'=>$ok?'':$message],JSON_UNESCAPED_UNICODE);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')pf_reply(false,'Usá el botón Guardar lectura semanal.',405);
if(!auth_es_admin())pf_reply(false,'Solo un administrador puede guardar lecturas semanales.',403);
$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input)||!is_string($input['token']??null)||!hash_equals((string)($_SESSION['pumpoff_snapshot_token']??''),$input['token'])||$input['token']==='')pf_reply(false,'La lectura venció. Actualizá la pantalla.',403);
$snapshot=$_SESSION['pumpoff_snapshot']??[];
if(empty($snapshot['created'])||time()-(int)$snapshot['created']>900)pf_reply(false,'La lectura tiene más de 15 minutos. Actualizá la pantalla.',409);
$cfg=require __DIR__.'/config.php';date_default_timezone_set($cfg['app']['tz']??'UTC');
try {
    $config=pf_config();
    if(($snapshot['config_hash']??'')!==hash('sha256',serialize($config)))throw new RuntimeException('Cambió la configuración. Actualizá la pantalla.');
    pf_store_week(clear_db(),$snapshot,new DateTimeImmutable('now'),(string)auth_user());
    unset($_SESSION['pumpoff_snapshot'],$_SESSION['pumpoff_snapshot_token']);
    pf_reply(true,'Lectura semanal guardada. No se modificaron Jobs ni datos de BM.');
}catch(Throwable $e){pf_reply(false,$e instanceof RuntimeException?$e->getMessage():'No se pudo guardar la lectura. Revisá la instalación y permisos del histórico.',409);}
