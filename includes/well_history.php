<?php
/* Lectura del catálogo existente. No crea ni modifica objetos SQL. */
function wh_clean($value) { return trim(trim((string)$value), "\" \t\r\n"); }
function wh_source($value='monitoreo_pozos') {
    if(!is_string($value)||!in_array($value,['monitoreo_pozos','telemetria_pcp'],true))
        throw new InvalidArgumentException('La pantalla de origen no es válida.');
    return $value;
}
function wh_can_history($source) {
    return permissions_can_menu(wh_source($source))&&permissions_can_menu('pi_historico');
}
function wh_can_activity($source) {
    return permissions_can_menu(wh_source($source))&&(permissions_can_menu('pozos_alarmas24')||permissions_can_menu('pozos_todas_alarmas'));
}
function wh_cards_allowed($source) { return wh_source($source)==='monitoreo_pozos'; }
function wh_columns($source='monitoreo_pozos') {
    if(wh_source($source)==='telemetria_pcp')return ['YT:POZO','PT:FONDO','YAT:COM','__COMUNICACION'];
    return ['TIME:SUP','TIME:FONDO','ZT:SUP-POS','WT:SUP-LOAD','ZT:FONDO-POS','WT:FONDO-LOAD','ESTADO','Fecha','ECO','ESTADO-GRAL','PT:LINEA','YAT:COM','__COMUNICACION',
        'ET:VARIADOR','FP','FT:AGUA_ACU','FT:AGUA_CIERRE','FT:FONDO-LLENADO.PV',
        'FT:LLENADO.SP','FT:LLENADO-ACTUAL','FT:OIL_ACU','FT:OIL_CIERRE','FT:PROD_ACU',
        'FT:PROD-PROY','FT:PROY-AGUA','FT:PROY-OIL','WT:SUP_MAX','WT:SPMIN','YT:CONTROL',
        'YT:DEVICE','YT:LLAVE','YT:LLAVE-AUTO','ZT:CARRERA-MIN','ZT:CARRERA-ACTUAL',
        'ZT:PUMPOFF','ZT:SUP-CARRERA','PT:CABEZA','QT:RPM','ESTADO_RPM',
        'AF-ESTADO-PARO-REMOTO','PRODUCCION_PETROLEO','PRODUCCION_LIQUIDO','PRODUCCION_GAS'];
}
function wh_well($value) {
    if(!is_string($value))throw new InvalidArgumentException('El pozo no es válido.');
    $well=trim((string)$value);
    if($well===''||strlen($well)>120||!preg_match('/^[\pL\pN(). _:-]+$/u',$well))throw new InvalidArgumentException('El pozo no es válido.');
    return $well;
}
function wh_like($value) { return strtr($value,['['=>'[[]','%'=>'[%]','_'=>'[_]']); }
function wh_catalog($db,$well,$source='monitoreo_pozos') {
    $source=wh_source($source);
    /*
     * PCP: candidatos estrictos, no una convención confirmada de producción.
     * PC12/PC14 son las plantillas de la grilla; PCP y el nombre directo se
     * verifican únicamente contra filas reales del catálogo. No se fabrican
     * WebId ni se reutiliza la familia RPC de bombeo mecánico.
     */
    $prefixes=$source==='telemetria_pcp'
        ?[$well.'(PC12)_',$well.'(PC14)_',$well.'(PCP)_',$well.'_']
        :[$well.'(RPC)_'];
    $conditions=[];$params=[];
    foreach($prefixes as $prefix){
        $conditions[]='[Name] LIKE ?';$params[]=wh_like($prefix).'%';
        $conditions[]='[Name] LIKE ?';$params[]='"'.wh_like($prefix).'%';
    }
    $rows=$db->all('SELECT TOP (501) [Name],[WebId],[EngineeringUnits],[PointType],[Step] FROM [LC_MDB].[dbo].[PI_Points_Stage] WHERE '.implode(' OR ',$conditions),$params);
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
    foreach(wh_columns($source) as $column){
        $attribute=$column==='__COMUNICACION'?'YAT:COM':$column;
        $names=[];
        foreach($prefixes as $prefix){
            $stem=strtoupper($prefix.$attribute);$names[]=$stem;
            if(!preg_match('/\.(PV|SP|CAL|ANL)$/i',$attribute))$names[]=$stem.'.PV';
        }
        $matches=[];foreach($names as $name)foreach($byName[$name]??[] as $id=>$point)$matches[$id]=$point;
        if(count($matches)===1)$output[$column]=array_values($matches)[0]+['available'=>true];
        else $output[$column]=['available'=>false,'reason'=>count($matches)>1?'Hay más de un tag posible; falta verificar la correspondencia.':($source==='telemetria_pcp'?'Sin correspondencia PCP verificada en PI_Points_Stage.':'Sin tag verificado en PI_Points_Stage.')];
    }
    // Misma regla de ESTADO_RPM que usa telemetry_grid.php, sobre el RPM horario.
    if($source==='monitoreo_pozos'&&!empty($output['QT:RPM']['available']))$output['ESTADO_RPM']=array_merge($output['QT:RPM'],[
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
