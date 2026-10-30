<?php
require __DIR__.'/../includes/well_activity.php';
function wa_check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function wa_rejects($fn) {
    try { $fn(); } catch (RuntimeException $error) { return; } catch (InvalidArgumentException $error) { return; }
    throw new RuntimeException('Se aceptó una búsqueda que puede alterar la correspondencia del pozo.');
}
class ActivityDb {
    public $calls = [];
    public $cache = '3';
    public $cachePresent = true;
    public $cacheError = false;
    public $latestError = false;
    public $lastError = '';
    public $latest = [['description' => 'Comunicación', 'value' => 'FALLA', 'ALM_ALMSTATUS' => '']];
    function all($sql, $params = []) {
        $this->calls[] = [$sql, $params];
        if (strpos($sql, 'INFORMATION_SCHEMA') !== false) {
            $rows = [];
            foreach (['ALM_ALMEXTFLD2','ALM_NATIVETIMEIN','ALM_TAGDESC','ALM_VALUE','ALM_ALMSTATUS'] as $name) {
                $rows[] = ['TABLE_NAME'=>'FIXALARMS', 'COLUMN_NAME'=>$name, 'DATA_TYPE'=>'varchar', 'CHARACTER_MAXIMUM_LENGTH'=>255];
            }
            if ($this->cachePresent) foreach (['POZO','ALM'] as $name) {
                $rows[] = ['TABLE_NAME'=>'TELEMETRIA_POZOS_GENERAL_CACHE', 'COLUMN_NAME'=>$name, 'DATA_TYPE'=>'nvarchar', 'CHARACTER_MAXIMUM_LENGTH'=>120];
            }
            return $rows;
        }
        if ($this->latestError) { $this->lastError = 'fallo simulado'; return []; }
        return $this->latest;
    }
    function scalar($sql, $params = []) {
        $this->calls[] = [$sql, $params];
        if ($this->cacheError) { $this->lastError = 'fallo simulado'; return null; }
        return $this->cache;
    }
    function error() { return $this->lastError; }
}
$well = 'YPF.SC.BB-10';
$db = new ActivityDb();
$result = wa_alarms($db, $well);
wa_check($result['count24h'] === 3 && $result['count24hAvailable'], 'Leer contador de caché, conservando entero.');
wa_check($result['latest']['value'] === 'FALLA' && $result['latest']['status'] === '', 'Conservar alarma y estado ya filtrado por política CFN.');
wa_check(!isset($result['latest']['ALM_ALMSTATUS']), 'No publicar una segunda clave de estado.');
$latestSql = $db->calls[1][0];
wa_check(strpos($latestSql, 'TOP (1)') !== false, 'Sólo leer la última alarma.');
wa_check(strpos($latestSql, 'WHERE [ALM_ALMEXTFLD2] = CONVERT(varchar(255), ?) ORDER BY [ALM_NATIVETIMEIN] DESC') !== false, 'Filtro exacto sin conversión sobre columna ni cambio implícito varchar→nvarchar.');
wa_check(strpos($latestSql, 'AS [ALM_ALMSTATUS]') !== false, 'Conservar clave original para respetar política CFN en DB.');
wa_check($db->calls[1][1] === [$well] && $db->calls[2][1] === [$well], 'El pozo se pasa como parámetro exacto.');
wa_check(strpos($db->calls[2][0], 'FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE') !== false, 'El contador sale de la caché.');
wa_check(strpos($db->calls[2][0], 'MAX(') !== false, 'Conservar criterio de la grilla para pozos con dos sistemas.');
foreach ($db->calls as $call) {
    wa_check(!preg_match('/COUNT\s*\(/i', $call[0]), 'No recalcular conteos de FIXALARMS en una petición web.');
    wa_check(!preg_match('/\b(LIKE|LTRIM|RTRIM)\b/i', $call[0]), 'No buscar por coincidencia parcial ni normalizar la columna indexada.');
    wa_check(!preg_match('/\b(CREATE|ALTER|UPDATE|DELETE|INSERT)\b/i', $call[0]), 'Las consultas son sólo de lectura.');
}
foreach ([0, '0'] as $zero) {
    $db = new ActivityDb(); $db->cache = $zero; $result = wa_alarms($db, $well);
    wa_check($result['count24h'] === 0 && $result['count24hAvailable'], 'Un cero real de caché es válido.');
}
foreach ([null, '', -1, 'texto'] as $missing) {
    $db = new ActivityDb(); $db->cache = $missing; $result = wa_alarms($db, $well);
    wa_check($result['count24h'] === null && !$result['count24hAvailable'] && $result['count24hWarning'] !== '', 'La ausencia de contador no equivale a cero.');
    wa_check($result['latest'] !== null, 'La última alarma permanece cuando falta el contador.');
}
$db = new ActivityDb(); $db->cachePresent = false; $result = wa_alarms($db, $well);
wa_check($result['count24h'] === null && count($db->calls) === 2, 'Sin caché no intentar consultarla ni recalcular FIXALARMS.');
$db = new ActivityDb(); $db->cacheError = true; $result = wa_alarms($db, $well);
wa_check($result['count24h'] === null && $result['latest'] !== null, 'Una falla de caché no borra una alarma válida.');
$db = new ActivityDb(); $db->latest = []; $result = wa_alarms($db, $well);
wa_check($result['latest'] === null && $result['count24h'] === 3, 'La ausencia de última alarma se informa por separado del contador.');
$db = new ActivityDb(); $db->latestError = true; wa_rejects(function () use ($db, $well) { wa_alarms($db, $well); });
foreach (['char', 'varchar', 'nchar', 'nvarchar'] as $type) {
    wa_check(wa_parameter(['DATA_TYPE'=>$type,'CHARACTER_MAXIMUM_LENGTH'=>255], $well) === 'CONVERT('.$type.'(255), ?)', 'El parámetro conserva tipo de campo.');
}
wa_rejects(function () use ($well) { wa_parameter(['DATA_TYPE'=>'varchar','CHARACTER_MAXIMUM_LENGTH'=>3], $well); });
wa_rejects(function () use ($well) { wa_parameter(['DATA_TYPE'=>'text','CHARACTER_MAXIMUM_LENGTH'=>255], $well); });
wa_rejects(function () use ($well) { wa_parameter(['DATA_TYPE'=>'varchar); DROP TABLE x;--','CHARACTER_MAXIMUM_LENGTH'=>255], $well); });
echo "OK actividad: última alarma exacta y parametrizada, caché sin COUNT, cero real frente a ausencias, CFN y fallos parciales.\n";
