<?php
function wz_key($well) {
    $key=strtoupper(preg_replace('/\s+/u','',trim((string)$well)));
    return strpos($key,'YPF.SC.')===0?substr($key,7):$key;
}
function wz_production($db,$well) {
    $key=wz_key($well);
    // Consulta sólo la caché instalada. No llama a la API ni modifica SQL.
    $rows=$db->all("SELECT TOP (20) PozoId,Pozo,DiaOperativo,
        CONVERT(varchar(64),Petroleo24) AS Petroleo24,
        CONVERT(varchar(64),Agua24) AS Agua24,
        CONVERT(varchar(64),Liquido24) AS Liquido24,
        CONVERT(varchar(64),Gas24) AS Gas24,FechaCarga
        FROM dbo.CLEAR_API_Q412_CACHE
        WHERE UPPER(REPLACE(LTRIM(RTRIM(Pozo)),' ','')) IN (?,?)
        ORDER BY DiaOperativo DESC,FechaCarga DESC",[$key,'YPF.SC.'.$key]);
    if($db->error())throw new RuntimeException('No se pudo consultar la caché Zafiro Q412.');
    if(count($rows)>=20)throw new RuntimeException('Demasiadas coincidencias Zafiro; verificar el identificador del pozo.');
    $matches=[];$identities=[];
    foreach($rows as $row){
        if(wz_key($row['Pozo']??'')!==$key)continue;
        $id=trim((string)($row['PozoId']??''));
        $identities[$id!==''?'id:'.$id:'name:'.strtoupper(trim($row['Pozo']))]=true;
        $matches[]=$row;
    }
    if(count($identities)>1)throw new RuntimeException('La correspondencia Zafiro es ambigua. Verificar PozoId antes de mostrar producción.');
    if(!$matches)return ['found'=>false,'source'=>'Zafiro Q412','well'=>$well];
    $row=$matches[0];$values=[];
    foreach(['Petroleo24','Agua24','Liquido24','Gas24'] as $column){
        $raw=$row[$column]??null;
        $values[$column]=$raw!==null&&$raw!==''&&is_numeric($raw)?(float)$raw:null;
    }
    return ['found'=>true,'source'=>'Zafiro Q412','well'=>$well,'matchedWell'=>$row['Pozo'],'pozoId'=>$row['PozoId'],'operatingDay'=>$row['DiaOperativo'],'loadedAt'=>$row['FechaCarga'],'values'=>$values];
}
