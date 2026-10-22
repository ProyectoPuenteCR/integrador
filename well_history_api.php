<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/appconfig.php';
require_once __DIR__.'/includes/well_history.php';
auth_require();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function wh_out($data,$status=200){http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
if(!permissions_can_menu('monitoreo_pozos')||!permissions_can_menu('pi_historico'))wh_out(['ok'=>false,'error'=>'No tenés permiso para consultar estos históricos.'],403);
try{
    $input=$_GET;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if((int)($_SERVER['CONTENT_LENGTH']??0)>12000)throw new InvalidArgumentException('La selección es demasiado grande.');
        $raw=file_get_contents('php://input',false,null,0,12001);
        if(strlen($raw)>12000)throw new InvalidArgumentException('La selección es demasiado grande.');
        $input=json_decode($raw,true);
        if(!is_array($input))throw new InvalidArgumentException('La consulta no es válida.');
    }
    $well=wh_well($input['well']??'');$db=clear_db();
    if(!$db->ok())throw new RuntimeException('Sin conexión a SQL Server.');
    $catalog=wh_catalog($db,$well);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        foreach($catalog as &$point)unset($point['webid']);unset($point);
        wh_out(['ok'=>true,'well'=>$well,'columns'=>$catalog]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')wh_out(['ok'=>false,'error'=>'Método no permitido.'],405);
    // Reutilizar CLEAR_CONFIG con lecturas; no llamar config_ensure_table().
    $app=require __DIR__.'/config.php';$fallback=$app['pi']??[];
    $pi=['base'=>config_get('pi_base',$fallback['base']??'https://10.17.40.37/piwebapi'),
        'ca_bundle'=>config_get('pi_ca_bundle',$fallback['ca_bundle']??'')];
    $user=config_get('pi_user','');$pass=config_get('pi_pass','');
    $auth=($user!==''||$pass!=='')?'Basic '.base64_encode($user.':'.$pass):($fallback['auth']??'');
    $request=function($path)use($pi,$auth){
        if(!function_exists('curl_init'))throw new RuntimeException('El servidor necesita la extensión cURL para consultar PI.');
        $ch=curl_init(rtrim($pi['base'],'/').$path);
        $headers=['Accept: application/json'];if($auth!=='')$headers[]='Authorization: '.$auth;
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>45,
            CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        if($pi['ca_bundle']!==''){
            if(!is_file($pi['ca_bundle']))throw new RuntimeException('No se encontró el archivo de certificados configurado para PI.');
            curl_setopt($ch,CURLOPT_CAINFO,$pi['ca_bundle']);
        }
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$curlError=curl_errno($ch);curl_close($ch);
        if(in_array($curlError,[51,60,77],true))throw new RuntimeException('No se pudo validar el certificado de PI. Configurá una CA confiable mediante pi_ca_bundle o pi.ca_bundle en config.php.');
        if($body===false||$status<200||$status>=300)throw new RuntimeException('No se pudo consultar PI Web API'.($status?' (HTTP '.$status.')':'').'.');
        $data=json_decode($body,true);if(!is_array($data))throw new RuntimeException('La respuesta de PI no es válida.');return $data;
    };
    $result=wh_history($catalog,$input['columns']??[],$input['from']??'',$input['to']??'',$request);
    foreach($result['series'] as &$series)$series['reportKey']='well_history:'.sha1(json_encode([$well,$series['column'],$result['start'],$result['end']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));unset($series);
    wh_out(['ok'=>true,'well'=>$well]+$result);
}catch(InvalidArgumentException $e){wh_out(['ok'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){wh_out(['ok'=>false,'error'=>$e->getMessage()],502);}
