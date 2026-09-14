<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/reporting.php';
auth_require();
header('Content-Type: application/json; charset=UTF-8');
function rc_out(array $data,$status=200){http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(!permissions_can_menu('novedades_semanales_reporte'))rc_out(['ok'=>false,'error'=>'No tenés permisos para usar la libreta de reportes.'],403);
if(!report_ensure_tables())rc_out(['ok'=>false,'error'=>'No se pudo preparar la libreta de direcciones.'],500);
$raw=file_get_contents('php://input');$json=json_decode((string)$raw,true);$input=is_array($json)?$json:array_merge($_GET,$_POST);
$action=strtolower(trim((string)($input['action']??'list')));
if($action==='list')rc_out(['ok'=>true,'contacts'=>report_contacts_all()]);
if($action==='save'){
    [$ok,$message]=report_contact_save($input['name']??'',$input['email']??'',auth_user());
    if(!$ok)rc_out(['ok'=>false,'error'=>$message],422);
    audit_log('REPORTE_CONTACTO_GUARDAR','reportes',strtolower(trim((string)($input['email']??''))),auth_user());
    rc_out(['ok'=>true,'message'=>$message,'contacts'=>report_contacts_all()]);
}
rc_out(['ok'=>false,'error'=>'Acción no válida.'],405);
