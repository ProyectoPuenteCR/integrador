<?php
/* Caché de cartas. La página sólo lee SQL; PI se consulta exclusivamente desde CLI. */
require_once __DIR__.'/well_cards.php';
require_once __DIR__.'/well_history.php';

function cd_tz() { return new DateTimeZone('America/Argentina/Buenos_Aires'); }
function cd_cutoff(DateTimeImmutable $now) {
    $now=$now->setTimezone(cd_tz()); $cut=$now->setTime(6,0,0);
    return $now<$cut?$cut->modify('-1 day'):$cut;
}
function cd_sql_date(DateTimeInterface $date) {
    return (new DateTimeImmutable($date->format(DateTimeInterface::ATOM)))->setTimezone(cd_tz())->format('Y-m-d H:i:s');
}
function cd_query($db,$sql,$params=[]) {
    $rows=$db->all($sql,$params);
    if($db->error())throw new RuntimeException('No se pudo leer la información de cartas en SQL.');
    return $rows;
}
function cd_execute($db,$sql,$params=[]) {
    if(!$db->execute($sql,$params))throw new RuntimeException('No se pudo guardar la carga de cartas en SQL.');
}
function cd_status($card,DateTimeImmutable $cut) {
    if(empty($card['reference'])||empty($card['points']))return 'Sin datos';
    $epoch=wh_card_epoch($card['reference']);
    if($epoch===null||$epoch>$cut->getTimestamp())return 'Referencia inválida';
    return $cut->getTimestamp()-$epoch>86400?'Sin datos en las últimas 24 h':'Con datos';
}
function cd_has_card($card) {
    if(!is_array($card)||!isset($card['points'])||!is_array($card['points'])||wh_card_epoch($card['reference']??null)===null)return false;
    $valid=0;
    foreach($card['points'] as $p)if(isset($p['x'],$p['y'])&&is_numeric($p['x'])&&is_numeric($p['y'])&&is_finite((float)$p['x'])&&is_finite((float)$p['y']))$valid++;
    return $valid>=3;
}
function cd_roster($rows) {
    $wells=[];
    foreach($rows as $row){
        $well=trim((string)$row['POZO']);if($well==='')continue;
        wh_well($well);$key=strtoupper($well);
        $battery=trim((string)($row['BATERIA']??''));
        if(!isset($wells[$key]))$wells[$key]=['well'=>$well,'batteries'=>[]];
        if($battery!=='')$wells[$key]['batteries'][strtoupper($battery)]=$battery;
    }
    $out=[];
    foreach($wells as $well){
        $count=count($well['batteries']);
        $out[]=['well'=>$well['well'],'battery'=>$count===1?reset($well['batteries']):($count?'BATERÍA POR VERIFICAR':'SIN BATERÍA ASIGNADA'),
            'warning'=>$count>1?'El catálogo asigna más de una batería al pozo.':''];
    }
    usort($out,function($a,$b){return strnatcasecmp($a['battery'],$b['battery'])?:strnatcasecmp($a['well'],$b['well']);});return $out;
}
/* Se revisan las últimas 64 actualizaciones TIME y hasta 8 referencias distintas.
 * Búsqueda inversa acotada: nunca descargar el histórico XY completo de 300 pozos.
 * Si se agota el límite, se informa la limitación y se conserva la carta anterior. */
