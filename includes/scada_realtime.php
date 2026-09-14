<?php
/* =============================================================
   CLEAR PLATAFORMA — soporte de SCADA Real time.
   La fuente OPC se normaliza en SQL mediante CLEAR_SCADA_REALTIME_V.
============================================================= */

require_once __DIR__ . '/db.php';

function clear_scada_objects_ready($db = null)
{
    $db = $db ?: clear_db();
    if (!$db || !$db->ok()) return false;
    return (int)$db->scalar(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_SCADA_REALTIME_V',N'V') IS NOT NULL " .
        "AND OBJECT_ID(N'dbo.CLEAR_SCADA_SETTINGS',N'U') IS NOT NULL " .
        "AND OBJECT_ID(N'dbo.CLEAR_SCADA_TAG_MAP',N'U') IS NOT NULL " .
        "AND OBJECT_ID(N'dbo.CLEAR_SCADA_ALARM_CACHE',N'U') IS NOT NULL " .
        "THEN 1 ELSE 0 END"
    ) === 1;
}

function clear_scada_row_value(array $row, $name, $default = null)
{
    if (array_key_exists($name, $row)) return $row[$name];
    $wanted = strtoupper((string)$name);
    foreach ($row as $key => $value) {
        if (strtoupper((string)$key) === $wanted) return $value;
    }
    return $default;
}

function clear_scada_settings($db = null)
{
    $defaults = [
        'source_schema' => 'dbo',
        'source_table' => '',
        'refresh_seconds' => 10,
        'stale_seconds' => 900,
        'alarm_window_hours' => 24,
        'pi_vision_base_url' => '',
        'updated_at' => '',
        'updated_by' => '',
    ];
    $db = $db ?: clear_db();
    if (!$db || !$db->ok() || !clear_scada_objects_ready($db)) return $defaults;
    $rows = $db->all(
        "SELECT TOP 1 SOURCE_SCHEMA,SOURCE_TABLE,REFRESH_SECONDS,STALE_SECONDS," .
        "ALARM_WINDOW_HOURS,PI_VISION_BASE_URL,UPDATED_AT,UPDATED_BY " .
        "FROM dbo.CLEAR_SCADA_SETTINGS WHERE ID=1"
    );
    if (!$rows) return $defaults;
    $row = $rows[0];
    return [
        'source_schema' => trim((string)clear_scada_row_value($row, 'SOURCE_SCHEMA', 'dbo')),
        'source_table' => trim((string)clear_scada_row_value($row, 'SOURCE_TABLE', '')),
        'refresh_seconds' => max(5, min(300, (int)clear_scada_row_value($row, 'REFRESH_SECONDS', 10))),
        'stale_seconds' => max(10, min(86400, (int)clear_scada_row_value($row, 'STALE_SECONDS', 900))),
        'alarm_window_hours' => max(1, min(720, (int)clear_scada_row_value($row, 'ALARM_WINDOW_HOURS', 24))),
        'pi_vision_base_url' => trim((string)clear_scada_row_value($row, 'PI_VISION_BASE_URL', '')),
        'updated_at' => (string)clear_scada_row_value($row, 'UPDATED_AT', ''),
        'updated_by' => (string)clear_scada_row_value($row, 'UPDATED_BY', ''),
    ];
}

function clear_scada_csrf_token()
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['clear_scada_csrf'])) {
        $_SESSION['clear_scada_csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['clear_scada_csrf'];
}

function clear_scada_verify_csrf($token)
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $stored = (string)($_SESSION['clear_scada_csrf'] ?? '');
    return $stored !== '' && hash_equals($stored, (string)$token);
}

function clear_scada_clean($value, $maxLength = 255)
{
    $value = trim((string)$value);
    return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
}

/* Los colectores y los exportadores de PI pueden agregar NBSP, BOM o
   comillas alrededor del nombre. Para vincular usamos siempre el TAG limpio. */
function clear_scada_normalize_tag($value, $maxLength = 255)
{
    $value = str_replace(["\xC2\xA0", "\xEF\xBB\xBF"], [' ', ''], (string)$value);
    $value = trim($value);
    $value = preg_replace('/^[\"\']+|[\"\']+$/u', '', $value);
    $value = trim((string)$value);
    return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
}

