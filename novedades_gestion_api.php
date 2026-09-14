<?php
require_once __DIR__.'/includes/db.php';require_once __DIR__.'/includes/auth.php';require_once __DIR__.'/includes/permissions.php';require_once __DIR__.'/includes/novedades_gestion.php';
auth_require();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function ng_reply(array $data,int $status=200){http_response_code($status);echo nm_json($data);exit;}
try{$type=ng_text($_GET['tipo']??'',20,true);$spec=ng_spec($type);}catch(Throwable $e){ng_reply(['ok'=>false,'error'=>'Pantalla no válida.'],400);}
permissions_require_menu($spec['key']);
try{
 $db=clear_db();$method=$_SERVER['REQUEST_METHOD']??'';
 if($method==='GET'){
  $action=$_GET['accion']??'';
  if($action==='catalogo'){ng_reply(['ok'=>true,'catalog'=>ngr_catalog($db)]);}
  if($action==='historial'){ng_reply(['ok'=>true,'history'=>ng_history($db,$type,ng_id($_GET['id']??'',false))]);}
  ng_reply(['ok'=>false,'error'=>'Consulta no válida.'],400);
 }
 if($method!=='POST')ng_reply(['ok'=>false,'error'=>'Método no admitido.'],405);
 if((int)($_SERVER['CONTENT_LENGTH']??0)>6291456)ng_reply(['ok'=>false,'error'=>'El envío excede el límite. Un adjunto de hasta 5 MB.'],413);
 if(stripos($_SERVER['CONTENT_TYPE']??'','multipart/form-data')===0){$input=$_POST;if(!$input)ng_reply(['ok'=>false,'error'=>'No se recibió el formulario. Revisá los límites PHP/IIS: upload_max_filesize 5M y post_max_size 8M o mayores.'],413);}
 else{$body=(string)file_get_contents('php://input',false,null,0,65537);if(strlen($body)>65536)ng_reply(['ok'=>false,'error'=>'Formulario demasiado grande.'],413);$input=json_decode($body,true);}
 if(!is_array($input)||!is_string($input['token']??null)||empty($_SESSION['novedades_gestion_token'])||!hash_equals($_SESSION['novedades_gestion_token'],$input['token']))ng_reply(['ok'=>false,'error'=>'La sesión del formulario venció. Actualizá la pantalla.'],403);
 if(count($_FILES)>1||($_FILES&&!isset($_FILES['ADJUNTO'])))throw new RuntimeException('Adjuntá un solo archivo por registro.');
 if(($_GET['accion']??'')==='guardar_responsable'){
  if($type!=='REQUERIMIENTO'||$_FILES)ng_reply(['ok'=>false,'error'=>'Operación no válida.'],400);
  ng_reply(['ok'=>true,'result'=>ngr_add($db,$input,(string)auth_user())]);
 }
 if(($_GET['accion']??'')!=='')ng_reply(['ok'=>false,'error'=>'Operación no válida.'],400);
 $cfg=require __DIR__.'/config.php';date_default_timezone_set($cfg['app']['tz']??'UTC');
 $result=ng_store($db,$type,$input,new DateTimeImmutable('now'),(string)auth_user(),$_FILES['ADJUNTO']??null);ng_reply(['ok'=>true,'result'=>$result]);
}catch(Throwable $e){ng_reply(['ok'=>false,'error'=>$e instanceof RuntimeException?$e->getMessage():'No se pudo completar la operación. Revisá la instalación.'],409);}
