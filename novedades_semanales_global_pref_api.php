<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/appconfig.php';
auth_require();
permissions_require_menu('novedades_semanales_panel');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$key='novedades_semanales_zona_grupo';
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
    echo json_encode(['ok'=>true,'value'=>(string)config_get($key,'')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no admitido.']);exit;}
$body=(string)file_get_contents('php://input',false,null,0,4096);
$input=json_decode($body,true);
$value=trim((string)($input['value']??''));
$allowed=['','LHCG','CED Zona I','CED Zona II','PLH003','PCE002','PLH008','PCE021','PCE020','PCE010'];
if(!in_array($value,$allowed,true)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Preferencia inválida.']);exit;}
$ok=config_set($key,$value);
echo json_encode(['ok'=>$ok,'value'=>$value],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
