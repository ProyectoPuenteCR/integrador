<?php
require_once dirname(__DIR__).'/includes/tecss_3sigma_trend.php';
$count=0;function trend_check($ok,$message){global $count;$count++;if(!$ok)throw new RuntimeException($message);}
function trend_samples($pairs){return array_map(function($p){return ['time'=>'2026-10-26T'.$p[0].':00+00:00','value'=>$p[1]];},$pairs);}
$from=new DateTimeImmutable('2026-10-26T00:00:00Z');$until=new DateTimeImmutable('2026-10-26T12:00:00Z');
$r=sigma_condition_intervals(trend_samples([['00:00',0],['01:00',1],['01:20',1],['01:40',0],['02:00',1],['02:10',0]]),$from,$until);
trend_check(count($r)===2,'Separar excesos consecutivos');trend_check($r[0]['duration_seconds']===2400.0&&$r[0]['complete'],'Duración desde primera muestra alta a primera baja');
trend_check($r[1]['duration_seconds']===600.0,'No usar número de excesos para duración');
$r=sigma_condition_intervals(trend_samples([['00:00',1],['01:00',1],['02:00',0]]),$from,$until);
trend_check(!$r[0]['start_known']&&!$r[0]['complete'],'Inicio desconocido al límite del período');
$r=sigma_condition_intervals(trend_samples([['00:00',0],['01:00',1],['01:10',1],['02:00',null],['03:00',1],['04:00',0]]),$from,$until);
trend_check(count($r)===2&&$r[0]['state']==='gap'&&!$r[0]['complete'],'Dato malo corta continuidad');trend_check(!$r[1]['start_known'],'No unir tramos a través de falta de datos');
$r=sigma_condition_intervals(trend_samples([['00:00',0],['01:00',1],['01:10',1]]),$from,$until);
trend_check($r[0]['end']===null&&$r[0]['duration_seconds']===600.0,'No extender último valor hasta ahora');
$r=sigma_condition_intervals([['time'=>'2026-10-26T01:00:00-03:00','value'=>1],['time'=>'2026-10-26T03:00:00Z','value'=>0],['time'=>'2026-10-26T05:00:00Z','value'=>0]],$from,$until);
trend_check(count($r)===1&&$r[0]['duration_seconds']===3600.0,'Ordenar instantes con distintas zonas horarias');
$get=function($path){return ['Items'=>[['Timestamp'=>'2026-10-26T00:00:00Z','Value'=>1,'Good'=>true],['Timestamp'=>'2026-10-26T01:00:00Z','Value'=>['Name'=>'No Data'],'Good'=>false]]];};
$s=sigma_af_series($get,['WebId'=>'test','Name'=>'3SIGMA_SUP'],$from,$until,true);trend_check($s['samples'][1]['value']===null,'Conservar muestras malas como cortes');
$get=function($path){return ['Items'=>[],'Links'=>['Next'=>'next']];};$bad=false;try{sigma_af_series($get,['WebId'=>'test','Name'=>'VI-001-V'],$from,$until);}catch(RuntimeException $e){$bad=true;}trend_check($bad,'No publicar duración desde colección truncada');
$rule=['Name'=>'PerformanceEquation','IsConfigured'=>true,'ConfigString'=>"@group(\"Start triggers\") @name(\"StartTrigger\") @severity(\"Critical\") @timetrue(\"10800\")\nStartTrigger1 := 'VI-001-V' > '3SIGMA';"];
trend_check(sigma_af_minimum_duration([$rule])===10800,'Leer tres horas desde el disparador real, no desde un valor fijo');
trend_check(sigma_af_minimum_duration([$rule,$rule])===null,'Rechazar tiempos mínimos ambiguos');
trend_check(sigma_af_minimum_duration([['Name'=>'PerformanceEquation','IsConfigured'=>true,'ConfigString'=>'otro formato']])===null,'No inventar tiempo si cambia la configuración');
echo "OK: $count verificaciones de duración y calidad PI.\n";
