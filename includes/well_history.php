<?php
/* Lectura del catálogo existente. No crea ni modifica objetos SQL. */
function wh_clean($value) { return trim(trim((string)$value), "\" \t\r\n"); }
function wh_columns() {
    return ['ZT:SUP','WT:SUP','ZT:FONDO','WT:FONDO','ESTADO','Fecha','ECO','ESTADO-GRAL','PT:LINEA','YAT:COM','__COMUNICACION',
        'ET:VARIADOR','FP','FT:AGUA_ACU','FT:AGUA_CIERRE','FT:FONDO-LLENADO.PV',
        'FT:LLENADO.SP','FT:LLENADO-ACTUAL','FT:OIL_ACU','FT:OIL_CIERRE','FT:PROD_ACU',
        'FT:PROD-PROY','FT:PROY-AGUA','FT:PROY-OIL','WT:SUP_MAX','WT:SPMIN','YT:CONTROL',
        'YT:DEVICE','YT:LLAVE','YT:LLAVE-AUTO','ZT:CARRERA-MIN','ZT:CARRERA-ACTUAL',
        'ZT:FONDO-POS','ZT:PUMPOFF','ZT:SUP-CARRERA','PT:CABEZA','QT:RPM','ESTADO_RPM',
        'AF-ESTADO-PARO-REMOTO','PRODUCCION_PETROLEO','PRODUCCION_LIQUIDO','PRODUCCION_GAS'];
}
function wh_well($value) {
    if(!is_string($value))throw new InvalidArgumentException('El pozo no es válido.');
    $well=trim((string)$value);
    if($well===''||strlen($well)>120||!preg_match('/^[\pL\pN(). _:-]+$/u',$well))throw new InvalidArgumentException('El pozo no es válido.');
    return $well;
}
function wh_like($value) { return strtr($value,['['=>'[[]','%'=>'[%]','_'=>'[_]']); }
function wh_catalog($db,$well) {
    $prefix=wh_like($well.'(RPC)_');
    $rows=$db->all('SELECT TOP (501) [Name],[WebId],[EngineeringUnits],[PointType],[Step] FROM [LC_MDB].[dbo].[PI_Points_Stage] WHERE [Name] LIKE ? OR [Name] LIKE ?',[$prefix.'%','"'.$prefix.'%']);
    if($db->error())throw new RuntimeException('No se pudo leer LC_MDB.dbo.PI_Points_Stage.');
    if(count($rows)>500)throw new RuntimeException('El catálogo del pozo supera el límite de búsqueda.');
    $byName=[];
    foreach($rows as $row){
        $name=strtoupper(wh_clean($row['Name']??''));
        $webid=wh_clean($row['WebId']??'');
        if(!preg_match('/^[A-Za-z0-9_-]{20,300}$/D',$webid))continue;
        $byName[$name][$webid]=['tag'=>wh_clean($row['Name']),'webid'=>$webid,
            'unit'=>wh_clean($row['EngineeringUnits']??''),'type'=>wh_clean($row['PointType']??''),
            'step'=>in_array(strtolower(wh_clean($row['Step']??'')),['true','1'],true)];
    }
    $output=[];
    foreach(wh_columns() as $column){
        $source=$column==='__COMUNICACION'?'YAT:COM':$column;
        $stem=strtoupper($well.'(RPC)_'.$source);
        $names=[$stem];if(!preg_match('/\.(PV|SP|CAL|ANL)$/i',$source))$names[]=$stem.'.PV';
        $matches=[];foreach($names as $name)foreach($byName[$name]??[] as $id=>$point)$matches[$id]=$point;
        if(count($matches)===1)$output[$column]=array_values($matches)[0]+['available'=>true];
        else $output[$column]=['available'=>false,'reason'=>count($matches)>1?'Hay más de un tag posible; falta verificar la correspondencia.':'Sin tag verificado en PI_Points_Stage.'];
    }
    // Misma regla de ESTADO_RPM que usa telemetry_grid.php, sobre el RPM horario.
    if(!empty($output['QT:RPM']['available']))$output['ESTADO_RPM']=array_merge($output['QT:RPM'],[
        'type'=>'Digital','unit'=>'','step'=>true,'derived'=>'rpm_state',
        'derivation_label'=>'Estado calculado a partir de RPM en cada muestra.'
    ]);
    return $output;
}
function wh_range($from,$to) {
    $tz=new DateTimeZone('America/Argentina/Buenos_Aires');$dates=[];
    foreach([$from,$to] as $value){
        if(!is_string($value))throw new InvalidArgumentException('Ingresá fechas desde y hasta válidas.');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)$value,$tz);
        if(!$date||$date->format('Y-m-d\TH:i')!==$value)throw new InvalidArgumentException('Ingresá fechas desde y hasta válidas.');
        $dates[]=$date;
    }
    $seconds=$dates[1]->getTimestamp()-$dates[0]->getTimestamp();
    if($seconds<=0||$seconds>31*86400)throw new InvalidArgumentException('El rango debe ser mayor a cero y de hasta 31 días.');
    return [$dates[0]->format(DateTimeInterface::ATOM),$dates[1]->format(DateTimeInterface::ATOM)];
}
function wh_interval($value) {
    if(!(is_int($value)||(is_string($value)&&ctype_digit($value)))||(int)$value<30||(int)$value>86400)
        throw new InvalidArgumentException('La frecuencia debe ser un número entero entre 30 y 86400 segundos.');
    return (int)$value;
}
function wh_history($catalog,$columns,$from,$to,$request,$intervalSeconds=3600) {
    if(!is_array($columns)||!$columns||count($columns)>12)throw new InvalidArgumentException('Seleccioná entre 1 y 12 variables.');
    foreach($columns as $column)if(!is_string($column)||empty($catalog[$column]['available']))throw new InvalidArgumentException('Una variable seleccionada no tiene un tag verificado.');
    $columns=array_values(array_unique($columns));
    [$start,$end]=wh_range($from,$to);
    $intervalSeconds=wh_interval($intervalSeconds);
    $seconds=strtotime($end)-strtotime($start);
    $points=(int)floor($seconds/$intervalSeconds)+1;
    if($points>10000||$points*count($columns)>60000)
        throw new InvalidArgumentException('La consulta supera el límite de muestras. Aumentá la frecuencia en segundos o reducí el período o la cantidad de variables.');
    $ids=[];foreach($columns as $column)$ids[$catalog[$column]['webid']]=true;
    $query='startTime='.rawurlencode($start).'&endTime='.rawurlencode($end).'&interval='.$intervalSeconds.'s';
    foreach(array_keys($ids) as $id)$query.='&webId='.rawurlencode($id);
    $response=$request('/streamsets/interpolated?'.$query);
    if(!isset($response['Items'])||!is_array($response['Items']))throw new RuntimeException('PI Web API no devolvió las series solicitadas.');
    $streams=[];foreach($response['Items'] as $stream)if(isset($stream['WebId']))$streams[$stream['WebId']]=$stream;
    $series=[];
    foreach($columns as $column){
        $point=$catalog[$column];$stream=$streams[$point['webid']]??[];
        $entry=['column'=>$column,'tag'=>$point['tag'],'unit'=>!empty($point['derived'])?'':(wh_clean($stream['UnitsAbbreviation']??'')?:$point['unit']),
            'type'=>$point['type'],'step'=>$point['step'],'samples'=>[]];
        if(!empty($point['derivation_label']))$entry['derivation_label']=$point['derivation_label'];
        if(!isset($stream['Items'])||!is_array($stream['Items'])||!empty($stream['Errors'])){$entry['error']='PI no devolvió datos para esta variable.';$series[]=$entry;continue;}
        if(count($stream['Items'])>min(10000,$points+2))throw new RuntimeException('PI devolvió más muestras que el rango permitido.');
        foreach($stream['Items'] as $sample){
            $timestamp=(string)($sample['Timestamp']??'');$epoch=strtotime($timestamp);
            if($epoch===false||$epoch<strtotime($start)||$epoch>strtotime($end))continue;
            $raw=$sample['Value']??null;$value=is_array($raw)?($raw['Name']??null):$raw;
            if(($point['derived']??'')==='rpm_state')$value=is_numeric($raw)?((float)$raw===0.0?'PARADO':((float)$raw>0?'EN MARCHA':'SIN DATO')):'SIN DATO';
            if(!is_scalar($value)&&$value!==null)$value=null;
            // Conservar el estado textual y el código numérico PI por separado.
            $plot=is_numeric($value)?(float)$value:null;
            if(is_array($raw)&&isset($raw['Value'])&&is_numeric($raw['Value']))$plot=(float)$raw['Value'];
            if(($point['derived']??'')==='rpm_state')$plot=is_numeric($raw)&&$raw>=0?((float)$raw===0.0?0:1):null;
            $entry['samples'][]=['timestamp'=>$timestamp,'value'=>$value,'plot_value'=>$plot,'good'=>($sample['Good']??false)===true,
                'questionable'=>!empty($sample['Questionable']),'substituted'=>!empty($sample['Substituted'])];
        }
        if(!$entry['samples'])$entry['error']='Sin muestras en el rango seleccionado.';
        $series[]=$entry;
    }
    return ['start'=>$start,'end'=>$end,'interval'=>$intervalSeconds.'s','intervalSeconds'=>$intervalSeconds,'timezone'=>'America/Argentina/Buenos_Aires','series'=>$series];
}
