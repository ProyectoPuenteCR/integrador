<?php
require_once __DIR__.'/tecss_3sigma_events.php';

function sigma_analysis_admin_allowed($sessionAdmin,array $access,$reportMode=false)
{
    return $sessionAdmin&&!$reportMode&&!empty($access['active'])&&($access['profile']??'')==='admin';
}

function sigma_analysis_validate_request($method,$allowed,$storedToken,array $input)
{
    if(!$allowed)throw new RuntimeException('Esta acción está reservada a administradores.',403);
    if($method!=='POST')throw new RuntimeException('Método no permitido.',405);
    if(!is_string($storedToken)||$storedToken===''||!is_string($input['csrf']??null)||!hash_equals($storedToken,$input['csrf']))throw new RuntimeException('La sesión de confirmación venció. Recargá la pantalla.',403);
    if(($input['confirmed']??false)!==true)throw new RuntimeException('Falta confirmar la habilitación del análisis.',400);
}

function sigma_analysis_path($well)
{
    if(!is_string($well)||$well===''||strlen($well)>255||!preg_match('/^[\pL\pN(). _:-]+$/uD',$well))throw new RuntimeException('Pozo inválido.',400);
    $name=stripos($well,'YPF.SC.')===0?$well:'YPF.SC.'.$well;
    return '\\\\SWPCRDLHSCD51\\IUP\\Instalaciones\\Pozos\\'.$name.'\\TECSS\\Analyses[3Sigma]';
}

function sigma_analysis_resolve(callable $get,$well)
{
    $path=sigma_analysis_path($well);
    $analysis=$get('/analyses?'.http_build_query(['path'=>$path]));
    if(($analysis['Name']??'')!=='3Sigma'||strcasecmp($analysis['Path']??'',$path)!==0||!preg_match('/^[A-Za-z0-9_-]{20,1000}$/D',$analysis['WebId']??''))throw new RuntimeException('AF no devolvió el análisis 3Sigma exacto del pozo.',502);
    return $analysis;
}

function sigma_analysis_enable(callable $get,callable $enable,callable $audit,$well)
{
    $analysis=sigma_analysis_resolve($get,$well);
    $before=$analysis['Status']??'';
    if(!in_array($before,['Enabled','Disabled'],true))throw new RuntimeException('AF no devolvió un estado válido para habilitar el análisis.',409);
    if($before==='Enabled')return ['ok'=>true,'status'=>'Enabled','changed'=>false,'message'=>'OK · El análisis 3Sigma ya está habilitado en AF.'];
    if(!$audit('AF_3SIGMA_ENABLE_REQUEST',['well'=>$well,'path'=>$analysis['Path'],'before'=>$before]))throw new RuntimeException('No se pudo registrar la solicitud en la auditoría. No se ejecutó el cambio.',503);
    $target='/analyses/'.rawurlencode($analysis['WebId']);
    try{
        $enable($target);
        // Success is based on a fresh AF read, not on HTTP PATCH succeeding alone.
        $after=$get($target);
        if(($after['Name']??'')!=='3Sigma'||strcasecmp($after['Path']??'',$analysis['Path'])!==0||($after['Status']??'')!=='Enabled')throw new RuntimeException('La solicitud se envió, pero AF no confirmó el análisis habilitado. Verificá su estado antes de reintentar.',502);
        $logged=$audit('AF_3SIGMA_ENABLE_OK',['well'=>$well,'path'=>$analysis['Path'],'before'=>$before,'after'=>'Enabled']);
        return ['ok'=>true,'status'=>'Enabled','changed'=>true,'message'=>'OK · Análisis 3Sigma habilitado y verificado en AF.'.($logged?'':' No se pudo registrar la confirmación en la auditoría.')];
    }catch(RuntimeException $error){
        $audit('AF_3SIGMA_ENABLE_ERROR',['well'=>$well,'path'=>$analysis['Path'],'before'=>$before,'error'=>$error->getMessage()]);
        throw $error;
    }
}

function sigma_analysis_transport(array $connection)
{
    if(!function_exists('curl_init'))throw new RuntimeException('La conexión AF requiere cURL.',503);
    return function($method,$path)use($connection){
        $read=$method==='GET'&&(strpos($path,'/analyses?path=')===0||preg_match('#^/analyses/[A-Za-z0-9_-]{20,1000}$#D',$path));
        $write=$method==='PATCH'&&preg_match('#^/analyses/[A-Za-z0-9_-]{20,1000}$#D',$path);
        if(!$read&&!$write)throw new RuntimeException('Operación AF no permitida.',400);
        $ch=curl_init($connection['base'].$path);
        $headers=['Accept: application/json','Authorization: '.$connection['auth'],'Cache-Control: no-cache','X-Requested-With: XMLHttpRequest'];
        if($write)$headers[]='Content-Type: application/json';
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        if($connection['ca']!=='')curl_setopt($ch,CURLOPT_CAINFO,$connection['ca']);
        if($connection['resolve'])curl_setopt($ch,CURLOPT_RESOLVE,$connection['resolve']);
        if($write)curl_setopt($ch,CURLOPT_POSTFIELDS,'{"Status":"Enabled"}');
        $body=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($body===false||($write&&!in_array($http,[200,204],true))||($read&&$http!==200))throw new RuntimeException('AF rechazó la operación (HTTP '.$http.'). No se confirmó la habilitación; comprobá permisos y compatibilidad de PI Web API.',502);
        if($write)return;
        $result=json_decode($body,true);
        if(!is_array($result))throw new RuntimeException('Respuesta de AF inválida.',502);
        return $result;
    };
}
