<?php
// Lecturas acotadas del historial existente, con coincidencia exacta del pozo.
function wa_alarms($db, $well, $since, $until) {
    $metadata=$db->all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS'");
    if($db->error())throw new RuntimeException('No se pudo consultar el historial de alarmas.');
    $columns=[];
    foreach($metadata as $row){$name=(string)($row['COLUMN_NAME']??'');$columns[strtoupper($name)]='['.str_replace(']',']]',$name).']';}
    foreach(['ALM_ALMEXTFLD2','ALM_NATIVETIMEIN'] as $required)if(!isset($columns[$required]))throw new RuntimeException('El historial no tiene los campos de pozo y fecha requeridos.');
    $pick=function($names,$alias)use($columns){foreach($names as $name)if(isset($columns[$name]))return 'CONVERT(nvarchar(4000),'.$columns[$name].') AS ['.$alias.']';return "N'' AS [$alias]";};
    $select=implode(',',[$pick(['ALM_DESCR','ALM_TAGDESC'],'description'),$pick(['ALM_TAGNAME'],'tag'),$pick(['ALM_VALUE'],'value'),$pick(['ALM_ALMSTATUS'],'status'),$pick(['ALM_NATIVETIMEIN'],'started_at'),$pick(['ALM_NATIVETIMELAST'],'last_at')]);
    $where='LTRIM(RTRIM(CONVERT(nvarchar(4000),'.$columns['ALM_ALMEXTFLD2'].'))) = ?';
    $date=$columns['ALM_NATIVETIMEIN'];
    $latest=$db->all("SELECT TOP (1) $select FROM dbo.FIXALARMS WHERE $where ORDER BY $date DESC",[$well]);
    if($db->error())throw new RuntimeException('No se pudo consultar la última alarma.');
    $count=$db->scalar("SELECT COUNT(*) FROM dbo.FIXALARMS WHERE $where AND $date >= CONVERT(datetime2, ?, 126) AND $date <= CONVERT(datetime2, ?, 126)",[$well,$since,$until]);
    if($db->error())throw new RuntimeException('No se pudo consultar la cantidad de alarmas.');
    return ['latest'=>$latest[0]??null,'count24h'=>(int)$count];
}
