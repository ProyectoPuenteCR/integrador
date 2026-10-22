<?php

require_once __DIR__ . '/includes/reporting.php';
require_once __DIR__ . '/includes/ai_analysis.php';

ignore_user_abort(true);
set_time_limit(0);

ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');

report_ensure_tables();

$cfg = require __DIR__ . '/config.php';

date_default_timezone_set(
    $cfg['app']['tz'] ?? 'America/Argentina/Buenos_Aires'
);

/*
|--------------------------------------------------------------------------
| Validación de acceso
|--------------------------------------------------------------------------
| Permite:
| - ejecución desde consola;
| - ejecución HTTP con token.
|
| Este token quedó definido temporalmente dentro del archivo para validar
| el funcionamiento automático mediante IIS.
|--------------------------------------------------------------------------
*/

if (PHP_SAPI !== 'cli') {
    $token = isset($_GET['token'])
        ? trim((string) $_GET['token'])
        : '';

    $expected = 'CLEAR-SCHEDULER-2026-X8K4-P9R2-L7M5';

    if (
        $token === '' ||
        !hash_equals($expected, $token)
    ) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Acceso denegado');
    }
}

/*
|--------------------------------------------------------------------------
| Preparación de la respuesta
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

$response = [
    'ok' => true,
    'started_at' => date('Y-m-d H:i:s'),
    'processed' => 0,
    'items' => [],
    'ai' => [
        'ok' => false,
        'message' => 'No ejecutado'
    ]
];

try {
    $db = clear_db();

    if (!$db || !$db->ok()) {
        throw new RuntimeException(
            'No se pudo conectar a SQL Server: ' .
            ($db ? $db->error() : 'conexión no disponible')
        );
    }

    report_setting_save('SCHEDULER_LAST_RUN', date('Y-m-d H:i:s'), 'scheduler');

    /*
    |--------------------------------------------------------------------------
    | Obtención de programaciones pendientes
    |--------------------------------------------------------------------------
    */

    $rows = $db->all("
        SELECT *
        FROM dbo.CLEAR_REPORT_SCHEDULES
        WHERE ACTIVO = 1
          AND (
              PROXIMA_EJECUCION IS NULL
              OR PROXIMA_EJECUCION <= CONVERT(datetime2,?,126)
          )
        ORDER BY PROXIMA_EJECUCION
    ", [date('Y-m-d H:i:s')]);

    if (!is_array($rows)) {
        $rows = [];
    }

    /*
    |--------------------------------------------------------------------------
    | Ejecución de reportes
    |--------------------------------------------------------------------------
    */

    foreach ($rows as $r) {
        $reportId = $r['ID'] ?? null;
        $reportName = $r['NOMBRE'] ?? ('Reporte ' . $reportId);

        $item = [
            'id' => $reportId,
            'name' => $reportName,
            'ok' => false,
            'message' => '',
            'next' => null
        ];

        try {
            $claimed = report_schedule_claim($db, $reportId);
            if ($claimed === null) continue; // Otro ejecutor o una edición ganó la reserva.
            $next = $claimed['RESERVED_NEXT'];
            $item['next'] = $next;

            // La reserva queda persistida ANTES de generar/enviar: una caída o
            // un error al guardar el resultado no debe reenviar el mismo turno.
            try {
                [$ok, $msg] = report_execute($claimed, 'scheduler');
            } catch (Throwable $e) {
                $ok = false;
                $msg = $e->getMessage();
            }

            $updated = $db->execute("
                UPDATE dbo.CLEAR_REPORT_SCHEDULES
                SET ULTIMA_EJECUCION=SYSDATETIME(), ULTIMO_ESTADO=?, ULTIMO_ERROR=?
                WHERE ID=? AND PROXIMA_EJECUCION=CONVERT(datetime2,?,126)
            ", [$ok ? 'ENVIADO' : 'ERROR', $ok ? '' : (string)$msg, $reportId, $next]);

            $item['ok'] = (bool)$ok;
            $item['message'] = (string)$msg;
            $item['schedule_updated'] = (bool)$updated;
            if (!$updated) $item['update_error'] = $db->error();
        } catch (Throwable $e) {
            $item['message'] = $e->getMessage();
        }
        $response['items'][] = $item;
        $response['processed']++;
    }

    /*
    |--------------------------------------------------------------------------
    | Diagnóstico de inteligencia artificial
    |--------------------------------------------------------------------------
    */

    try {
        [$aiOk, $aiMsg] = ai_analysis_run_if_due();

        $response['ai'] = [
            'ok' => (bool) $aiOk,
            'message' => (string) $aiMsg
        ];
    } catch (Throwable $e) {
        $response['ai'] = [
            'ok' => false,
            'message' => $e->getMessage()
        ];
    }

    $response['finished_at'] = date('Y-m-d H:i:s');
} catch (Throwable $e) {
    http_response_code(500);

    $response['ok'] = false;
    $response['error'] = $e->getMessage();
    $response['finished_at'] = date('Y-m-d H:i:s');
}

echo json_encode(
    $response,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_PRETTY_PRINT
);
