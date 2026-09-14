<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/tecss_vibraciones_query.php';
require_once __DIR__ . '/includes/alarm_event_comments.php';

auth_require();
permissions_require_menu('tecss_vibraciones');
$db = clear_db();
if (!$db->ok() || !tecss_vib_tables_ready($db)) {
    http_response_code(503);
    exit('El módulo de vibraciones TECSS no está disponible.');
}

$filters = tecss_vib_filters_from_request();
list($whereSql, $params) = tecss_vib_build_where($filters);
$rows = $db->all(
    "SELECT TOP (5000) POZO,BATERIA,ESTADO_ETIQUETA,FECHA_ULTIMO_DATO,VIBR_ACTUAL," .
    "GPM_ACTUAL,VIBR_MEDIANA,VIBR_P25,VIBR_P75,UMBRAL_ALERTA,UMBRAL_CRITICO," .
    "FRECUENCIA_MEDIANA_MIN,N_REGISTROS,ALERTAS_48H,EXCESOS_48H,SCORE_SEVERIDAD," .
    "EXCESO_PROMEDIO_PCT,EXCESO_MAXIMO_PCT,ULTIMA_ALERTA,ULTIMA_CRITICA,FECHA_ANALISIS " .
    "FROM dbo.CLEAR_TECSS_VIBRACIONES_ESTADO" . $whereSql .
    " ORDER BY " . tecss_vib_state_priority_sql() . ",SCORE_SEVERIDAD DESC,POZO",
    $params
);

$commentMap = [];
if (permissions_can('comments.view') && $rows) {
    $subjects = [];
    foreach ($rows as $row) {
        $well = tecss_vib_text($row['POZO'] ?? '');
        if ($well !== '') $subjects[] = clear_alarm_comment_subject($well, 'pozo');
    }
    foreach (array_chunk(array_values(array_unique($subjects)), 1000) as $chunk) {
        foreach (clear_alarm_comments_load_subjects_sql($chunk) as $key => $commentRow) $commentMap[$key] = $commentRow;
    }
}

function tecss_vib_export_safe($value)
{
    $value = (string)$value;
    if (preg_match('/^[=+\-@]/', $value)) $value = "'" . $value;
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$columns = [
    'POZO','BATERIA','ESTADO','ULTIMO DATO','VIBR ACTUAL','GPM ACTUAL','MEDIANA','P25','P75',
    'UMBRAL ALERTA','UMBRAL CRITICO','FRECUENCIA MIN','REGISTROS','ALERTAS 48H',
    'EXCESOS 48H','SEVERIDAD','EXCESO PROMEDIO %','EXCESO MAXIMO %','ULTIMA ALERTA',
    'ULTIMA CRITICA','FECHA ANALISIS','COMENTARIO'
];
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="Vibraciones_TECSS_' . date('Ymd_His') . '.xls"');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo "\xEF\xBB\xBF";
echo '<html><head><meta charset="UTF-8"><style>table{border-collapse:collapse;font-family:Arial;font-size:10pt}th{background:#174f5e;color:#fff}th,td{border:1px solid #b9c8ce;padding:5px;text-align:left;vertical-align:top}</style></head><body><h2>CLEAR · Análisis de Vibraciones TECSS</h2><table><thead><tr>';
foreach ($columns as $column) echo '<th>' . tecss_vib_export_safe($column) . '</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $row) {
    $well = tecss_vib_text($row['POZO'] ?? '');
    $subject = clear_alarm_comment_subject($well, 'pozo');
    $commentRow = $subject !== '' ? ($commentMap[strtoupper($subject)] ?? []) : [];
    $comment = trim((string)($commentRow['COMENTARIO'] ?? $commentRow['comentario'] ?? ''));
    $values = [
        $well, $row['BATERIA'] ?? '', $row['ESTADO_ETIQUETA'] ?? '', tecss_vib_date($row['FECHA_ULTIMO_DATO'] ?? null),
        $row['VIBR_ACTUAL'] ?? '', $row['GPM_ACTUAL'] ?? '', $row['VIBR_MEDIANA'] ?? '', $row['VIBR_P25'] ?? '', $row['VIBR_P75'] ?? '',
        $row['UMBRAL_ALERTA'] ?? '', $row['UMBRAL_CRITICO'] ?? '', $row['FRECUENCIA_MEDIANA_MIN'] ?? '', $row['N_REGISTROS'] ?? '',
        $row['ALERTAS_48H'] ?? '', $row['EXCESOS_48H'] ?? '', $row['SCORE_SEVERIDAD'] ?? '', $row['EXCESO_PROMEDIO_PCT'] ?? '',
        $row['EXCESO_MAXIMO_PCT'] ?? '', tecss_vib_date($row['ULTIMA_ALERTA'] ?? null), tecss_vib_date($row['ULTIMA_CRITICA'] ?? null),
        tecss_vib_date($row['FECHA_ANALISIS'] ?? null), $comment
    ];
    echo '<tr>';
    foreach ($values as $value) echo '<td style="mso-number-format:\'\\@\'">' . tecss_vib_export_safe($value) . '</td>';
    echo '</tr>';
}
echo '</tbody></table></body></html>';
exit;
