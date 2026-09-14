<?php
ob_start();
ini_set('display_errors', '0');

$scadaApiConfig = require __DIR__ . '/config.php';
date_default_timezone_set($scadaApiConfig['app']['tz'] ?? 'America/Argentina/Buenos_Aires');

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/appconfig.php';
require_once __DIR__ . '/includes/scada_realtime.php';

auth_require();
permissions_require_menu('scada_realtime');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* Si IIS/PHP produce un fatal, devolvemos JSON para que la pantalla muestre
   la causa en lugar del ambiguo "Error HTTP 500". */
register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    if (ob_get_length()) ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Error interno del API SCADA: ' . (string)$error['message'],
        'code' => 'SCADA_API_FATAL',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

function scada_api_out($payload, $status = 200)
{
    if (ob_get_length()) ob_clean();
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function scada_api_list($db, $sql, array $params = [])
{
    $rows = $db->all($sql, $params);
    $result = [];
    foreach ($rows as $row) {
        $value = reset($row);
        $value = trim((string)$value);
        if ($value !== '') $result[] = $value;
    }
    return array_values(array_unique($result));
}

function scada_api_datetime($value)
{
    $timezone = new DateTimeZone(date_default_timezone_get());
    if ($value instanceof DateTimeInterface) {
        /* SQL datetime no contiene zona: conservamos su hora de pared. */
        $value = $value->format('Y-m-d H:i:s.u');
    }
    $value = str_replace(["\xC2\xA0", "\xEF\xBB\xBF"], [' ', ''], trim((string)$value));
    if ($value === '') return '';
    /* Algunos drivers etiquetan el datetime local como UTC. La columna SQL es
       datetime/datetime2, por lo que ese sufijo no forma parte del dato. */
    $localValue = preg_replace('/\s*(?:Z|[+\-]\d{2}:?\d{2})$/i', '', $value);
    try {
        return (new DateTimeImmutable($localValue, $timezone))->format(DateTime::ATOM);
    } catch (Exception $e) {
        return $value;
    }
}

$db = clear_db();
if (!$db->ok()) scada_api_out(['ok'=>false, 'error'=>'Sin conexión a SQL Server. '.$db->error()], 500);
if (!clear_scada_objects_ready($db)) {
    scada_api_out([
        'ok'=>false,
        'error'=>'Falta instalar SQL/CLEAR_SCADA_REALTIME_20260908.sql.',
        'code'=>'SCADA_NOT_INSTALLED',
    ], 503);
}

$action = strtolower(clear_scada_clean($_GET['action'] ?? 'bootstrap', 40));
$settings = clear_scada_settings($db);
$staleSeconds = (int)$settings['stale_seconds'];

if ($action === 'bootstrap') {
    $zones = scada_api_list($db, "SELECT DISTINCT ZONA FROM dbo.CLEAR_SCADA_REALTIME_V WHERE NULLIF(LTRIM(RTRIM(ZONA)),N'') IS NOT NULL ORDER BY ZONA");
    $nodes = scada_api_list($db, "SELECT DISTINCT NODO FROM dbo.CLEAR_SCADA_REALTIME_V WHERE NULLIF(LTRIM(RTRIM(NODO)),N'') IS NOT NULL ORDER BY NODO");
    $areas = scada_api_list($db, "SELECT DISTINCT AREA_EQUIPO FROM dbo.CLEAR_SCADA_REALTIME_V WHERE NULLIF(LTRIM(RTRIM(AREA_EQUIPO)),N'') IS NOT NULL ORDER BY AREA_EQUIPO");
    $qualities = scada_api_list($db, "SELECT DISTINCT CALIDAD FROM dbo.CLEAR_SCADA_REALTIME_V WHERE NULLIF(LTRIM(RTRIM(CALIDAD)),N'') IS NOT NULL ORDER BY CALIDAD");
    $batteryRows = $db->all(
        "SELECT V.ZONA,V.BATERIA,COUNT_BIG(*) AS VARIABLES," .
        "SUM(CASE WHEN (UPPER(ISNULL(V.CALIDAD,N'')) LIKE N'%GOOD%' OR UPPER(ISNULL(V.CALIDAD,N'')) IN(N'OK',N'BUENA')) " .
        "AND V.FECHA_OPC IS NOT NULL AND DATEDIFF(second,V.FECHA_OPC,SYSDATETIME())<=? THEN 1 ELSE 0 END) AS GOOD_COUNT," .
        "SUM(CONVERT(bigint,ISNULL(A.CANTIDAD,0))) AS ALARM_COUNT,MAX(V.FECHA_OPC) AS ULTIMA_FECHA " .
        "FROM dbo.CLEAR_SCADA_REALTIME_V V LEFT JOIN dbo.CLEAR_SCADA_ALARM_CACHE A ON A.TAG=V.TAG " .
        "GROUP BY V.ZONA,V.BATERIA ORDER BY V.ZONA,V.BATERIA",
        [$staleSeconds]
    );
    $batteries = [];
    foreach ($batteryRows as $row) {
        $variables = (int)clear_scada_row_value($row, 'VARIABLES', 0);
        $good = (int)clear_scada_row_value($row, 'GOOD_COUNT', 0);
        $batteries[] = [
            'zone' => (string)clear_scada_row_value($row, 'ZONA', 'Sin asignar'),
            'battery' => (string)clear_scada_row_value($row, 'BATERIA', 'Sin asignar'),
            'variables' => $variables,
            'good_count' => $good,
            'quality_percent' => $variables > 0 ? round(($good * 100) / $variables, 1) : 0,
            'alarm_count' => (int)clear_scada_row_value($row, 'ALARM_COUNT', 0),
            'latest' => scada_api_datetime(clear_scada_row_value($row, 'ULTIMA_FECHA', '')),
        ];
    }

    $statusRows = $db->all("SELECT COMPONENTE,ESTADO,INICIO,FIN,FILAS,DETALLE FROM dbo.CLEAR_SCADA_RUNTIME_STATUS ORDER BY COMPONENTE");
    $pi = pi_config();
    scada_api_out([
        'ok'=>true,
        'settings'=>$settings,
        'pi'=>[
            'configured'=>trim((string)($pi['base'] ?? '')) !== '' && trim((string)($pi['ds_webid'] ?? '')) !== '',
            'ds_webid'=>(string)($pi['ds_webid'] ?? ''),
        ],
        'filters'=>[
            'zones'=>$zones,
            'nodes'=>$nodes,
            'areas'=>$areas,
            'qualities'=>$qualities,
            /* Los TAGs se cargan bajo demanda para la batería activa. Devolver
               los 20.000+ TAGs aquí agotaba el tiempo/memoria de PHP COM. */
            'tags'=>[],
        ],
        'batteries'=>$batteries,
        'runtime_status'=>$statusRows,
        'can_comment'=>permissions_can('comments.create'),
        'server_time'=>date(DateTime::ATOM),
    ]);
}

if ($action === 'tags') {
    $zone = clear_scada_clean($_GET['zone'] ?? '', 128);
    $battery = clear_scada_clean($_GET['battery'] ?? '', 128);
    $node = clear_scada_clean($_GET['node'] ?? '', 128);
    $area = clear_scada_clean($_GET['area'] ?? '', 255);

    /* Un combo global con más de 20.000 elementos no es utilizable. El filtro
       se habilita cuando hay una batería y devuelve únicamente sus TAGs. */
    if ($battery === '') {
        scada_api_out(['ok'=>true, 'tags'=>[], 'requires_battery'=>true]);
    }

    $where = ['V.BATERIA=?', "NULLIF(LTRIM(RTRIM(V.TAG)),N'') IS NOT NULL"];
    $params = [$battery];
    foreach ([['V.ZONA', $zone], ['V.NODO', $node], ['V.AREA_EQUIPO', $area]] as $filter) {
        if ($filter[1] === '') continue;
        $where[] = $filter[0] . '=?';
        $params[] = $filter[1];
    }

    $tagRows = $db->all(
        "SELECT DISTINCT TOP (2500) V.TAG FROM dbo.CLEAR_SCADA_REALTIME_V V WHERE " .
        implode(' AND ', $where) . " ORDER BY V.TAG",
        $params
    );
    if (!$tagRows && $db->error()) {
        scada_api_out(['ok'=>false, 'error'=>'No se pudo cargar el filtro TAG. '.$db->error()], 500);
    }

    $tags = [];
    foreach ($tagRows as $row) {
        $tagValue = clear_scada_normalize_tag(clear_scada_row_value($row, 'TAG', ''));
        if ($tagValue !== '') $tags[] = $tagValue;
    }
    scada_api_out([
        'ok'=>true,
        'tags'=>array_values(array_unique($tags)),
        'truncated'=>count($tags) >= 2500,
    ]);
}

if ($action === 'resolve_pi') {
    $tag = clear_scada_normalize_tag($_GET['tag'] ?? '');
    if ($tag === '') scada_api_out(['ok'=>false, 'error'=>'El TAG es obligatorio.'], 400);
    $point = clear_scada_pi_stage_point($db, $tag);
    if (!$point || empty($point['WebId'])) {
        scada_api_out(['ok'=>false, 'error'=>'El TAG no figura en dbo.PI_Points_Stage.'], 404);
    }
    scada_api_out(['ok'=>true, 'point'=>$point, 'source'=>'PI_Points_Stage']);
}

if ($action !== 'values') scada_api_out(['ok'=>false, 'error'=>'Acción no válida.'], 400);

$zone = clear_scada_clean($_GET['zone'] ?? '', 128);
$battery = clear_scada_clean($_GET['battery'] ?? '', 128);
$node = clear_scada_clean($_GET['node'] ?? '', 128);
$area = clear_scada_clean($_GET['area'] ?? '', 255);
$quality = clear_scada_clean($_GET['quality'] ?? '', 64);
$tag = clear_scada_normalize_tag($_GET['tag'] ?? '');
$search = clear_scada_clean($_GET['search'] ?? '', 120);
$limit = max(50, min(1000, (int)($_GET['limit'] ?? 500)));

$where = [];
$params = [];
$addExact = function ($column, $value) use (&$where, &$params) {
    if ($value === '') return;
    $where[] = 'V.[' . $column . ']=?';
    $params[] = $value;
};
$addExact('ZONA', $zone);
$addExact('BATERIA', $battery);
$addExact('NODO', $node);
$addExact('AREA_EQUIPO', $area);
$addExact('CALIDAD', $quality);
$addExact('TAG', $tag);
if ($search !== '') {
    $where[] = "(V.TAG LIKE ? OR V.DESCRIPCION LIKE ? OR V.ITEM_VALOR LIKE ?)";
    $needle = '%' . $search . '%';
    $params[] = $needle;
    $params[] = $needle;
    $params[] = $needle;
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$isGood = "((UPPER(ISNULL(V.CALIDAD,N'')) LIKE N'%GOOD%' OR UPPER(ISNULL(V.CALIDAD,N'')) IN(N'OK',N'BUENA')) " .
          "AND V.FECHA_OPC IS NOT NULL AND DATEDIFF(second,V.FECHA_OPC,SYSDATETIME())<=?)";
$isStale = "(V.FECHA_OPC IS NULL OR DATEDIFF(second,V.FECHA_OPC,SYSDATETIME())>?)";
$sqlParams = [$staleSeconds, $staleSeconds];
foreach ($params as $param) $sqlParams[] = $param;

$sql = "SELECT TOP ($limit) V.NODO,V.TAG,V.DESCRIPCION,V.ITEM_VALOR,V.VALOR_NUMERICO,V.VALOR_TEXTO," .
       "V.VALOR_MOSTRAR,V.CALIDAD,V.FECHA_OPC,V.FECHA_ACTUALIZACION,V.BATERIA,V.ZONA,V.AREA_EQUIPO,V.UNIDAD," .
       "V.PI_POINT,V.PI_WEBID,V.PI_VISION_URL,V.ALARM_TAG,ISNULL(A.CANTIDAD,0) AS ALARM_COUNT," .
       "A.ULTIMA_ALARMA,A.FECHA_CACHE AS ALARM_CACHE_AT,H.IS_GOOD,H.IS_STALE," .
       "COUNT_BIG(*) OVER() AS TOTAL_FILTERED,SUM(H.IS_GOOD) OVER() AS GOOD_TOTAL," .
       "SUM(CONVERT(bigint,ISNULL(A.CANTIDAD,0))) OVER() AS ALARM_TOTAL," .
       "SUM(CASE WHEN H.IS_STALE=1 OR H.IS_GOOD=0 THEN 1 ELSE 0 END) OVER() AS WITHOUT_COMM_TOTAL " .
       "FROM dbo.CLEAR_SCADA_REALTIME_V V " .
       "LEFT JOIN dbo.CLEAR_SCADA_ALARM_CACHE A ON A.TAG=V.TAG " .
       "CROSS APPLY(SELECT CASE WHEN $isGood THEN 1 ELSE 0 END AS IS_GOOD," .
       "CASE WHEN $isStale THEN 1 ELSE 0 END AS IS_STALE)H" . $whereSql .
       " ORDER BY V.TAG";

$rows = $db->all($sql, $sqlParams);
if (!$rows && $db->error()) scada_api_out(['ok'=>false, 'error'=>'No se pudieron leer los valores SCADA. '.$db->error()], 500);

$items = [];
$goodCount = 0;
$staleCount = 0;
$alarmTotal = 0;
$latest = '';
$totalFiltered = 0;
foreach ($rows as $row) {
    $isGoodValue = (int)clear_scada_row_value($row, 'IS_GOOD', 0);
    $isStaleValue = (int)clear_scada_row_value($row, 'IS_STALE', 0);
    if (!$items) {
        $goodCount = (int)clear_scada_row_value($row, 'GOOD_TOTAL', 0);
        $staleCount = (int)clear_scada_row_value($row, 'WITHOUT_COMM_TOTAL', 0);
        $alarmTotal = (int)clear_scada_row_value($row, 'ALARM_TOTAL', 0);
    }
    $date = scada_api_datetime(clear_scada_row_value($row, 'FECHA_OPC', ''));
    if ($date !== '' && ($latest === '' || strcmp($date, $latest) > 0)) $latest = $date;
    $totalFiltered = (int)clear_scada_row_value($row, 'TOTAL_FILTERED', count($rows));
    $items[] = [
        'node'=>(string)clear_scada_row_value($row, 'NODO', ''),
        'tag'=>clear_scada_normalize_tag(clear_scada_row_value($row, 'TAG', '')),
        'description'=>(string)clear_scada_row_value($row, 'DESCRIPCION', ''),
        'item_value'=>(string)clear_scada_row_value($row, 'ITEM_VALOR', ''),
        'numeric_value'=>clear_scada_row_value($row, 'VALOR_NUMERICO', null) === '' ? null : (float)clear_scada_row_value($row, 'VALOR_NUMERICO', 0),
        'text_value'=>(string)clear_scada_row_value($row, 'VALOR_TEXTO', ''),
        'value'=>(string)clear_scada_row_value($row, 'VALOR_MOSTRAR', ''),
        'quality'=>(string)clear_scada_row_value($row, 'CALIDAD', ''),
        'opc_time'=>$date,
        'updated_at'=>scada_api_datetime(clear_scada_row_value($row, 'FECHA_ACTUALIZACION', '')),
        'battery'=>(string)clear_scada_row_value($row, 'BATERIA', ''),
        'zone'=>(string)clear_scada_row_value($row, 'ZONA', ''),
        'area'=>(string)clear_scada_row_value($row, 'AREA_EQUIPO', ''),
        'unit'=>(string)clear_scada_row_value($row, 'UNIDAD', ''),
        'pi_point'=>clear_scada_normalize_tag(clear_scada_row_value($row, 'PI_POINT', ''), 512),
        'pi_webid'=>clear_scada_normalize_tag(clear_scada_row_value($row, 'PI_WEBID', ''), 512),
        'pi_vision_url'=>(string)clear_scada_row_value($row, 'PI_VISION_URL', ''),
        'alarm_tag'=>(string)clear_scada_row_value($row, 'ALARM_TAG', ''),
        'alarm_count'=>(int)clear_scada_row_value($row, 'ALARM_COUNT', 0),
        'last_alarm'=>scada_api_datetime(clear_scada_row_value($row, 'ULTIMA_ALARMA', '')),
        'alarm_cache_at'=>scada_api_datetime(clear_scada_row_value($row, 'ALARM_CACHE_AT', '')),
        'is_good'=>$isGoodValue === 1,
        'is_stale'=>$isStaleValue === 1,
    ];
}

scada_api_out([
    'ok'=>true,
    'items'=>$items,
    'metrics'=>[
        'total'=>$totalFiltered,
        'shown'=>count($items),
        'good'=>$goodCount,
        'without_communication'=>$staleCount,
        'alarms'=>$alarmTotal,
        'latest'=>$latest,
    ],
    'alarm_window_hours'=>(int)$settings['alarm_window_hours'],
    'server_time'=>date(DateTime::ATOM),
]);
