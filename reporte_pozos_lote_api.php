<?php
require_once __DIR__.'/includes/db.php';require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';require_once __DIR__.'/includes/reporte_pozos_lote.php';
auth_require();permissions_require_menu('novedades_semanales_monitoreo');
header('Content-Type: application/json; charset=UTF-8');
function rp_reply(array $value,int $status=200){http_response_code($status);echo nm_json($value);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')rp_reply(['ok'=>false,'error'=>'Usá Guardar pozos en el parte.'],405);
$body=file_get_contents('php://input',false,null,0,1048577);
if(strlen((string)$body)>1048576)rp_reply(['ok'=>false,'error'=>'El lote es demasiado grande.'],413);
$input=json_decode((string)$body,true);
if(!is_array($input)||!is_string($input['token']??null)||empty($_SESSION['pumpoff_partes_token'])||!hash_equals($_SESSION['pumpoff_partes_token'],$input['token']))rp_reply(['ok'=>false,'error'=>'La sesión venció. Actualizá la pantalla.'],403);
if(!is_array($input['rows']??null))rp_reply(['ok'=>false,'error'=>'Falta la lista de pozos.'],422);
$cfg=require __DIR__.'/config.php';date_default_timezone_set($cfg['app']['tz']??'UTC');
try{$result=rp_store_batch(clear_db(),$input['rows'],new DateTimeImmutable('now'),(string)auth_user());rp_reply(['ok'=>true,'result'=>$result,'message'=>$result['count'].' pozos guardados en el parte.']);}
catch(Throwable $e){rp_reply(['ok'=>false,'error'=>$e instanceof RuntimeException?$e->getMessage():'No se pudo guardar el lote. Los datos siguen disponibles para reintentar.'],409);}
