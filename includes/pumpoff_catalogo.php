<?php
require_once __DIR__.'/pumpoff_partes.php';

function pfc_zone($value): string {
    $key=preg_replace('/[\s_-]+/u',' ',pf_key($value));
    $map=['LHCG'=>'LHCG','LH ZONA LHCG'=>'LHCG','ZONA LHCG'=>'LHCG','CED I'=>'CED I','CED ZONA I'=>'CED I','CED II'=>'CED II','CED ZONA II'=>'CED II'];
    return $map[$key]??'';
}
function pfc_unique(array $rows,string $field): string {
    $values=[];foreach($rows as $row){$value=nm_text(ns_value($row,$field));if($value!=='')$values[pf_key($value)]=$value;}
    return count($values)===1?(string)reset($values):'';
}
function pfc_catalog($db,bool $installationsOnly=false): array {
    $out=['wells'=>[],'batteries'=>[],'warnings'=>[],'loaded_at'=>date('Y-m-d H:i:s')];
    if(!$db||!$db->ok())throw new RuntimeException('No hay conexión con SQL Server. Podés completar el parte manualmente.');
    // Solo tablas locales: nunca abrir una vista RTQP como alternativa silenciosa.
    $meta=$db->all("SELECT s.name ESQUEMA,t.name TABLA,c.name COLUMNA FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id JOIN sys.columns c ON c.object_id=t.object_id WHERE t.object_id IN (OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE'),OBJECT_ID(N'dbo.CLEAR_SUPERVISORES_INSTALACIONES'),OBJECT_ID(N'CLEAR.ZONAS'))");
    $columns=[];foreach($meta as $m)$columns[nm_text(ns_value($m,'ESQUEMA')).'.'.nm_text(ns_value($m,'TABLA'))][strtoupper(nm_text(ns_value($m,'COLUMNA')))]=true;
    $read=function($table,array $required,array $optional,int $limit,string $where='')use($db,$columns,&$out){
        $available=$columns[$table]??[];
        foreach($required as $field)if(!isset($available[$field])){$out['warnings'][]='No se pudo usar '.$table.': falta el objeto, una columna o permiso de lectura.';return [];}
        $select=[];foreach(array_merge($required,$optional) as $field)$select[]=isset($available[$field])?'['.$field.']':"N'' AS [".$field.']';
        $rows=$db->all('SELECT TOP ('.($limit+1).') '.implode(',',$select).' FROM '.$table.($where?' WHERE '.$where:''));
        if(count($rows)>$limit){$out['warnings'][]=$table.' supera el límite del catálogo. No se usa una lista incompleta.';return [];}
        if(!$rows)$out['warnings'][]=$table.' no devolvió registros disponibles. Revisá datos y permisos.';
        return $rows;
    };
    $wells=$installationsOnly?[]:$read('dbo.TELEMETRIA_POZOS_GENERAL_CACHE',['POZO','BATERIA','TIPO'],['TAG','FECHA_CACHE'],5000,"TIPO=N'BM'");
    $assigned=$read('dbo.CLEAR_SUPERVISORES_INSTALACIONES',['BATERIA','ZONA','SUPERVISOR','JEFE_ZONA','ACTIVO'],['JEFE_PRODUCCION'],3000,'ACTIVO=1');
    $zones=isset($columns['CLEAR.ZONAS'])?$read('CLEAR.ZONAS',['ZONA','BATERIA_POZOS'],[],5000):[];
    $zoneMap=[];foreach($zones as $r){$key=nm_battery_key(ns_value($r,'BATERIA_POZOS'));$z=pfc_zone(ns_value($r,'ZONA'));if($key!==''&&$z!=='')$zoneMap[$key][]=['ZONA'=>$z];}
    $byBattery=[];$labels=[];foreach($assigned as $raw){
        $battery=nm_text(ns_value($raw,'BATERIA'));$key=nm_battery_key($battery);if($key==='')continue;
        $row=[];foreach(['BATERIA','SUPERVISOR','JEFE_ZONA','JEFE_PRODUCCION'] as $field)$row[$field]=nm_text(ns_value($raw,$field));
        $row['ZONA']=pfc_zone(ns_value($raw,'ZONA'));$byBattery[$key][]=$row;$labels[$key]=$battery;
    }
    if($installationsOnly)foreach($zones as $r){$battery=nm_text(ns_value($r,'BATERIA_POZOS'));$key=nm_battery_key($battery);if($key!==''&&!isset($labels[$key]))$labels[$key]=$battery;}
    foreach($wells as $r){$battery=nm_text(ns_value($r,'BATERIA'));$key=nm_battery_key($battery);if($key!==''&&!isset($labels[$key]))$labels[$key]=$battery;}
    $resolved=[];
    foreach($labels as $key=>$battery){
        $rows=$byBattery[$key]??[];$row=['BATERIA'=>$battery];
        foreach(['ZONA','SUPERVISOR','JEFE_ZONA','JEFE_PRODUCCION'] as $field)$row[$field]=pfc_unique($rows,$field);
        if(!$rows)$row['ZONA']=pfc_unique($zoneMap[$key]??[],'ZONA');
        $resolved[$key]=$row;$out['batteries'][]=$row;
    }
    $grouped=[];foreach($wells as $raw){
        $well=nm_text(ns_value($raw,'POZO'));$battery=nm_battery_key(ns_value($raw,'BATERIA'));if($well===''||$battery==='')continue;
        $grouped[pf_key($well).'|'.$battery][]=$raw;
    }
    foreach($grouped as $rawRows){
        $raw=$rawRows[0];$battery=nm_battery_key(ns_value($raw,'BATERIA'));$row=$resolved[$battery];
        $row['POZO']=nm_text(ns_value($raw,'POZO'));$row['TAG']=pfc_unique($rawRows,'TAG');$row['FECHA_CACHE']=pfc_unique($rawRows,'FECHA_CACHE');
        $out['wells'][]=$row;
    }
    if(!$installationsOnly&&!$out['wells'])$out['warnings'][]='Sin lista local de pozos BM. No se consulta RTQP como reemplazo.';
    usort($out['wells'],function($a,$b){return strnatcasecmp($a['POZO'],$b['POZO']);});
    usort($out['batteries'],function($a,$b){return strnatcasecmp($a['BATERIA'],$b['BATERIA']);});
    return $out;
}
