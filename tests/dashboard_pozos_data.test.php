<?php
require_once dirname(__DIR__) . '/includes/dashboard_pozos_data.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');
$assertions = 0;
function dpd_test_equal($actual, $expected, $message)
{
    global $assertions;
    $assertions++;
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . var_export($actual, true) . ' != ' . var_export($expected, true));
}

foreach (['SIN FALLA','NO ERROR','SIN ALARMA','SIN FALLAS','NO ERRORES'] as $value) dpd_test_equal(dpd_state($value), 'Desconocido', 'Negación sin estado operativo');
dpd_test_equal(dpd_state('Sin alarma; en marcha'), 'En marcha', 'Conservar el estado positivo');
dpd_test_equal(dpd_state('Sin alarma; falla del motor'), 'En falla', 'La negación no elimina otra falla');
dpd_test_equal(dpd_state('PARADO'), 'Parado', 'Parado no es falla');
dpd_test_equal(dpd_state('FAULT MOTOR'), 'En falla', 'Falla en inglés');
dpd_test_equal(dpd_comm('UNKNOWN'), 'Sin dato', 'Texto desconocido no significa comunicar');
dpd_test_equal(dpd_comm('BROKEN'), 'Sin dato', 'OK parcial no significa comunicar');
dpd_test_equal(dpd_comm('0'), 'Sin comunicación', 'Cero exacto');
dpd_test_equal(dpd_comm('1'), 'Comunicando', 'Uno exacto');
dpd_test_equal(dpd_comm('INTERMITENTE'), 'Intermitente', 'Intermitencia separada');
dpd_test_equal(dpd_well_key('YPF.SC.CG-001'), 'CG001', 'Clave pozo común y ceros');
dpd_test_equal(dpd_well_key('CG 001'), 'CG001', 'Variante misma clave');
dpd_test_equal(dpd_well_key(str_repeat('A', 99) . '中B'), str_repeat('A', 99) . '中', 'Clave100 sin cortar caracteres UTF-8');
dpd_test_equal(dpd_method('Bombeo mecánico'), 'BM', 'BM por nombre');
dpd_test_equal(dpd_method('Bombeo electrosumergible'), 'BES', 'BES por nombre');
dpd_test_equal(dpd_method('Bombeo cavidades progresivas'), 'PCP', 'PCP por nombre');
dpd_test_equal(dpd_method('TECSS'), 'Otro', 'No deducir método desde TECSS');
dpd_test_equal(dpd_method('No posee'), 'Sin identificar', 'Método no informado');
$now = strtotime('2026-10-21 10:00:00');
dpd_test_equal(dpd_fresh($now + 1, $now, 30), false, 'No aceptar fechas futuras respecto del reloj SQL');
dpd_test_equal(dpd_fresh($now - 1800, $now, 30), true, 'Límite exacto de antigüedad');
dpd_test_equal(dpd_fresh($now - 1801, $now, 30), false, 'Lectura vencida');
$sample = static function ($well, $type, $comm, $state, $stamp = '2026-10-21 09:55:00') {
    return ['POZO'=>$well,'BATERIA'=>'CG 01','TIPO'=>$type,'COMUNICACION'=>$comm,'ESTADO'=>$state,'FECHA_CACHE'=>$stamp];
};
$group = dpd_group_telemetry([
    $sample('YPF.SC.CG-001', 'PCP', 'SIN COMUNICACION', 'FALLA MOTOR'),
    $sample('CG 001', 'TECSS', 'OK', 'EN MARCHA'),
    $sample('CG-002', 'BES', 'SIN COMUNICACION', 'FALLA MOTOR', '2026-10-21 08:00:00'),
    $sample('CG-002', 'TECSS', 'OK', 'SIN FALLA; EN MARCHA'),
    $sample('CG-003', 'BM', 'OK', 'FALLA MOTOR', null),
], $now, 30);
$wells = [];
foreach ($group['rows'] as $row) $wells[$row['pozo_clave']] = $row;
dpd_test_equal(count($wells), 3, 'Un pozo por clave pese a fuentes duplicadas');
dpd_test_equal($wells['CG001']['comm'], 'Comunicando', 'Una fuente comunicando mantiene comunicación global');
dpd_test_equal($wells['CG001']['state'], 'En falla', 'Falla fresca de una fuente permanece');
dpd_test_equal($wells['CG001']['scada_comm'], 'Sin comunicación', 'Estado SCADA separado');
dpd_test_equal($wells['CG001']['tecss_comm'], 'Comunicando', 'Estado TECSS separado');
dpd_test_equal($wells['CG001']['sin_com_desde'], null, 'Nunca inventar inicio desde fecha de caché');
dpd_test_equal($wells['CG002']['state'], 'En marcha', 'Falla de lectura vencida no cambia estado actual');
dpd_test_equal($wells['CG002']['scada_state'], 'Desconocido', 'Fuente vencida queda sin estado conocido');
dpd_test_equal($wells['CG003']['state'], 'Desconocido', 'Fecha ausente impide afirmar falla vigente');
dpd_test_equal($wells['CG003']['comm'], 'Sin dato', 'Fecha ausente impide afirmar comunicación');
dpd_test_equal($group['expired'], 2, 'Lecturas excluidas por antigüedad');
$recovered = dpd_group_telemetry([
    $sample('YPF.SC.CG-010', 'BM', 'SIN COMUNICACION', 'FALLA MOTOR', '2026-10-21 09:40:00'),
    $sample('CG 010', 'PCP', 'OK', 'EN MARCHA', '2026-10-21 09:55:00'),
    $sample('CG-010', 'BES', 'SIN COMUNICACION', 'FALLA MOTOR', null),
    $sample('CG010', 'PCP', 'SIN COMUNICACION', 'FALLA MOTOR', '2026-10-21 10:01:00'),
], $now, 30)['rows'][0];
dpd_test_equal($recovered['state'], 'En marcha', 'Alias viejo fallando no domina a la nueva muestra de la misma fuente');
dpd_test_equal($recovered['comm'], 'Comunicando', 'Recuperación por alias de fuente SCADA');
dpd_test_equal($recovered['date'], strtotime('2026-10-21 09:55:00'), 'Muestra futura no desplaza a la última válida');
$tied = dpd_group_telemetry([
    $sample('CG-011', 'BM', 'OK', 'EN MARCHA'),
    $sample('YPF.SC.CG011', 'PCP', 'SIN COMUNICACION', 'FALLA MOTOR')
], $now, 30)['rows'][0];
dpd_test_equal($tied['state'], 'En falla', 'Empate de muestra conserva prioridad de falla');
dpd_test_equal($tied['comm'], 'Comunicando', 'Empate de muestra conserva evidencia de comunicación');
$future = dpd_group_telemetry([
    $sample('CG-012', 'BM', 'OK', 'FALLA MOTOR', '2026-10-21 10:01:00'),
    $sample('YPF.SC.CG012', 'PCP', 'OK', 'FALLA MOTOR', null)
], $now, 30)['rows'][0];
dpd_test_equal($future['state'], 'Desconocido', 'Solo fechas futuras o nulas no prueban falla vigente');
dpd_test_equal($future['comm'], 'Sin dato', 'Solo fechas futuras o nulas no prueban comunicación');
dpd_test_equal($future['date'], null, 'Fecha futura nunca aparece como snapshot válido');
dpd_test_equal(dpd_operation(strtotime('2026-10-21 05:59:59'), '06:00')['date'], '2026-10-20', 'Antes del corte');
dpd_test_equal(dpd_operation(strtotime('2026-10-21 06:00:00'), '06:00')['date'], '2026-10-21', 'En el corte');