function cd_collect_kind($catalog,$kind,DateTimeImmutable $cut,$request,$previous=null,$window=60) {
    $timeColumn=$kind==='superficie'?'TIME:SUP':'TIME:FONDO';
    $cols=$kind==='superficie'?['ZT:SUP-POS','WT:SUP-LOAD']:['ZT:FONDO-POS','WT:FONDO-LOAD'];
    $fallback=cd_has_card($previous)?$previous:['kind'=>$kind,'points'=>[]];
    try{
        foreach(array_merge([$timeColumn],$cols) as $col)if(empty($catalog[$col]['available']))throw new RuntimeException('Falta tag verificado: '.$col.'.');
        $path='/streams/'.rawurlencode($catalog[$timeColumn]['webid']).'/recorded?startTime='.rawurlencode($cut->format(DateTimeInterface::ATOM)).'&endTime=1970-01-01T00%3A00%3A00Z&boundaryType=Inside&maxCount=65';
        $response=$request($path);
        if(!isset($response['Items'])||!is_array($response['Items'])||!empty($response['Errors']))throw new RuntimeException('PI no devolvió las referencias de cartas.');
        $refs=[];$bad=false;
        foreach(array_slice($response['Items'],0,64) as $sample){
            if(!empty($sample['Errors'])){$bad=true;continue;}
            $sampleTime=wh_card_epoch($sample['Timestamp']??null);
            if($sampleTime===null||$sampleTime>$cut->getTimestamp()){$bad=true;continue;}
            try{$reference=wh_card_reference($sample);}catch(RuntimeException $e){$bad=true;continue;}
            // La ventana debe estar completa al corte; no usar puntos posteriores a las 06:00.
            if($reference->getTimestamp()+(int)ceil($window/2)>$cut->getTimestamp())continue;
            $refs[$reference->getTimestamp()]=['date'=>$reference,'sample'=>$sample];
        }
        krsort($refs,SORT_NUMERIC);$attempts=0;$lastError='';
        foreach($refs as $entry){
            $reference=$entry['date'];
            if(cd_has_card($previous)&&wh_card_epoch($previous['reference'])>=$reference->getTimestamp())return ['card'=>$previous,'warning'=>''];
            if(++$attempts>8)break;
            $sub=[];foreach(array_merge([$timeColumn],$cols) as $col)$sub[$col]=$catalog[$col];
            $wrapped=function($path)use($request,$entry){return substr($path,-6)==='/value'?$entry['sample']:$request($path);};
            $result=wh_cards($sub,$cut->format('Y-m-d\TH:i'),$window,$wrapped,true);
            $candidate=$result['cards'][$kind==='superficie'?0:1];
            if(cd_has_card($candidate))return ['card'=>$candidate,'warning'=>''];
            $lastError=$candidate['error']??'Sin pares válidos en la referencia.';
        }
        $limited=count($response['Items'])>=65||!empty($response['Links']['Next'])||$attempts>8;
        $warning=$limited?'Búsqueda limitada: no se encontró una carta válida entre las últimas referencias.':($lastError?:($bad?'Referencias PI sin calidad válida.':''));
        if(cd_has_card($previous))$warning=$warning?:'Se conserva la última carta válida de una carga anterior.';
        return ['card'=>$fallback,'warning'=>$warning];
    }catch(RuntimeException $e){return ['card'=>$fallback,'warning'=>$e->getMessage()];}
}
function cd_escape($value) { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function cd_svg($card) {
    if(!cd_has_card($card))return '<div class="cd-empty">Sin carta válida para mostrar</div>';
    $pts=$card['points'];$xs=[];$ys=[];
    foreach($pts as $p)if(isset($p['x'],$p['y'])&&is_numeric($p['x'])&&is_numeric($p['y'])){$xs[]=(float)$p['x'];$ys[]=(float)$p['y'];}
    $xmin=min($xs);$xmax=max($xs);$ymin=min($ys);$ymax=max($ys);
    $dx=max(1,$xmax-$xmin)*.06;$dy=max(1,$ymax-$ymin)*.08;$xmin-=$dx;$xmax+=$dx;$ymin-=$dy;$ymax+=$dy;
    $x=function($v)use($xmin,$xmax){return 66+($v-$xmin)/($xmax-$xmin)*428;};
    $y=function($v)use($ymin,$ymax){return 190-($v-$ymin)/($ymax-$ymin)*178;};
    $out='<svg viewBox="0 0 510 239" role="img" aria-label="'.cd_escape('Carta de '.$card['kind']).'"><title>'.cd_escape('Carta de '.$card['kind']).'</title>';
    for($i=0;$i<=4;$i++){
        $xx=66+$i*107;$yy=190-$i*44.5;
        $out.='<path class="cd-grid" d="M'.$xx.' 12V190 M66 '.$yy.'H494"/><text x="'.$xx.'" y="207" text-anchor="middle">'.cd_escape(number_format($xmin+($xmax-$xmin)*$i/4,0,',','.')).'</text><text x="60" y="'.($yy+4).'" text-anchor="end">'.cd_escape(number_format($ymin+($ymax-$ymin)*$i/4,0,',','.')).'</text>';
    }
    $d='';$new=true;
    foreach($pts as $p){if(!isset($p['x'],$p['y'])||!is_numeric($p['x'])||!is_numeric($p['y'])){$new=true;continue;}$d.=($new?'M':'L').sprintf('%.2F %.2F ',$x($p['x']),$y($p['y']));$new=false;}
    $out.='<path class="cd-curve '.($card['kind']==='fondo'?'cd-bottom':'').'" d="'.$d.'"/><text x="280" y="232" text-anchor="middle">'.cd_escape('Posición ('.($card['xUnit']??'').')').'</text><text transform="translate(15 105) rotate(-90)" text-anchor="middle">'.cd_escape('Carga ('.($card['yUnit']??'').')').'</text></svg>';
    return $out;
}
