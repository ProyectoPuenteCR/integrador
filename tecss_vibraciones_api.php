<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/tecss_vibraciones_query.php';

auth_require();
permissions_require_menu('tecss_vibraciones');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function tecss_vib_api_reply($ok, array $payload = [], $status = 200)
{
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>$ok], $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$well = trim((string)($_GET['pozo'] ?? ''));
$fromText = trim((string)($_GET['desde'] ?? ''));
$toText = trim((string)($_GET['hasta'] ?? ''));
if ($well === '' || strlen($well) > 255) tecss_vib_api_reply(false, ['error'=>'Pozo inválido.'], 400);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromText) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toText)) {
    tecss_vib_api_reply(false, ['error'=>'Rango de fechas inválido.'], 400);
}
try {
    $from = new DateTimeImmutable($fromText . ' 00:00:00');
    $to = new DateTimeImmutable($toText . ' 00:00:00');
} catch (Throwable $error) {
    tecss_vib_api_reply(false, ['error'=>'Rango de fechas inválido.'], 400);
}
if ($from > $to) tecss_vib_api_reply(false, ['error'=>'La fecha Desde no puede ser posterior a Hasta.'], 400);
if ((int)$from->diff($to)->format('%a') > 120) {
    tecss_vib_api_reply(false, ['error'=>'El rango máximo permitido es de 120 días.'], 400);
}

$db = clear_db();
if (!$db->ok()) tecss_vib_api_reply(false, ['error'=>$db->error()], 503);
if (!tecss_vib_tables_ready($db)) {
    tecss_vib_api_reply(false, ['error'=>'El módulo de vibraciones TECSS no está instalado.'], 503);
}

$stateRows = $db->all(
    "SELECT POZO,BATERIA,ESTADO,ESTADO_ETIQUETA,VIBR_ACTUAL,GPM_ACTUAL,VIBR_MEDIANA," .
    "VIBR_P25,VIBR_P75,UMBRAL_ALERTA,UMBRAL_CRITICO,FRECUENCIA_MEDIANA_MIN," .
    "N_REGISTROS,ALERTAS_48H,EXCESOS_48H,SCORE_SEVERIDAD,EXCESO_PROMEDIO_PCT," .
    "EXCESO_MAXIMO_PCT,ULTIMA_ALERTA,ULTIMA_CRITICA,FECHA_ANALISIS " .
    "FROM dbo.CLEAR_TECSS_VIBRACIONES_ESTADO WHERE POZO=?",
    [$well]
);
if (!$stateRows) tecss_vib_api_reply(false, ['error'=>'No existe un análisis para el pozo seleccionado.'], 404);

$endExclusive = $to->modify('+1 day')->format('Y-m-d H:i:s');
$seriesRows = $db->all(
    "SELECT TOP (5000) FECHA,VIBR,GPM FROM dbo.CLEAR_TECSS_VIBRACIONES_DATO " .
    "WHERE POZO=? AND FECHA>=? AND FECHA<? ORDER BY FECHA DESC",
    [$well, $from->format('Y-m-d H:i:s'), $endExclusive]
);
if (!$seriesRows && $db->error()) tecss_vib_api_reply(false, ['error'=>$db->error()], 500);
$seriesRows = array_reverse($seriesRows);

$eventRows = $db->all(
    "SELECT TOP (200) FECHA_EVENTO,NIVEL,TIPO,VIBR_VALOR,VIBR_UMBRAL,ES_PICO,N_LECTURAS " .
    "FROM dbo.CLEAR_TECSS_VIBRACIONES_EVENTO WHERE POZO=? AND FECHA_EVENTO>=? " .
    "AND FECHA_EVENTO<? ORDER BY FECHA_EVENTO DESC",
    [$well, $from->format('Y-m-d H:i:s'), $endExclusive]
);
if (!$eventRows && $db->error()) tecss_vib_api_reply(false, ['error'=>$db->error()], 500);

$series = [];
foreach ($seriesRows as $row) {
    $vibration = str_replace(',', '.', tecss_vib_text($row['VIBR'] ?? ''));
    $gpm = str_replace(',', '.', tecss_vib_text($row['GPM'] ?? ''));
    $series[] = [
        'fecha' => tecss_vib_datetime_for_json($row['FECHA'] ?? ''),
        'vibr' => is_numeric($vibration) ? (float)$vibration : null,
        'gpm' => is_numeric($gpm) ? (float)$gpm : null,
    ];
}

$events = [];
foreach ($eventRows as $row) {
    $value = str_replace(',', '.', tecss_vib_text($row['VIBR_VALOR'] ?? ''));
    $threshold = str_replace(',', '.', tecss_vib_text($row['VIBR_UMBRAL'] ?? ''));
    $peakValue = strtolower(tecss_vib_text($row['ES_PICO'] ?? '0'));
    $events[] = [
        'fecha' => tecss_vib_datetime_for_json($row['FECHA_EVENTO'] ?? ''),
        'nivel' => tecss_vib_text($row['NIVEL'] ?? ''),
        'tipo' => tecss_vib_text($row['TIPO'] ?? ''),
        'valor' => is_numeric($value) ? (float)$value : null,
        'umbral' => is_numeric($threshold) ? (float)$threshold : null,
        'es_pico' => in_array($peakValue, ['1','-1','true'], true),
        'n_lecturas' => (int)($row['N_LECTURAS'] ?? 0),
    ];
}

$state = $stateRows[0];
$numericFields = [
    'VIBR_ACTUAL','GPM_ACTUAL','VIBR_MEDIANA','VIBR_P25','VIBR_P75','UMBRAL_ALERTA',
    'UMBRAL_CRITICO','FRECUENCIA_MEDIANA_MIN','SCORE_SEVERIDAD',
    'EXCESO_PROMEDIO_PCT','EXCESO_MAXIMO_PCT'
];
foreach ($numericFields as $field) {
    $value = str_replace(',', '.', tecss_vib_text($state[$field] ?? ''));
    $state[$field] = is_numeric($value) ? (float)$value : null;
}
foreach (['N_REGISTROS','ALERTAS_48H','EXCESOS_48H'] as $field) $state[$field] = (int)($state[$field] ?? 0);
foreach (['ULTIMA_ALERTA','ULTIMA_CRITICA','FECHA_ANALISIS'] as $field) $state[$field] = tecss_vib_datetime_for_json($state[$field] ?? '');

tecss_vib_api_reply(true, [
    'pozo' => $well,
    'state' => $state,
    'series' => $series,
    'events' => $events,
    'series_limit' => 5000,
    'event_limit' => 200,
]);
