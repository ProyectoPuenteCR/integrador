<?php
require_once dirname(__DIR__).'/includes/tecss_3sigma_events.php';
$n=0;
function check_sigma($test,$message){global $n;$n++;if(!$test)throw new RuntimeException($message);}
function notification_sigma($subscribers,$creator='NT SERVICE\\PINotificationsService'){
 return ['Id'=>'annotation-1','Name'=>'Notification Subscribers','Creator'=>$creator,'CreationDate'=>'2026-10-26T09:47:00Z','Value'=>'Notification sent to 14 subscriber(s).','Description'=>json_encode(['Notification'=>'3Sigma Superado','Subscribers'=>$subscribers])];
}
$sub=['Name'=>'Persona - Email','Configuration'=>'one@example.test','Type'=>'Email','SendTime'=>'2026-10-26T09:46:10Z','SendStatus'=>'Success','RetryCount'=>0];
$failed=array_merge($sub,['Configuration'=>'two@example.test','SendStatus'=>'Failed']);
$audits=sigma_af_audits([notification_sigma([$sub,$failed])]);
check_sigma(count($audits)===2,'Separar éxito parcial y fallo por destinatario');
check_sigma($audits[0]['status']==='Enviado'&&$audits[1]['status']==='Error','Usar SendStatus real');
check_sigma($audits[1]['error']===null,'No fabricar detalle de error ausente');
check_sigma($audits[0]['sent_at']==='2026-10-26T09:46:10.000000+00:00','Usar SendTime, no CreationDate');
check_sigma($audits[0]['recipients']===['one@example.test'],'Usar Configuration histórico');
check_sigma(sigma_af_audits([notification_sigma([$sub],'Usuario')])===[],'Ignorar anotaciones de usuario aunque aparenten envío');
$summary=notification_sigma([]);unset($summary['Description']);
check_sigma(sigma_af_audits([$summary])===[],'El resumen no prueba emails enviados');
check_sigma(sigma_af_audits([notification_sigma([array_merge($sub,['Type'=>'WebService'])])])===[],'No llamar email a otro canal');
$unknown=sigma_af_audits([notification_sigma([array_merge($sub,['SendStatus'=>'FutureStatus'])])]);
check_sigma($unknown[0]['status']==='Estado PI: FutureStatus','Conservar estados desconocidos sin presumir éxito');
$pending=sigma_af_audits([notification_sigma([array_merge($sub,['SendStatus'=>'Pending','SendTime'=>null])])]);
check_sigma($pending[0]['sent_at']===null&&$pending[0]['status']==='Pendiente','No inventar fecha de envío pendiente');
$retry=notification_sigma([array_merge($sub,['RetryCount'=>1,'SendTime'=>'2026-10-26T09:50:00Z'])]);$retry['Id']='annotation-2';
check_sigma(count(sigma_af_audits([notification_sigma([$sub]),$retry]))===2,'Conservar reintentos de distintas anotaciones');
$frame=['Id'=>'event-1','WebId'=>'frame-1','Name'=>'YPF.SC.LH-945-3SIGMA-TECSS-2026-10-26','TemplateName'=>'3Sigma_Superado','StartTime'=>'2026-10-26T06:46:00Z','EndTime'=>'2026-10-26T10:16:00Z'];
$requests=[];
$get=function($path)use(&$requests,$frame,$sub){$requests[]=$path;if(strpos($path,'/assetdatabases?')===0)return ['WebId'=>'db-1'];if(strpos($path,'/eventframes?')!==false)return ['Items'=>[$frame]];return ['Items'=>[notification_sigma([$sub])]];};
$result=sigma_af_collect($get,'YPF.SC.LH-945',new DateTimeImmutable('2026-10-25T00:00:00-03:00'),new DateTimeImmutable('2026-10-27T00:00:00-03:00'));
check_sigma(count($result['events'])===1&&count($result['events'][0]['audits'])===1,'Cargar eventos y auditoría por separado');
check_sigma(strpos($requests[1],'templateName=3Sigma_Superado')!==false&&strpos($requests[1],'searchMode=Overlapped')!==false,'Consultar plantilla verificada y eventos superpuestos');
$unavailable=function($path)use($frame){if(strpos($path,'/assetdatabases?')===0)return ['WebId'=>'db'];if(strpos($path,'/eventframes?')!==false)return ['Items'=>[$frame]];throw new RuntimeException('HTTP 403');};
$result=sigma_af_collect($unavailable,'YPF.SC.LH-945',new DateTimeImmutable('2026-10-25T00:00:00-03:00'),new DateTimeImmutable('2026-10-27T00:00:00-03:00'));
check_sigma(count($result['events'])===1&&!$result['events'][0]['audit_available']&&count($result['warnings'])===1,'Conservar alerta cuando falla el permiso de anotaciones');
$bad=false;try{sigma_af_collect($get,'*',new DateTimeImmutable('2026-10-25'),new DateTimeImmutable('2026-10-27'));}catch(RuntimeException $e){$bad=true;}
check_sigma($bad,'Rechazar comodines de pozo');
check_sigma(sigma_af_date('2026-10-26 09:00:00')===null,'No aceptar timestamps AF sin zona');
echo "OK: $n verificaciones de auditoría 3Sigma.\n";
