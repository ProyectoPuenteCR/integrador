<?php
// Sólo puntos existentes y unívocos del catálogo local. Nunca construir WebId.
function pcp_catalog($db,$well) {
    $prefix=wh_like($well);
    $rows=$db->all('SELECT TOP (501) [Name],[WebId],[EngineeringUnits],[PointType],[Step] FROM [LC_MDB].[dbo].[PI_Points_Stage] WHERE [Name] LIKE ? OR [Name] LIKE ?',[$prefix.'%','"'.$prefix.'%']);
    if($db->error())throw new RuntimeException('No se pudo leer el catálogo de puntos PI.');
    if(count($rows)>500)throw new RuntimeException('El catálogo supera el límite de búsqueda.');
    $output=[];
    foreach(['YT:POZO','PT:FONDO','YAT:COM','__COMUNICACION'] as $column){
        $source=$column==='__COMUNICACION'?'YAT:COM':$column;
        $pattern='~^'.preg_quote($well,'~').'\((?:PC12|PC14|PCP|RPC)\)_'.preg_quote($source,'~').'(?:\.PV)?$~iD';
        $matches=[];
        foreach($rows as $row){
            $name=wh_clean($row['Name']??'');$webid=wh_clean($row['WebId']??'');
            if(!preg_match($pattern,$name)||!preg_match('/^[A-Za-z0-9_-]{20,300}$/D',$webid))continue;
            $matches[$webid]=['tag'=>$name,'webid'=>$webid,'unit'=>wh_clean($row['EngineeringUnits']??''),'type'=>wh_clean($row['PointType']??''),'step'=>in_array(strtolower(wh_clean($row['Step']??'')),['1','true'],true),'available'=>true];
        }
        $output[$column]=count($matches)===1?array_values($matches)[0]:['available'=>false,'reason'=>count($matches)>1?'Hay más de un tag posible; verificar correspondencia PCP.':'Sin tag PCP verificado en el catálogo PI.'];
    }
    return $output;
}
