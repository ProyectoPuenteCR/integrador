<?php
require_once dirname(__DIR__).'/includes/tecss_3sigma_admin.php';
$n=0;function admin_check($ok,$label){global $n;$n++;if(!$ok)throw new RuntimeException($label);}
admin_check(sigma_analysis_admin_allowed(true,['active'=>1,'profile'=>'admin']),'Permitir administrador activo');
admin_check(!sigma_analysis_admin_allowed(true,['active'=>1,'profile'=>'operador']),'Rechazar rol de sesión viejo');
admin_check(!sigma_analysis_admin_allowed(true,['active'=>0,'profile'=>'admin']),'Rechazar administrador desactivado');
admin_check(!sigma_analysis_admin_allowed(true,['active'=>1,'profile'=>'admin'],true),'Rechazar sesión de reporte');
foreach([['POST',false,'token',['csrf'=>'token','confirmed'=>true]],['GET',true,'token',['csrf'=>'token','confirmed'=>true]],['POST',true,'token',['csrf'=>'otro','confirmed'=>true]],['POST',true,'token',['csrf'=>'token','confirmed'=>false]]] as $args){$bad=false;try{sigma_analysis_validate_request(...$args);}catch(RuntimeException $e){$bad=true;}admin_check($bad,'Bloquear solicitud sin permiso, método, CSRF o confirmación');}
$bad=false;try{sigma_analysis_path('CG-325\\Otro');}catch(RuntimeException $e){$bad=true;}admin_check($bad,'Bloquear selección de otro análisis por ruta');
$well='YPF.SC.CG-325';$frame=['Name'=>'3Sigma','Path'=>sigma_analysis_path($well),'WebId'=>str_repeat('a',30),'Status'=>'Disabled'];
$calls=[];$get=function($path)use($frame,&$calls){$calls[]=['GET',$path];$v=$frame;if(strpos($path,'?')===false)$v['Status']='Enabled';return $v;};
$write=function($path)use(&$calls){$calls[]=['PATCH',$path];};$logs=[];$audit=function($action,$detail)use(&$logs){$logs[]=$action;return true;};
$r=sigma_analysis_enable($get,$write,$audit,$well);admin_check($r['ok']&&$r['status']==='Enabled'&&$r['changed'],'Confirmar habilitación con lectura posterior');admin_check(array_column($calls,0)===['GET','PATCH','GET'],'No dar OK antes de releer AF');admin_check($logs===['AF_3SIGMA_ENABLE_REQUEST','AF_3SIGMA_ENABLE_OK'],'Auditar solicitud y confirmación');
$already=function($path)use($frame){return array_merge($frame,['Status'=>'Enabled']);};$writes=0;$write=function($path)use(&$writes){$writes++;};$r=sigma_analysis_enable($already,$write,$audit,$well);admin_check(!$r['changed']&&$writes===0,'No escribir ni reiniciar un análisis ya habilitado');
$unchanged=function($path)use($frame){return $frame;};$bad=false;try{sigma_analysis_enable($unchanged,$write,$audit,$well);}catch(RuntimeException $e){$bad=true;}admin_check($bad,'No dar OK si AF permanece deshabilitado');
$writes=0;$bad=false;try{sigma_analysis_enable($get,$write,function(){return false;},$well);}catch(RuntimeException $e){$bad=true;}admin_check($bad&&$writes===0,'No habilitar sin registrar auditoría');
$wrong=function($path)use($frame){return array_merge($frame,['Name'=>'3Sigma_Superado']);};$writes=0;$bad=false;try{sigma_analysis_enable($wrong,$write,$audit,$well);}catch(RuntimeException $e){$bad=true;}admin_check($bad&&$writes===0,'No actuar sobre la notificación ni otro análisis');
echo "OK: $n verificaciones de administración AF.\n";
