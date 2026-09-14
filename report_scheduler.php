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
              OR PROXIMA_EJECUCION <= SYSDATETIME()
          )
        ORDER BY PROXIMA_EJECUCION
    ");

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
            [$ok, $msg] = report_execute($r, 'scheduler');

            $nextRun = report_next_run($r);
            $next = $nextRun->format('Y-m-d H:i:s');

            $updated = $db->execute("
                UPDATE dbo.CLEAR_REPORT_SCHEDULES
                SET
                    ULTIMA_EJECUCION = SYSDATETIME(),
                    ULTIMO_ESTADO = ?,
                    ULTIMO_ERROR = ?,
                    PROXIMA_EJECUCION = ?
                WHERE ID = ?
            ", [
                $ok ? 'ENVIADO' : 'ERROR',
                $ok ? '' : (string) $msg,
                $next,
                $reportId
            ]);

            $item['ok'] = (bool) $ok;
            $item['message'] = (string) $msg;
            $item['next'] = $next;
            $item['schedule_updated'] = (bool) $updated;
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();

            $item['ok'] = false;
            $item['message'] = $errorMessage;

            try {
                $nextRun = report_next_run($r);
                $next = $nextRun->format('Y-m-d H:i:s');

                $db->execute("
                    UPDATE dbo.CLEAR_REPORT_SCHEDULES
                    SET
                        ULTIMA_EJECUCION = SYSDATETIME(),
                        ULTIMO_ESTADO = 'ERROR',
                        ULTIMO_ERROR = ?,
                        PROXIMA_EJECUCION = ?
                    WHERE ID = ?
                ", [
                    $errorMessage,
                    $next,
                    $reportId
                ]);

                $item['next'] = $next;
            } catch (Throwable $updateException) {
                $item['update_error'] = $updateException->getMessage();
            }
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