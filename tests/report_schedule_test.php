<?php
require_once dirname(__DIR__).'/includes/report_schedule.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
$checks=0;
function eq($actual,$expected,$label){
    global $checks;$checks++;
    if($actual!==$expected)throw new RuntimeException($label.': '.var_export($actual,true).' != '.var_export($expected,true));
}
function next_is($frequency,$from,$expected,$days='',$times='16:00,08:00,08:00',$zone='America/Argentina/Buenos_Aires'){
    $date=new DateTimeImmutable($from);
    $before=$date->format('c');
    $r=['FRECUENCIA'=>$frequency,'HORARIOS'=>$times,'DIAS_SEMANA'=>$days,'ZONA_HORARIA'=>$zone];
    eq(report_next_run($r,$date)->format('Y-m-d H:i:s'),$expected,$frequency.' '.$from);
    eq($date->format('c'),$before,'El parámetro no se modifica');
}
eq(report_normalize_times('16:00;8:00,08:00'),'08:00,16:00','Orden, formato y duplicados');
eq(report_normalize_times('00:00,23:59'),'00:00,23:59','Límites');
foreach(['','24:00','12:60','-1:00','08:00 basura', ['08:00']] as $bad){
    $thrown=false;try{report_normalize_times($bad);}catch(InvalidArgumentException $e){$thrown=true;}
    eq($thrown,true,'Rechazar horario inválido');
}
$many=[];for($i=0;$i<34;$i++)$many[]=sprintf('00:%02d',$i);
eq(strlen(report_normalize_times(implode(',',array_slice($many,0,33)))),197,'33 horarios compatibles');
$thrown=false;try{report_normalize_times(implode(',',$many));}catch(InvalidArgumentException $e){$thrown=true;}
eq($thrown,true,'Evitar desbordar NVARCHAR(200)');
next_is('daily','2026-10-21 07:59:59','2026-10-21 08:00:00');
next_is('daily','2026-10-21 08:00:00','2026-10-21 16:00:00');
next_is('daily','2026-10-21 16:00:00','2026-10-22 08:00:00');
next_is('daily','2026-10-21 08:00:00','2026-10-22 08:00:00','','08:00');
next_is('weekdays','2026-10-23 16:00:00','2026-10-26 08:00:00');
next_is('weekly','2026-10-21 08:00:00','2026-10-21 16:00:00','3');
next_is('weekly','2026-10-21 16:00:00','2026-10-28 08:00:00','3');
next_is('custom','2026-10-21 16:00:00','2026-10-23 08:00:00','1,3,5');
next_is('monthly','2026-10-01 08:00:00','2026-10-01 16:00:00');
next_is('monthly','2026-12-01 16:00:00','2027-01-01 08:00:00');
next_is('monthly','2028-02-29 16:00:00','2028-03-01 08:00:00');
next_is('daily','2026-10-21 11:00:00+00:00','2026-10-21 16:00:00');
next_is('daily','2026-10-21 07:00:00','2026-10-21 08:00:00','','');
$r=['HORARIOS'=>'08:00,16:00','ZONA_HORARIA'=>'UTC'];
eq(report_schedule_next_storage($r,new DateTime('2026-10-21 04:00:00')),'2026-10-21 05:00:00','Persistir zona de aplicación');

// Doble de SQL para verificar las intercalaciones de reservas y edición.
// La ejecución real de UPDATE ... OUTPUT requiere SQL Server.
class ScheduleDbDouble {
    public $row,$beforeUpdate=null,$failUpdate=false;
    function __construct(){
        $this->row=['ID'=>1,'ACTIVO'=>1,'FRECUENCIA'=>'daily','DIAS_SEMANA'=>'','HORARIOS'=>'08:00,16:00','DUE_KEY'=>'2026-10-21T08:00:00','VERSION_KEY'=>'','ZONA_HORARIA'=>'America/Argentina/Buenos_Aires'];
    }
    function all($sql,$p){
        if(strpos($sql,'SELECT')===0){
            if(!$this->row['ACTIVO'] || $this->row['ID']!==$p[0] || ($this->row['DUE_KEY']!=='' && new DateTime($this->row['DUE_KEY'])>new DateTime($p[1])))return [];
            return [$this->row];
        }
        if($this->beforeUpdate){$cb=$this->beforeUpdate;$this->beforeUpdate=null;$cb();}
        if($this->failUpdate || !$this->row['ACTIVO'] || $this->row['DUE_KEY']!==$p[2] || $this->row['VERSION_KEY']!==$p[4])return [];
        $this->row['DUE_KEY']=str_replace(' ','T',$p[0]);
        return [['ID'=>$this->row['ID']]];
    }
}
$now=new DateTime('2026-10-21 08:00:00');
$db=new ScheduleDbDouble();
$first=report_schedule_claim($db,1,$now);
eq($first['RESERVED_NEXT'],'2026-10-21 16:00:00','Reservar el segundo horario');
eq(report_schedule_claim($db,1,$now),null,'Repetir scheduler no repite el turno');
eq(report_schedule_claim($db,1,new DateTime('2026-10-21 16:00:00'))['RESERVED_NEXT'],'2026-10-22 08:00:00','Reservar el segundo envío');
$db=new ScheduleDbDouble();
eq(report_schedule_claim($db,1,new DateTime('2026-10-21 17:00:00'))['RESERVED_NEXT'],'2026-10-21 16:00:00','No saltar el turno si hay demora');
eq(report_schedule_claim($db,1,new DateTime('2026-10-21 17:00:00'))['RESERVED_NEXT'],'2026-10-22 08:00:00','Recuperar segundo turno atrasado');
$db=new ScheduleDbDouble();$winner=null;
$db->beforeUpdate=function() use($db,$now,&$winner){$winner=report_schedule_claim($db,1,$now);};
eq(report_schedule_claim($db,1,$now),null,'El ejecutor con lectura obsoleta pierde');
eq($winner['ID'],1,'Solo el ganador recibe la reserva');
$db=new ScheduleDbDouble();
$db->beforeUpdate=function() use($db){$db->row['VERSION_KEY']='2026-10-21T07:59:59';};
eq(report_schedule_claim($db,1,$now),null,'Respetar una edición concurrente');
$db=new ScheduleDbDouble();$db->row['ACTIVO']=0;
eq(report_schedule_claim($db,1,$now),null,'No ejecutar pausados');
$db=new ScheduleDbDouble();$db->failUpdate=true;
eq(report_schedule_claim($db,1,$now),null,'Sin reserva confirmada no se envía');
$db=new ScheduleDbDouble();$db->row['DUE_KEY']='';
eq(report_schedule_claim($db,1,$now)['RESERVED_NEXT'],'2026-10-21 16:00:00','Compatibilidad con próxima ejecución NULL');
echo "OK: $checks comprobaciones\n";
