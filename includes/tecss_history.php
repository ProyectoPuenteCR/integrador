<?php
function tecss_columns() {
    return ['ESTADO','YAT:COM','__COMUNICACION','PI-005-PL','VIBRACION','SI-002-SPM','QT:GOLPES-MIN',
        'TIME:SUP','TIME:FONDO','ZT:SUP-POS','WT:SUP-LOAD','ZT:FONDO-POS','WT:FONDO-LOAD'];
}
function tecss_catalog($db,$well) {
    $prefix=wh_like($well);
    $rows=$db->all('SELECT TOP (501) [Name],[WebId],[EngineeringUnits],[PointType],[Step] FROM [LC_MDB].[dbo].[PI_Points_Stage] WHERE [Name] LIKE ? OR [Name] LIKE ?',[$prefix.'%','"'.$prefix.'%']);
    if($db->error())throw new RuntimeException('No se pudo leer el catálogo de puntos PI.');
    if(count($rows)>500)throw new RuntimeException('El catálogo supera el límite de búsqueda.');
    $out=[];
    foreach(tecss_columns() as $column){
        $sources=$column==='__COMUNICACION'?['YAT:COM']:($column==='VIBRACION'?['VIBRACION','VI-001-V']:[$column]);
        $matches=[];
        foreach($rows as $row){
            $name=wh_clean($row['Name']??'');$id=wh_clean($row['WebId']??'');
            if(!preg_match('/^[A-Za-z0-9_-]{20,300}$/D',$id))continue;
            foreach($sources as $source){
                $pattern='~^('.preg_quote($well,'~').'(?:\([^()]+\))?)_'.preg_quote($source,'~').'(?:\.PV)?$~iD';
                if(!preg_match($pattern,$name,$parts))continue;
                $matches[$id]=['available'=>true,'tag'=>$name,'webid'=>$id,'family'=>strtoupper($parts[1]),
                    'unit'=>wh_clean($row['EngineeringUnits']??''),'type'=>wh_clean($row['PointType']??''),
                    'step'=>in_array(strtolower(wh_clean($row['Step']??'')),['true','1'],true)];
            }
        }
        $out[$column]=count($matches)===1?array_values($matches)[0]:['available'=>false,'reason'=>count($matches)>1?'Más de un tag posible; verificar correspondencia TECSS.':'Sin tag verificado para este pozo.'];
    }
    return $out;
}
function tecss_card_availability($catalog) {
    $out=[];
    foreach(['superficie'=>['ZT:SUP-POS','WT:SUP-LOAD','TIME:SUP'],'fondo'=>['ZT:FONDO-POS','WT:FONDO-LOAD','TIME:FONDO']] as $kind=>$columns){
        $x=$catalog[$columns[0]]??[];$y=$catalog[$columns[1]]??[];$time=$catalog[$columns[2]]??[];
        $available=!empty($x['available'])&&!empty($y['available'])&&!empty($x['family'])&&$x['family']===($y['family']??null);
        foreach([$x,$y] as $point)if(in_array(strtolower($point['type']??''),['string','digital','timestamp','blob'],true))$available=false;
        $out[]=['kind'=>$kind,'available'=>$available,'latest'=>$available&&!empty($time['available'])&&($time['family']??null)===$x['family']];
    }
    return $out;
}
function tecss_cards($catalog,$input,$request) {
    $availability=tecss_card_availability($catalog);$allowed=[];
    foreach($availability as $card){
        if($card['available'])$allowed[]=$card['kind'];
        $suffix=$card['kind']==='superficie'?'SUP':'FONDO';
        if(!$card['available']||!$card['latest'])$catalog['TIME:'.$suffix]=['available'=>false];
        if(!$card['available'])$catalog['ZT:'.$suffix.'-POS']=['available'=>false];
    }
    if(!$allowed)throw new InvalidArgumentException('Este pozo no tiene pares de posición y carga verificados para consultar cartas.');
    $result=wh_cards($catalog,$input['at']??'',$input['windowSeconds']??60,$request,($input['mode']??'manual')==='latest');
    $result['cards']=array_values(array_filter($result['cards'],function($card)use($allowed){return in_array($card['kind'],$allowed,true);}));
    return $result;
}
function tecss_snapshot($catalog,$request) {
    $query=[];
    foreach(['ESTADO','YAT:COM','__COMUNICACION','PI-005-PL','VIBRACION','SI-002-SPM','QT:GOLPES-MIN'] as $column){
        $point=$catalog[$column]??[];
        if(!empty($point['available']))$query[$point['webid']]='webId='.rawurlencode($point['webid']);
    }
    if(!$query)throw new RuntimeException('No se encontraron variables TECSS verificadas en el catálogo PI.');
    $response=$request('/streamsets/value?'.implode('&',$query));
    if(!isset($response['Items'])||!is_array($response['Items']))throw new RuntimeException('PI no devolvió valores actuales.');
    $streams=[];foreach($response['Items'] as $item)if(isset($item['WebId']))$streams[$item['WebId']]=$item;
    $out=[];
    foreach(['ESTADO','YAT:COM','__COMUNICACION','PI-005-PL','VIBRACION','SI-002-SPM','QT:GOLPES-MIN'] as $column){
        $point=$catalog[$column]??[];$item=!empty($point['available'])?($streams[$point['webid']]??[]):[];
        $sample=$item['Value']??[];$raw=$sample['Value']??null;$value=is_array($raw)?($raw['Name']??null):$raw;
        $out[$column]=['tag'=>$point['tag']??'','unit'=>$point['unit']??'','timestamp'=>$sample['Timestamp']??null,
            'value'=>is_scalar($value)?$value:null,'good'=>($sample['Good']??false)===true&&empty($sample['Questionable'])&&empty($item['Errors'])&&is_scalar($value)];
    }
    return $out;
}
