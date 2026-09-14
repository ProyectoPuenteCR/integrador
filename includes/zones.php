<?php
/** Utilidades compartidas para el filtro por zona. */
function clear_zones_all($db): array {
    if (!$db || !$db->ok()) return [];
    $rows = $db->all("SELECT DISTINCT LTRIM(RTRIM(ZONA)) AS ZONA FROM [CLEAR].[ZONAS] WHERE ZONA IS NOT NULL AND LTRIM(RTRIM(ZONA))<>'' ORDER BY ZONA");
    $out=[];
    foreach($rows as $r){ $v=trim((string)($r['ZONA']??reset($r))); if($v!=='')$out[]=$v; }
    return array_values(array_unique($out));
}
function clear_zone_valid(string $value, array $zones): string {
    $value=trim($value); return in_array($value,$zones,true)?$value:'';
}
function clear_zone_batteries($db,string $zone,string $scope='instalaciones'): array {
    if($zone===''||!$db||!$db->ok())return [];
    $column=strtolower($scope)==='pozos'?'BATERIA_POZOS':'BATERIA';
    $sql="SELECT DISTINCT LTRIM(RTRIM([".$column."])) AS BATERIA FROM [CLEAR].[ZONAS] WHERE LTRIM(RTRIM(ZONA)) COLLATE DATABASE_DEFAULT = ? COLLATE DATABASE_DEFAULT AND [".$column."] IS NOT NULL AND LTRIM(RTRIM([".$column."]))<>'' ORDER BY BATERIA";
    $rows=$db->all($sql,[$zone]);
    $out=[]; foreach($rows as $r){$v=trim((string)($r['BATERIA']??reset($r)));if($v!=='')$out[]=$v;} return $out;
}

function clear_zone_compact_value($value): string {
    $value=(string)$value;
    $value=str_replace(["Â ", "	", "", "
", ' '], '', $value);
    return strtoupper(trim($value));
}

function clear_zone_compact_sql_expr(string $sqlExpr): string {
    /*
     * Normalización conservadora para baterías de pozos:
     *   "CE 04", "CE04" y valores con espacio no separable => "CE04".
     * No convierte a entero ni altera los ceros a la izquierda.
     */
    return "REPLACE(REPLACE(REPLACE(UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100),$sqlExpr)))),NCHAR(160),''),CHAR(9),''),' ','')";
}
function clear_zone_normalized_battery_expr(string $sqlExpr): string {
    return clear_zone_compact_sql_expr($sqlExpr);
}
function clear_zone_normalized_catalog_expr(string $alias='Z',string $column='BATERIA'): string {
    $safeColumn=$column==='BATERIA_POZOS'?'BATERIA_POZOS':'BATERIA';
    $expr="[$alias].[$safeColumn]";
    if($safeColumn==='BATERIA'){
        /* BCE004 -> CE004 se conserva para pantallas de instalaciones. */
        $expr="CASE WHEN LEN(LTRIM(RTRIM(CONVERT(nvarchar(100),$expr))))>2 THEN SUBSTRING(LTRIM(RTRIM(CONVERT(nvarchar(100),$expr))),2,99) ELSE LTRIM(RTRIM(CONVERT(nvarchar(100),$expr))) END";
    }
    return clear_zone_compact_sql_expr($expr);
}
