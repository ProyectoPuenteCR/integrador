<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/well_history.php';
require_once __DIR__.'/includes/well_zafiro.php';
auth_require();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function wz_out($data,$status=200){http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
if($_SERVER['REQUEST_METHOD']!=='GET')wz_out(['ok'=>false,'error'=>'Método no permitido.'],405);
$source=$_GET['source']??'';
if(!is_string($source)||!in_array($source,['monitoreo_pozos','telemetria_pcp','telemetria_bes'],true)||!permissions_can_menu($source))wz_out(['ok'=>false,'error'=>'Sin permiso para consultar esta ficha.'],403);
try{
    $well=wh_well($_GET['well']??'');$db=clear_db();
    if(!$db->ok())throw new RuntimeException('Sin conexión a SQL Server.');
    wz_out(['ok'=>true]+wz_production($db,$well));
}catch(InvalidArgumentException $e){wz_out(['ok'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){wz_out(['ok'=>false,'error'=>$e->getMessage()],502);}
