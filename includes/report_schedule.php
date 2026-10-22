<?php
/* Funciones puras de horarios y reserva de ejecuciones; sin cambios de esquema. */
function report_normalize_times($value){
    if(!is_string($value)) throw new InvalidArgumentException('Horarios inválidos. Usá HH:MM.');
    $items=preg_split('/[;,]+/',trim($value));
    $out=[];
    foreach($items as $time){
        $time=trim($time);
        if($time==='') continue;
        if(!preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/D',$time))
            throw new InvalidArgumentException('Horario inválido: '.$time.'. Usá HH:MM.');
        list($h,$m)=array_map('intval',explode(':',$time));
        $out[]=sprintf('%02d:%02d',$h,$m);
    }
    $out=array_values(array_unique($out));
    sort($out,SORT_STRING);
    if(!$out) throw new InvalidArgumentException('Ingresá al menos un horario.');
    // HORARIOS es NVARCHAR(200) en las instalaciones existentes.
    if(strlen(implode(',',$out))>200) throw new InvalidArgumentException('Podés seleccionar hasta 33 horarios.');
    return implode(',',$out);
}
function report_next_run(array $r,$from=null){
    $tz=new DateTimeZone($r['ZONA_HORARIA']??'America/Argentina/Buenos_Aires');
    // Copia mutable: aceptar DateTimeImmutable y no modificar el parámetro.
    $now=$from ? new DateTime('@'.$from->getTimestamp()) : new DateTime('now',$tz);
    $now->setTimezone($tz);
    $raw=trim((string)($r['HORARIOS']??''));
    $times=explode(',',report_normalize_times($raw!==''?$raw:'08:00'));
    $freq=strtolower((string)($r['FRECUENCIA']??'daily'));
    $days=array_map('intval',array_filter(explode(',',(string)($r['DIAS_SEMANA']??''))));
    for($i=0;$i<370;$i++){
        $d=(clone $now)->modify('+'.$i.' day');
        $dow=(int)$d->format('N');
        $allowed=true;
        if($freq==='weekdays')$allowed=$dow<=5;
        elseif($freq==='weekly'||$freq==='custom')$allowed=!$days||in_array($dow,$days,true);
        elseif($freq==='monthly')$allowed=((int)$d->format('j')===1);
        if(!$allowed)continue;
        foreach($times as $time){
            list($h,$m)=array_map('intval',explode(':',$time));
            $candidate=(clone $d)->setTime($h,$m,0);
            if($candidate>$now)return $candidate;
        }
    }
    throw new RuntimeException('No se encontró una próxima ejecución válida.');
}
function report_schedule_storage_timezone(){
    static $tz=null;
    if($tz===null){
        $path=dirname(__DIR__).'/config.php';
        $cfg=is_file($path)?require $path:[];
        $tz=new DateTimeZone($cfg['app']['tz']??date_default_timezone_get());
    }
    return $tz;
}
function report_schedule_next_storage(array $r,$from=null){
    // DATETIME2 no guarda zona: persistir en la zona de la aplicación/servidor,
    // la misma utilizada históricamente para SYSDATETIME y las grillas.
    return report_next_run($r,$from)->setTimezone(report_schedule_storage_timezone())->format('Y-m-d H:i:s');
}
function report_schedule_claim($db,$id,$now=null){
    $now=$now?:new DateTime('now',report_schedule_storage_timezone());
    $cutoff=(clone $now)->setTimezone(report_schedule_storage_timezone())->format('Y-m-d H:i:s');
    $rows=$db->all("SELECT *,CONVERT(varchar(33),PROXIMA_EJECUCION,126) AS DUE_KEY,CONVERT(varchar(33),FECHA_MODIFICACION,126) AS VERSION_KEY FROM dbo.CLEAR_REPORT_SCHEDULES WHERE ID=? AND ACTIVO=1 AND (PROXIMA_EJECUCION IS NULL OR PROXIMA_EJECUCION<=CONVERT(datetime2,?,126))",[(int)$id,$cutoff]);
    if(!$rows)return null;
    $row=$rows[0];
    $due=trim((string)($row['DUE_KEY']??''));
    // Si hubo demora, conservar los turnos pendientes: avanzar desde el turno
    // reservado, no desde el final del envío. NULL conserva el envío inicial.
    $from=$due!==''?new DateTime($due,report_schedule_storage_timezone()):$now;
    $next=report_schedule_next_storage($row,$from);
    // UPDATE ... OUTPUT es una única reserva atómica. Un segundo proceso o una
    // edición concurrente cambia la versión/turno y no obtiene ninguna fila.
    $claimed=$db->all("UPDATE dbo.CLEAR_REPORT_SCHEDULES SET PROXIMA_EJECUCION=?,ULTIMO_ESTADO='EN_PROCESO',ULTIMO_ERROR='' OUTPUT INSERTED.ID WHERE ID=? AND ACTIVO=1 AND (PROXIMA_EJECUCION=CONVERT(datetime2,NULLIF(?,''),126) OR (PROXIMA_EJECUCION IS NULL AND ?='')) AND (FECHA_MODIFICACION=CONVERT(datetime2,NULLIF(?,''),126) OR (FECHA_MODIFICACION IS NULL AND ?=''))",[$next,(int)$id,$due,$due,(string)($row['VERSION_KEY']??''),(string)($row['VERSION_KEY']??'')]);
    if(!$claimed)return null;
    $row['RESERVED_NEXT']=$next;
    return $row;
}