class DpdTestDatabase
{
    public $eventReadFails = false;
    public $eventSourceAvailable = true;
    public $eventRows = [];
    public $zafiroRows = [['POZO'=>'YPF.SC.CG-001','METODO'=>'PCP']];
    public $tecssRows = [];
    public function ok() { return true; }
    public function scalar($sql) { return '2026-10-21 10:00:00'; }
    public function all($sql, $params = [])
    {
        if (preg_match('/RTQP|OPENQUERY|FIXALARMS|Q164/i', $sql)) throw new RuntimeException('Se consultó una fuente no permitida para la web');
        if (strpos($sql, 'FROM sys.columns') !== false) {
            $objects = [
                'dbo.CLEAR_DASH_POZOS_CONFIG'=>['ID','HORA_CORTE','RETENCION_DIAS','MAX_ANTIGUEDAD_MIN'],
                'dbo.CLEAR_DASH_POZOS_ACTUAL'=>['POZO_CLAVE'],
                'dbo.CLEAR_DASH_POZOS_DIARIO'=>['DIA_OPERATIVO'],
                'dbo.CLEAR_DASH_POZOS_FALLAS_DIA'=>['POZO_CLAVE'],
                'dbo.CLEAR_DASH_POZOS_ESTADO_FUENTES'=>['ID'],
                'dbo.CLEAR_API_Q158_POZOS'=>['Pozo','METODO','FechaCarga','CacheId'],
                'dbo.CLEAR_TECSS_POZOS_CACHE'=>['Pozo','tipo'],
                'dbo.CLEAR_SUPERVISORES_INSTALACIONES'=>['BATERIA','SUPERVISOR','ACTIVO'],
                'CLEAR.ZONAS'=>['BATERIA_POZOS','ZONA']
            ];
            $rows = [];
            foreach ($objects as $object => $columns) {
                list($schema, $name) = explode('.', $object, 2);
                foreach ($columns as $column) $rows[] = ['SCHEMA_NAME'=>$schema,'OBJECT_NAME'=>$name,'COLUMN_NAME'=>$column];
            }
            return $rows;
        }
        if (strpos($sql, 'FROM dbo.CLEAR_DASH_POZOS_CONFIG') !== false) return [['HORA_CORTE'=>'06:00:00','RETENCION_DIAS'=>400,'MAX_ANTIGUEDAD_MIN'=>30]];
        if (strpos($sql, 'FROM dbo.CLEAR_DASH_POZOS_ACTUAL') !== false) return [[
            'POZO_CLAVE'=>'CG001','POZO'=>'CG-001','BATERIA'=>'CG 01','TIPO'=>'BM',
            'EN_SCADA'=>1,'EN_TECSS'=>0,'COMUNICACION'=>'Sin comunicación','ESTADO'=>'En falla',
            'SCADA_COMUNICACION'=>'Sin comunicación','SCADA_ESTADO'=>'En falla',
            'TECSS_COMUNICACION'=>null,'TECSS_ESTADO'=>null,'DETALLE_ESTADO'=>'Falla motor',
            'FECHA_CACHE'=>'2026-10-21 09:55:00','SIN_COM_DESDE'=>'2026-10-19 08:00:00','SIN_COM_INICIO_ESTIMADO'=>1
        ]];
        if (strpos($sql, 'FROM dbo.CLEAR_DASH_POZOS_DIARIO') !== false) return [];
        if (strpos($sql, 'FROM [CLEAR].[ZONAS]') !== false) return [['BATERIA_POZOS'=>'CG01','ZONA'=>'LHCG']];
        if (strpos($sql, 'FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES') !== false) return [['BATERIA'=>'CG-01','SUPERVISOR'=>'Supervisor de prueba']];
        if (strpos($sql, 'FROM dbo.CLEAR_API_Q158_POZOS') !== false) return $this->zafiroRows;
        if (strpos($sql, 'FROM dbo.CLEAR_TECSS_POZOS_CACHE') !== false) return $this->tecssRows;
        if (strpos($sql, 'FROM dbo.CLEAR_DASH_POZOS_ESTADO_FUENTES') !== false) return [[
            'FECHA_TELEMETRIA'=>'2026-10-21 09:55:00','FECHA_FALLAS'=>'2026-10-21 09:58:00',
            'FALLAS_DISPONIBLES'=>$this->eventSourceAvailable ? 1 : 0,'DIA_OPERATIVO_FALLAS'=>'2026-10-21','MENSAJE'=>''
        ]];
        if (strpos($sql, 'FROM dbo.CLEAR_DASH_POZOS_FALLAS_DIA') !== false) {
            if ($this->eventReadFails) return [];
            return array_merge($this->eventRows, [['POZO_CLAVE'=>null,'DETALLE_FALLA'=>null,'CANT_FALLAS'=>0,'ULTIMA_FALLA'=>null]]);
        }
        throw new RuntimeException('Consulta inesperada: ' . $sql);
    }
}
$db = new DpdTestDatabase();
$data = dp_data_load($db);
dpd_test_equal($data['ready'], true, 'Nueva caché instalada');
dpd_test_equal($data['operation']['date'], '2026-10-21', 'Día operativo SQL');
dpd_test_equal($data['rows'][0]['metodo'], 'PCP', 'Método real desde Q158');
dpd_test_equal($data['rows'][0]['zona'], 'LHCG', 'Zona por batería');
dpd_test_equal($data['rows'][0]['supervisor'], 'Supervisor de prueba', 'Supervisor por maestro');
dpd_test_equal($data['rows'][0]['fallas_dia'], 0, 'Cero solo con lectura confirmada');
dpd_test_equal($data['rows'][0]['sin_com_desde'], strtotime('2026-10-19 08:00:00'), 'Inicio preservado');
dpd_test_equal($data['rows'][0]['sin_com_estimado'], true, 'Marca de inicio mínimo');
$db->eventRows = [
    ['POZO_CLAVE'=>'CG001','DETALLE_FALLA'=>'Motor','CANT_FALLAS'=>3],
    ['POZO_CLAVE'=>'CG001','DETALLE_FALLA'=>'Variador','CANT_FALLAS'=>2]
];
$data = dp_data_load($db);
dpd_test_equal($data['rows'][0]['fallas_dia'], 5, 'Eventos de varios tags sin duplicar pozo');
dpd_test_equal($data['rows'][0]['detalle_falla'], 'Motor · Variador', 'Detalle de ambas fallas');
$db->eventReadFails = true;
$data = dp_data_load($db);
dpd_test_equal($data['failure_counts_ready'], false, 'Fallo de consulta invalida el conteo');
dpd_test_equal($data['rows'][0]['fallas_dia'], null, 'Error SQL no se convierte en cero');
$db->eventReadFails = false;
$db->eventSourceAvailable = false;
$data = dp_data_load($db);
dpd_test_equal($data['rows'][0]['fallas_dia'], null, 'Fuente no disponible no produce cero');
$db->zafiroRows = [];
$data = dp_data_load($db);
dpd_test_equal($data['rows'][0]['metodo'], 'Sin identificar', 'Origen BM no implica método BM');
$db->tecssRows = [['Pozo'=>'CG-001','tipo'=>'BES']];
$data = dp_data_load($db);
dpd_test_equal($data['rows'][0]['metodo'], 'BES', 'Tipo explícito de extracción en caché TECSS');
$db->tecssRows[] = ['Pozo'=>'YPF.SC.CG-001','tipo'=>'BM'];
$data = dp_data_load($db);
dpd_test_equal($data['rows'][0]['metodo'], 'Sin identificar', 'Métodos TECSS en conflicto no se asignan arbitrariamente');
echo 'OK: ' . $assertions . " verificaciones de datos de pozos.\n";
