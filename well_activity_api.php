<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/well_history.php';
require_once __DIR__.'/includes/well_activity.php';
auth_require();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function wa_out($data,$status=200){http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
if($_SERVER['REQUEST_METHOD']!=='GET')wa_out(['ok'=>false,'error'=>'Método no permitido.'],405);
try{
    $source=wh_source(array_key_exists('source',$_GET)?$_GET['source']:'monitoreo_pozos');
    if(!wh_can_activity($source))wa_out(['ok'=>false,'error'=>'No tenés permiso para ver las alarmas del pozo.'],403);
    $well=wh_well($_GET['well']??'');
    $config=require __DIR__.'/config.php';date_default_timezone_set($config['timezone']??'America/Argentina/Buenos_Aires');
    $db=clear_db();if(!$db->ok())throw new RuntimeException('Sin conexión a SQL Server.');
    $now=new DateTimeImmutable();
    wa_out(['ok'=>true]+wa_alarms($db,$well,$now->modify('-24 hours')->format('Y-m-d\TH:i:s'),$now->format('Y-m-d\TH:i:s')));
}catch(InvalidArgumentException $e){wa_out(['ok'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){wa_out(['ok'=>false,'error'=>'No se pudo consultar el historial de alarmas.'],502);}
