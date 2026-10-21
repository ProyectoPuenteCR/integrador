<?php
require_once dirname(__DIR__).'/includes/dashboard_pozos_kpi.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
$checks=0;
function check_dp_kpi($condition,$message){global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function daily_fixture($from,$until,$without=10,$communicating=90,$faults=2){
    $rows=[];
    for($d=new DateTimeImmutable($from);$d<new DateTimeImmutable($until);$d=$d->modify('+1 day')){
        $rows[]=['DIA_OPERATIVO'=>$d->format('Y-m-d'),'HORA_CORTE'=>'00:00:00','COBERTURA_COMPLETA'=>1,
            'SIN_COM'=>$without,'COMUNICANDO'=>$communicating,'EN_FALLA'=>$faults];
    }
    return $rows;
}
$now=strtotime('2026-10-21 10:00:00');
$rows=array_merge(daily_fixture('2026-10-07','2026-10-14',20,80,4),daily_fixture('2026-10-14','2026-10-21',10,90,2));
$week=dp_kpi_comparisons($rows,$now)[0];
check_dp_kpi($week['current']['start']==='2026-10-14'&&$week['current']['end']==='2026-10-21','La semana cerrada debe ser miércoles a martes.');
check_dp_kpi($week['ready']&&$week['metrics'][0]['current']===10.0,'El KPI usa el promedio de cierres, no la suma de pozos repetidos.');
check_dp_kpi($week['metrics'][0]['percent']===-50.0&&$week['metrics'][0]['favorable']===true,'Bajar pozos sin comunicación debe ser favorable.');
check_dp_kpi($week['metrics'][1]['favorable']===true&&$week['metrics'][2]['favorable']===true,'Mejora de comunicación y reducción de fallas deben tener signo correcto.');
$missing=$rows;array_pop($missing);
$incomplete=dp_kpi_comparisons($missing,$now)[0];
check_dp_kpi(!$incomplete['ready']&&$incomplete['current']['values']['SIN_COM']===null&&$incomplete['metrics'][0]['delta']===null,'Un día faltante no puede transformarse en cero o comparación parcial.');
$gap=$rows;$gap[7]['COBERTURA_COMPLETA']=0;
check_dp_kpi(dp_kpi_comparisons($gap,$now)[0]['current']['available']===6,'Una interrupción de captura invalida la cobertura del día.');
$different=$rows;$different[7]['HORA_CORTE']='08:00:00';
check_dp_kpi(dp_kpi_comparisons($different,$now)[0]['current']['available']===6,'No mezclar cierres con horas de corte distintas.');
$zero=daily_fixture('2026-10-07','2026-10-21',0,100,0);
$zeroCmp=dp_kpi_comparisons($zero,$now)[0];
check_dp_kpi($zeroCmp['metrics'][0]['percent']===0.0,'Cero contra cero tiene variación cero.');
$newFault=array_merge(daily_fixture('2026-10-07','2026-10-14',0,100,0),daily_fixture('2026-10-14','2026-10-21',10,90,3));
$newCmp=dp_kpi_comparisons($newFault,$now)[0];
check_dp_kpi($newCmp['metrics'][0]['delta']===10.0&&$newCmp['metrics'][0]['percent']===null,'Una base cero no tiene porcentaje infinito ni 100% inventado.');
$month=dp_kpi_comparisons(array_merge(daily_fixture('2026-08-01','2026-09-01',31),daily_fixture('2026-09-01','2026-10-01',30)),$now)[1];
check_dp_kpi($month['ready']&&$month['current']['expected']===30&&$month['previous']['expected']===31,'Meses de distinta duración deben compararse con su cantidad real de días.');
check_dp_kpi($month['metrics'][0]['current']===30.0&&$month['metrics'][0]['previous']===31.0,'No sumar muestras mensuales como pozos distintos.');
$before=dp_kpi_comparisons([],strtotime('2026-10-21 07:59:00'),'08:00')[0];
$after=dp_kpi_comparisons([],strtotime('2026-10-21 08:00:00'),'08:00')[0];
check_dp_kpi($before['current']['start']==='2026-10-07'&&$after['current']['start']==='2026-10-14','El cierre semanal respeta la hora operativa configurada.');
$seconds=dp_kpi_comparisons([],strtotime('2026-10-21 08:00:15'),'08:00:30')[0];
check_dp_kpi($seconds['current']['start']==='2026-10-07','El corte operativo también respeta los segundos configurados.');
$year=dp_kpi_comparisons([],strtotime('2027-01-10 10:00:00'))[1];
check_dp_kpi($year['current']['start']==='2026-12-01'&&$year['previous']['start']==='2026-11-01','El cambio de año no desplaza los meses.');
$top=dp_top_without_comm([
    ['pozo'=>'desconocido','comm'=>'Sin comunicación','sin_com_desde'=>null,'date'=>$now],
    ['pozo'=>'corto','comm'=>'Sin comunicación','sin_com_desde'=>$now-86400,'date'=>$now],
    ['pozo'=>'largo','comm'=>'Sin comunicación','sin_com_desde'=>$now-10*86400,'date'=>$now-86400],
    ['pozo'=>'recuperado','comm'=>'Comunicando','sin_com_desde'=>$now-50*86400,'date'=>$now],
],$now);
check_dp_kpi(array_column($top,'pozo')===['largo','corto','desconocido'],'Top ordenado por días descendentes; fechas desconocidas al final y recuperados fuera.');
check_dp_kpi($top[0]['dias_sin_com']===9.0,'Una caché detenida no puede prolongar la duración más allá de su última observación.');
echo "OK: $checks comprobaciones de KPI y duración.\n";
