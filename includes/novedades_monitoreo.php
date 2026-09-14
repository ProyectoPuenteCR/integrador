<?php
/* Lectura para la nueva vista. Sin DDL, escrituras, parseo de fechas ni llamadas por gráfico. */
require_once __DIR__.'/novedades_semanales_common.php';
require_once __DIR__.'/zones.php';

function nm_columns(): array
{
    return ['POZO'=>'Pozo','BATERIA'=>'Batería','ZONA'=>'Zona','SUPERVISOR'=>'Supervisor',
        'Fecha'=>'Fecha de la fuente (sin convertir)','YT:LLAVE'=>'YT:LLAVE',
        'YT:CONTROL'=>'YT:CONTROL','YT:LLAVE-AUTO'=>'YT:LLAVE-AUTO','YT:DEVICE'=>'YT:DEVICE',
        'ESTADO'=>'ESTADO','ESTADO-GRAL'=>'ESTADO-GRAL','ZT:PUMPOFF'=>'ZT:PUMPOFF',
        'YAT:COM'=>'YAT:COM','JEFE_ZONA'=>'Jefe de zona'];
}

function nm_text($value): string
{
    return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : trim((string)$value);
}

function nm_battery_key($value): string
{
    // Misma regla de BATERIA_CLAVE del maestro existente; no deducir desde POZO.
    return strtoupper(str_replace([' ','-','_','.','/'],'',trim((string)$value)));
}

function nm_load($db): array
{
    $result=['rows'=>[],'warnings'=>[],'error'=>'','queried_at'=>date('Y-m-d H:i:s'),
        'missing'=>[],'duplicates'=>0,'unnamed'=>0];
    if (!$db || !$db->ok()) { $result['error']='Sin conexión a SQL Server.'; return $result; }
    $meta=$db->all("SELECT name FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.BM_RTQP')");
    $available=[];
    foreach($meta as $item) $available[strtoupper(nm_text(ns_value($item,'name')))]=true;
    if (!isset($available['POZO']) || !isset($available['BATERIA'])) {
        $result['error']='No se pudo verificar POZO y BATERIA en dbo.BM_RTQP. Revisá la vista y el permiso de lectura.';
        return $result;
    }
    $source=array_diff(array_keys(nm_columns()),['ZONA','SUPERVISOR','JEFE_ZONA']);
    if(isset($available['TAG']))$source[]='TAG';
    $select=[];
    foreach($source as $column) {
        $quoted='['.str_replace(']',']]',$column).']';
        if(isset($available[strtoupper($column)])) $select[]=$quoted;
        else { $select[]='NULL AS '.$quoted; $result['missing'][]=$column; }
    }
    // Misma fuente y límite operativo de Monitoreo; fila extra detecta truncamiento.
    $raw=$db->all('SELECT TOP (1001) '.implode(',',$select).' FROM dbo.BM_RTQP ORDER BY [POZO]');
    if (!$raw && $db->error()) {
        $result['error']='No se pudo leer dbo.BM_RTQP. No se muestran ceros como si fueran datos válidos.';
        return $result;
    }
    if(count($raw)>1000) {
        $result['error']='BM_RTQP supera 1.000 registros. No se muestran totales parciales; hay que ajustar el alcance o la paginación antes de usar esta vista.';
        return $result;
    }
    if($result['missing']) $result['warnings'][]='Campos ausentes en la vista: '.implode(', ',$result['missing']).'. Se muestran sin dato.';
    // No elegir arbitrariamente una lectura si el pozo aparece más de una vez.
    $groups=[];
    foreach($raw as $item) {
        $row=[];foreach($source as $column) $row[$column]=nm_text(ns_value($item,$column));
        if($row['POZO']==='') { $result['unnamed']++;continue; }
        $key=strtoupper($row['POZO']);
        if(!isset($groups[$key])) $groups[$key]=[];
        $groups[$key][]=$row;
    }
    $rows=[];
    foreach($groups as $group) {
        if(count($group)>1) { $result['duplicates']++;continue; }
        $rows[]=$group[0];
    }
    if($result['duplicates']) $result['warnings'][]=$result['duplicates'].' pozos con más de un registro excluidos de la grilla y los gráficos para evitar elegir una lectura sin confirmar.';
    if($result['unnamed']) $result['warnings'][]=$result['unnamed'].' filas sin POZO excluidas.';
    if(!$rows) { $result['rows']=[];return $result; }

    $zones=[];
    $zoneRows=$db->all('SELECT ZONA,BATERIA_POZOS FROM [CLEAR].[ZONAS] WHERE ZONA IS NOT NULL AND BATERIA_POZOS IS NOT NULL');
    if(!$zoneRows) $result['warnings'][]='El catálogo de zonas no devolvió asignaciones. Los pozos se muestran sin zona; no se deduce desde su nombre.';
    foreach($zoneRows as $item) {
        $key=clear_zone_compact_value(ns_value($item,'BATERIA_POZOS'));
        $zone=nm_text(ns_value($item,'ZONA'));
        if($key!=='' && $zone!=='') $zones[$key][$zone]=true;
    }
    $supervisors=[];
    $hasSupervisors=(int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_SUPERVISORES_INSTALACIONES',N'U') IS NULL THEN 0 ELSE 1 END");
    if($hasSupervisors) {
        $assigned=$db->all('SELECT BATERIA,SUPERVISOR,JEFE_ZONA FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES WHERE ACTIVO=1');
        if(!$assigned) $result['warnings'][]='No se obtuvieron asignaciones activas del maestro de supervisores.';
        foreach($assigned as $item) {
            $key=nm_battery_key(ns_value($item,'BATERIA'));
            if($key!=='') $supervisors[$key][]=$item;
        }
    } else $result['warnings'][]='El maestro de supervisores no está disponible. No se crea ni modifica desde esta pantalla.';
    foreach($rows as &$row) {
        $found=$zones[clear_zone_compact_value($row['BATERIA'])]??[];
        $row['ZONA']=count($found)===1 ? (string)array_key_first($found) : (count($found)>1?'Zona ambigua':'Sin zona');
        $found=$supervisors[nm_battery_key($row['BATERIA'])]??[];
        foreach(['SUPERVISOR','JEFE_ZONA'] as $field) $row[$field]=count($found)===1?nm_text(ns_value($found[0],$field)):'';
    }
    unset($row);
    $result['rows']=$rows;
    return $result;
}

function nm_json($value): string
{
    return (string)json_encode($value,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE);
}
