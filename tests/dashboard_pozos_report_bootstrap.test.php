<?php
/* Regresión de carga entre módulos reales. No abre conexiones ni ejecuta SQL.
 * Compatible con PHP 7.4; cada orden de carga corre en un proceso independiente.
 */
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

$dprtRoot = dirname(__DIR__);
$dprtChecks = 0;

function dprt_equal($actual, $expected, $message)
{
    global $dprtChecks;
    $dprtChecks++;
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': ' . var_export($actual, true));
    }
}

function dprt_well($key, $method, $count, $state = 'En falla')
{
    return [
        'pozo_clave'=>$key, 'pozo'=>'YPF.SC.' . $key, 'bateria'=>'CE 01',
        'zona'=>'CED Zona I', 'supervisor'=>'Supervisor de prueba',
        'detalle_falla'=>'Falla del variador', 'metodo'=>$method,
        'fallas_dia'=>$count, 'state'=>$state, 'date'=>strtotime('2026-10-21 11:45:00'),
    ];
}

function dprt_check_columns()
{
    dprt_equal(pf_columns(), [
        'SEMANA'=>'SEMANA', 'ZONA'=>'ZONA', 'SUPERVISOR'=>'SUPERVISOR',
        'JEFE_PRODUCCION'=>'JEFE DE PRODUCCIÓN', 'BATERIA'=>'BATERÍA',
        'POZO'=>'POZO', 'TAG'=>'TAG', 'ESTADO'=>'ESTADO',
    ], 'PUMP OFF conserva su contrato original de columnas');

    $columns = dpf_columns(dprt_well('CE001', 'BM', 0));
    dprt_equal(array_keys($columns), [
        'Zona', 'Instalación', 'Supervisor', 'Detalle de falla',
        'Cant. de fallas acumuladas durante el día operativo', 'Método de extracción',
    ], 'El reporte de fallas conserva sus seis columnas propias');
    dprt_equal($columns['Cant. de fallas acumuladas durante el día operativo'], 0,
        'Cero eventos continúa siendo un valor válido');
    $unknown = dpf_columns(dprt_well('CE002', 'BES', null));
    dprt_equal($unknown['Cant. de fallas acumuladas durante el día operativo'], 'Sin dato',
        'Un conteo desconocido no se convierte en cero');
}

function dprt_real_dashboard_imports($root)
{
    $source = file_get_contents($root . '/dashboard_pozos.php');
    $authPosition = strpos($source, 'auth_require();');
    if ($authPosition === false) throw new RuntimeException('No se encontró el límite del bootstrap del dashboard.');
    $bootstrap = substr($source, 0, $authPosition);
    preg_match_all("~require_once\\s+__DIR__\\s*\\.\\s*'([^']+)'\\s*;~", $bootstrap, $matches);
    dprt_equal(count($matches[1]) >= 7, true, 'Se cargan los imports del dashboard real');
    foreach ($matches[1] as $path) require_once $root . $path;
    // El componente puede cargarse mediante una guarda con ruta variable.
    require_once $root . '/includes/pozos_falla_report.php';
    dprt_equal(class_exists('DB', false), true, 'Se cargó la clase DB real sin instanciarla');
    dprt_equal(realpath((new ReflectionFunction('auth_require'))->getFileName()),
        realpath($root . '/includes/auth.php'), 'La autenticación no fue sustituida por un stub');
    dprt_equal(realpath((new ReflectionFunction('ns_report_row_payload'))->getFileName()),
        realpath($root . '/includes/novedades_semanales_common.php'), 'El generador de filas es el real');
    dprt_equal(function_exists('dp_data_load'), true, 'Se cargó el helper real de telemetría sin consultar datos');
    dprt_equal(function_exists('dp_kpi_comparisons'), true, 'Se cargó el helper real de KPI');
    dprt_equal(function_exists('dpf_build_report'), true, 'Se cargó el reporte con su prefijo exclusivo');
}

function dprt_check_report_payload($root)
{
    require_once $root . '/includes/novedades_semanales_common.php';
    require_once $root . '/includes/pozos_falla_report.php';
    $data = [
        'now'=>strtotime('2026-10-21 11:50:00'),
        'config'=>['hora_corte'=>'00:00:00'],
        'rows'=>[
            dprt_well('CE001', 'BM', 0),
            dprt_well('CE002', 'BES', null),
            dprt_well('CE003', 'PCP', 4, 'En marcha'),
        ],
    ];
    $model = dpf_build_report($data, [], true);
    dprt_equal($model['total'], 2, 'Solo entran los pozos actualmente en falla');
    dprt_equal($model['methodCounts'], ['BM'=>1, 'BES'=>1, 'PCP'=>0, 'Otros'=>0, 'Sin identificar'=>0],
        'Los métodos se calculan sobre el mismo conjunto');
    $items = dpf_report_items($model);
    dprt_equal(count($items), 3, 'El reporte común recibe resumen y dos pozos');
    dprt_equal($items[0]['key'], ns_report_key('pozos-falla-resumen', [$model['dayKey'], $model['params']]),
        'El resumen utiliza la identidad del generador real');
    $columns = dpf_columns($model['filtered'][0]);
    dprt_equal($items[1]['payload']['columns'], ns_report_row_payload($columns)['columns'],
        'Las filas pasan por el normalizador real del reporte común');
    dprt_equal($items[1]['payload']['columns']['Comentario'], '—',
        'Se conserva la columna fija que agrega el generador real');
    dprt_equal($items[1]['key'], ns_report_key('pozos-falla', [$model['dayKey'], 'CE001']),
        'La identidad del pozo conserva día operativo y clave');
    dprt_equal($items[2]['payload']['columns']['Cant. de fallas acumuladas durante el día operativo'], 'Sin dato',
        'El reporte común conserva el conteo desconocido');
}

if (($argv[1] ?? '') === '--scenario') {
    date_default_timezone_set('America/Argentina/Buenos_Aires');
    try {
        switch ($argv[2] ?? '') {
            case 'pumpoff_first':
                require_once $dprtRoot . '/includes/pumpoff.php';
                require_once $dprtRoot . '/includes/pozos_falla_report.php';
                dprt_check_columns();
                break;
            case 'faults_first':
                require_once $dprtRoot . '/includes/pozos_falla_report.php';
                require_once $dprtRoot . '/includes/pumpoff.php';
                dprt_check_columns();
                break;
            case 'dashboard_imports':
                dprt_real_dashboard_imports($dprtRoot);
                break;
            case 'real_report_payload':
                dprt_check_report_payload($dprtRoot);
                break;
            default:
                throw new RuntimeException('Escenario de prueba desconocido.');
        }
        echo json_encode(['checks'=>$dprtChecks], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}

$dprtScenarios = ['pumpoff_first', 'faults_first', 'dashboard_imports', 'real_report_payload'];
$dprtTotal = 0;
foreach ($dprtScenarios as $scenario) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-n', __FILE__, '--scenario', $scenario],
        [0=>['pipe', 'r'], 1=>['pipe', 'w'], 2=>['pipe', 'w']], $pipes, $dprtRoot);
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar el escenario ' . $scenario);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $result = json_decode($output, true);
    if ($status !== 0 || $errors !== '' || !is_array($result) || !isset($result['checks'])) {
        throw new RuntimeException('Falló ' . $scenario . ' (exit ' . $status . '): ' . trim($errors . "\n" . $output));
    }
    $dprtTotal += (int)$result['checks'];
}
echo 'OK: ' . count($dprtScenarios) . ' procesos aislados y ' . $dprtTotal . ' verificaciones de carga y reporte reales.' . PHP_EOL;