/* Resuelve el WebId desde el inventario local de puntos PI. Acepta:
     BCE008_TAG, LHC_BCE008_TAG y cualquier prefijo terminado en _BCE008_TAG.
   PI_Points_Stage es sólo lectura y evita una búsqueda remota por cada clic. */
function clear_scada_pi_stage_point($db, $tag)
{
    $tag = clear_scada_normalize_tag($tag);
    if ($tag === '' || !$db || !$db->ok()) return null;

    $ready = (int)$db->scalar(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.PI_Points_Stage',N'U') IS NOT NULL " .
        "AND COL_LENGTH(N'dbo.PI_Points_Stage',N'WebId') IS NOT NULL " .
        "AND COL_LENGTH(N'dbo.PI_Points_Stage',N'Name') IS NOT NULL " .
        "THEN 1 ELSE 0 END"
    );
    if ($ready !== 1) return null;

    /* El caso habitual compara sólo cuatro variantes conocidas. CONVERT también
       admite inventarios importados con Name definido como text/ntext. */
    $select = "SELECT TOP(1) CONVERT(nvarchar(512),S.[WebId]) AS WebId," .
              "CONVERT(nvarchar(512),S.[Name]) AS Name," .
              "CONVERT(nvarchar(1000),S.[Description]) AS Descriptor," .
              "CONVERT(nvarchar(128),S.[EngineeringUnits]) AS EngineeringUnits," .
              "CONVERT(nvarchar(1000),S.[Path]) AS Path FROM dbo.PI_Points_Stage S ";
    $rows = $db->all(
        $select . "WHERE NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(512),S.[WebId]))),N'') IS NOT NULL " .
        "AND CONVERT(nvarchar(512),S.[Name]) IN(?,?,?,?) " .
        "ORDER BY CASE WHEN CONVERT(nvarchar(512),S.[Name])=? THEN 0 " .
        "WHEN CONVERT(nvarchar(512),S.[Name])=? THEN 1 ELSE 2 END",
        [$tag, 'LHC_' . $tag, '"' . $tag . '"', '"LHC_' . $tag . '"', $tag, 'LHC_' . $tag]
    );

    /* Fallback para espacios no separables, BOM u otros prefijos de servidor. */
    $sql = "SELECT TOP(1) " .
           "CONVERT(nvarchar(512),S.[WebId]) AS WebId,P.CLEAN_NAME AS Name," .
           "CONVERT(nvarchar(1000),S.[Description]) AS Descriptor," .
           "CONVERT(nvarchar(128),S.[EngineeringUnits]) AS EngineeringUnits," .
           "CONVERT(nvarchar(1000),S.[Path]) AS Path " .
           "FROM dbo.PI_Points_Stage S " .
           "CROSS APPLY(SELECT LTRIM(RTRIM(REPLACE(REPLACE(REPLACE(" .
           "CONVERT(nvarchar(512),S.[Name]),NCHAR(34),N''),NCHAR(160),N' '),NCHAR(65279),N''))) AS CLEAN_NAME)P " .
           "WHERE NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(512),S.[WebId]))),N'') IS NOT NULL AND (" .
           "UPPER(P.CLEAN_NAME)=UPPER(?) OR UPPER(P.CLEAN_NAME)=N'LHC_'+UPPER(?) OR " .
           "RIGHT(UPPER(P.CLEAN_NAME),LEN(?)+1)=N'_'+UPPER(?)) " .
           "ORDER BY CASE WHEN UPPER(P.CLEAN_NAME)=UPPER(?) THEN 0 " .
           "WHEN UPPER(P.CLEAN_NAME)=N'LHC_'+UPPER(?) THEN 1 ELSE 2 END";
    if (!$rows) $rows = $db->all($sql, [$tag, $tag, $tag, $tag, $tag, $tag]);
    if (!$rows) return null;

    $row = $rows[0];
    return [
        'WebId' => clear_scada_normalize_tag(clear_scada_row_value($row, 'WebId', ''), 512),
        'Name' => clear_scada_normalize_tag(clear_scada_row_value($row, 'Name', ''), 512),
        'Descriptor' => trim((string)clear_scada_row_value($row, 'Descriptor', '')),
        'EngineeringUnits' => trim((string)clear_scada_row_value($row, 'EngineeringUnits', '')),
        'Path' => trim((string)clear_scada_row_value($row, 'Path', '')),
    ];
}
