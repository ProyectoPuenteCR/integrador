<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/appconfig.php';
require_once __DIR__.'/includes/well_history.php';
require_once __DIR__.'/includes/pi_transport.php';
require_once __DIR__.'/includes/well_cards.php';
auth_require();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function wh_out($data,$status=200){http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
try{
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true))wh_out(['ok'=>false,'error'=>'Método no permitido.'],405);
    $input=$_GET;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if((int)($_SERVER['CONTENT_LENGTH']??0)>12000)throw new InvalidArgumentException('La selección es demasiado grande.');
        $raw=file_get_contents('php://input',false,null,0,12001);
        if(strlen($raw)>12000)throw new InvalidArgumentException('La selección es demasiado grande.');
        $input=json_decode($raw,true);
        if(!is_array($input))throw new InvalidArgumentException('La consulta no es válida.');
    }
    $source=wh_source(array_key_exists('source',$input)?$input['source']:'monitoreo_pozos');
    if(!wh_can_history($source))wh_out(['ok'=>false,'error'=>'No tenés permiso para consultar estos históricos.'],403);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    if(($input['action']??'')==='cards'&&!wh_cards_allowed($source))
        throw new InvalidArgumentException('Las cartas dinamométricas no corresponden a la ficha PCP.');
    $well=wh_well($input['well']??'');$db=clear_db();
    if(!$db->ok())throw new RuntimeException('Sin conexión a SQL Server.');
    $catalog=wh_catalog($db,$well,$source);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        foreach($catalog as &$point)unset($point['webid']);unset($point);
        wh_out(['ok'=>true,'well'=>$well,'source'=>$source,'columns'=>$catalog]);
    }
    // Reutilizar CLEAR_CONFIG con lecturas; no llamar config_ensure_table().
    $app=require __DIR__.'/config.php';$fallback=$app['pi']??[];
    $pi=['base'=>config_get('pi_base',$fallback['base']??'https://10.17.40.37/piwebapi'),
        'ca_bundle'=>config_get('pi_ca_bundle',$fallback['ca_bundle']??'')];
    $user=config_get('pi_user','');$pass=config_get('pi_pass','');
    $auth=($user!==''||$pass!=='')?'Basic '.base64_encode($user.':'.$pass):($fallback['auth']??'');
    $request=function($path)use($pi,$auth){
        $response=clear_pi_transport($path,$pi,$auth,45);
        $status=$response['status'];$body=$response['body'];
        if($status<200||$status>=300)throw new RuntimeException('No se pudo consultar PI Web API (HTTP '.$status.').');
        $data=json_decode($body,true);if(!is_array($data))throw new RuntimeException('La respuesta de PI no es válida.');return $data;
    };
    if(($input['action']??'')==='cards')wh_out(['ok'=>true,'well'=>$well]+wh_cards($catalog,$input['at']??'',$input['windowSeconds']??60,$request,($input['mode']??'manual')==='latest'));
    $result=wh_history($catalog,$input['columns']??[],$input['from']??'',$input['to']??'',$request,$input['intervalSeconds']??3600);
    // Conservar las claves BM existentes y separar el origen de los reportes PCP.
    $reportScope=$source==='monitoreo_pozos'?[]:[$source];
    foreach($result['series'] as &$series)$series['reportKey']='well_history:'.sha1(json_encode(array_merge($reportScope,[$well,$series['column'],$result['start'],$result['end'],$result['interval']]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));unset($series);
    $columns=array_column($result['series'],'column');sort($columns);
    $result['combinedReportKey']='well_history_combined:'.sha1(json_encode(array_merge($reportScope,[$well,$columns,$result['start'],$result['end'],$result['interval']]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    wh_out(['ok'=>true,'well'=>$well,'source'=>$source]+$result);
}catch(InvalidArgumentException $e){wh_out(['ok'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){wh_out(['ok'=>false,'error'=>$e->getMessage()],502);}
