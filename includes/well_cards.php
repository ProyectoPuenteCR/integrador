<?php
/* Cartas XY de lectura: X archivado, Y interpolado en cada timestamp de X.
 * Se presenta una ventana temporal, no un ciclo de bombeo inferido. */
function wh_card_epoch($timestamp) {
    if(!is_string($timestamp)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/D',$timestamp))return null;
    try{return (float)(new DateTimeImmutable($timestamp))->format('U.u');}catch(Throwable $e){return null;}
}
function wh_card_samples($items) {
    $out=[];
    foreach($items as $item){
        $time=wh_card_epoch($item['Timestamp']??null);if($time===null)continue;
        $raw=$item['Value']??null;
        $value=(is_int($raw)||is_float($raw))&&is_finite((float)$raw)&&($item['Good']??false)===true&&empty($item['Questionable'])?(float)$raw:null;
        $key=sprintf('%.6f',$time);
        if(isset($out[$key]))throw new RuntimeException('PI devolvió muestras duplicadas en el mismo instante.');
        $out[$key]=['time'=>$time,'timestamp'=>$item['Timestamp'],'value'=>$value];
    }
    $out=array_values($out);usort($out,function($a,$b){return $a['time']<=>$b['time'];});return $out;
}
function wh_card_pairs($x,$y,$step,$start,$end) {
    $points=[];$cursor=0;
    foreach($x as $sample){
        $time=$sample['time'];if($time<$start||$time>$end)continue;
        // Sin posición numérica no se puede colocar el punto; cortar la curva.
        if($sample['value']===null){$points[]=['x'=>null,'y'=>null,'timestamp'=>$sample['timestamp']];continue;}
        while($cursor+1<count($y)&&$y[$cursor+1]['time']<=$time)$cursor++;
        $left=$y[$cursor]??null;$right=$y[$cursor+1]??null;$value=null;
        if($left&&$left['time']===$time)$value=$left['value'];
        elseif($left&&$left['time']<$time&&$right&&$right['time']>$time&&$left['value']!==null){
            if($step)$value=$left['value'];
            elseif($right['value']!==null)$value=$left['value']+($right['value']-$left['value'])*(($time-$left['time'])/($right['time']-$left['time']));
        }
        $points[]=['x'=>$sample['value'],'y'=>$value,'timestamp'=>$sample['timestamp']];
    }
    return $points;
}
function wh_cards($catalog,$at,$window,$request) {
    if(!is_string($at))throw new InvalidArgumentException('Ingresá una fecha válida para las cartas.');
    $end=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$at,new DateTimeZone('America/Argentina/Buenos_Aires'));
    if(!$end||$end->format('Y-m-d\TH:i')!==$at)throw new InvalidArgumentException('Ingresá una fecha válida para las cartas.');
    if(!is_int($window)||$window<5||$window>300)throw new InvalidArgumentException('La ventana de las cartas debe ser de 5 a 300 segundos.');
    $start=$end->modify('-'.$window.' seconds');$streams=[];$output=[];
    foreach(['superficie'=>['ZT:SUP','WT:SUP'],'fondo'=>['ZT:FONDO','WT:FONDO']] as $kind=>$columns){
        $card=['kind'=>$kind,'points'=>[]];
        try{
            foreach($columns as $column)if(empty($catalog[$column]['available']))throw new RuntimeException('Falta verificar '.$column.' en PI_Points_Stage.');
            foreach($columns as $column){
                $point=$catalog[$column];$id=$point['webid'];
                if(!isset($streams[$id])){
                    $response=$request('/streams/'.rawurlencode($id).'/recorded?startTime='.rawurlencode($start->format(DateTimeInterface::ATOM)).'&endTime='.rawurlencode($end->format(DateTimeInterface::ATOM)).'&boundaryType=Outside&maxCount=2001');
                    if(!isset($response['Items'])||!is_array($response['Items'])||!empty($response['Errors']))throw new RuntimeException('PI no devolvió muestras válidas para '.$column.'.');
                    if(count($response['Items'])>2000||!empty($response['Links']['Next']))throw new RuntimeException('La carta supera 2000 muestras. Reducí la ventana para evitar una curva incompleta.');
                    $streams[$id]=wh_card_samples($response['Items']);
                }
            }
            $card['xTag']=$catalog[$columns[0]]['tag'];$card['yTag']=$catalog[$columns[1]]['tag'];
            $card['xUnit']=$catalog[$columns[0]]['unit'];$card['yUnit']=$catalog[$columns[1]]['unit'];
            $card['points']=wh_card_pairs($streams[$catalog[$columns[0]]['webid']],$streams[$catalog[$columns[1]]['webid']],$catalog[$columns[1]]['step'],(float)$start->format('U.u'),(float)$end->format('U.u'));
            $valid=array_filter($card['points'],function($point){return $point['x']!==null&&$point['y']!==null;});
            if(count($valid)<3)throw new RuntimeException('No hay suficientes pares de posición y carga con calidad válida en esta ventana.');
        }catch(RuntimeException $e){$card['points']=[];$card['error']=$e->getMessage();}
        $output[]=$card;
    }
    return ['start'=>$start->format(DateTimeInterface::ATOM),'end'=>$end->format(DateTimeInterface::ATOM),'windowSeconds'=>$window,'cards'=>$output];
}
