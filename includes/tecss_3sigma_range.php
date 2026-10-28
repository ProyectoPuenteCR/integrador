<?php
function sigma_history_dates($from,$to)
{
    $dates=[];$dayOnly=null;
    foreach([$from,$to] as $value){
        $isDay=is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value);
        $format=$isDay?'Y-m-d':'Y-m-d\TH:i';
        if(!$isDay&&(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$value)))throw new InvalidArgumentException('Rango de fechas inválido.');
        if($dayOnly!==null&&$dayOnly!==(bool)$isDay)throw new InvalidArgumentException('Usá el mismo formato para Desde y Hasta.');
        $dayOnly=(bool)$isDay;
        $date=DateTimeImmutable::createFromFormat('!'.$format,$value,new DateTimeZone('America/Argentina/Buenos_Aires'));
        if(!$date||$date->format($format)!==$value)throw new InvalidArgumentException('Rango de fechas inválido.');
        $dates[]=$date;
    }
    if($dates[0]>$dates[1]||(!$dayOnly&&$dates[0]==$dates[1]))throw new InvalidArgumentException('La fecha Desde debe ser anterior a Hasta.');
    if((float)$dates[1]->format('U')-(float)$dates[0]->format('U')>366*86400)throw new InvalidArgumentException('El rango máximo permitido es de 366 días.');
    // Keep legacy day-only requests inclusive; new datetime requests end exactly at Hasta.
    if($dayOnly)$dates[1]=$dates[1]->modify('+1 day');
    return $dates;
}
