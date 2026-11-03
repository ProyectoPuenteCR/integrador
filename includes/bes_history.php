<?php
// Sólo se exponen variables BES y puntos inequívocos del pozo seleccionado.
function bes_columns() {
    return ['ESTADO','YT:SISTEMA','YAT:COM','__COMUNICACION','VT-Z','N-FP:MOTOR','PID:PT-FONDO','N-IT:LC','N-FQ:MOTOR','N-IT:DH','N-ET:OUT','N-ET:MOTOR','PAL:INTAKE','PT:CABEZA','PT:CONSUMO','PT:FONDO','PT:LINEA','RUN:BQ','TT:FONDO','TT:MOTOR','AF:RUN:BQ','YT:MOD-CONTROL','N-IT:MOTOR','N-IT:MOTOR-AVG','N-IT:MOTOR-MARCHA','N-IT:MOTOR-RATIO'];
}
function bes_catalog($db,$well) {
    $prefix=wh_like($well);
    $rows=$db->all('SELECT TOP (501) [Name],[WebId],[EngineeringUnits],[PointType],[Step] FROM [LC_MDB].[dbo].[PI_Points_Stage] WHERE [Name] LIKE ? OR [Name] LIKE ?',[$prefix.'%','"'.$prefix.'%']);
    if($db->error())throw new RuntimeException('No se pudo leer el catálogo de puntos PI.');
    if(count($rows)>500)throw new RuntimeException('El catálogo supera el límite de búsqueda.');
    $output=[];
    foreach(bes_columns() as $column){
        $source=$column==='__COMUNICACION'?'YAT:COM':$column;
        // El delimitador exige el nombre completo del pozo: CG-21 no acepta CG-212.
        $pattern='~^'.preg_quote($well,'~').'(?:\([^()]+\))?_'.preg_quote($source,'~').'(?:\.PV)?$~iD';
        $matches=[];
        foreach($rows as $row){
            $name=wh_clean($row['Name']??'');$id=wh_clean($row['WebId']??'');
            if(!preg_match($pattern,$name)||!preg_match('/^[A-Za-z0-9_-]{20,300}$/D',$id))continue;
            $matches[$id]=['available'=>true,'tag'=>$name,'webid'=>$id,'unit'=>wh_clean($row['EngineeringUnits']??''),'type'=>wh_clean($row['PointType']??''),'step'=>in_array(strtolower(wh_clean($row['Step']??'')),['true','1'],true)];
        }
        $output[$column]=count($matches)===1?array_values($matches)[0]:['available'=>false,'reason'=>count($matches)>1?'Más de un tag posible; verificar correspondencia BES.':'Sin tag verificado en el catálogo PI.'];
    }
    return $output;
}
function bes_snapshot($catalog,$request) {
    $query=[];foreach($catalog as $point)if(!empty($point['available']))$query[$point['webid']]='webId='.rawurlencode($point['webid']);
    if(!$query)throw new RuntimeException('No se encontraron puntos BES verificados en PI_Points_Stage.');
    $response=$request('/streamsets/value?'.implode('&',$query));
    if(!isset($response['Items'])||!is_array($response['Items']))throw new RuntimeException('PI no devolvió valores actuales.');
    $streams=[];foreach($response['Items'] as $item)if(isset($item['WebId']))$streams[$item['WebId']]=$item;
    $out=[];
    foreach($catalog as $column=>$point){
        $item=!empty($point['available'])?($streams[$point['webid']]??[]):[];
        $sample=$item['Value']??[];$raw=$sample['Value']??null;
        $value=is_array($raw)?($raw['Name']??null):$raw;
        $out[$column]=['tag'=>$point['tag']??'','unit'=>$point['unit']??'','timestamp'=>$sample['Timestamp']??null,'value'=>is_scalar($value)?$value:null,'good'=>($sample['Good']??false)===true&&empty($sample['Questionable'])&&empty($item['Errors'])&&is_scalar($value)];
    }
    return $out;
}
