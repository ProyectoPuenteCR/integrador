<?php
$TELEMETRY = [
    'key' => 'telemetria_bes',
    'collapsible_toolbar' => true,
    'title' => 'Telemetría BES',
    'subtitle' => 'Monitoreo de bombeo electrosumergible',
    'table' => 'BES_RTQP',
    'order' => 'POZO',
    'columns' => [
        'POZO','BATERIA','PANTALLA','FEHA','ESTADO','YT:SISTEMA','YAT:COM','VT-Z','N-FP:MOTOR',
        'PID:PT-FONDO','N-IT:LC','N-FQ:MOTOR','N-IT:DH','N-ET:OUT','N-ET:MOTOR','PAL:INTAKE',
        'PT:CABEZA','PT:CONSUMO','PT:FONDO','PT:LINEA','RUN:BQ','TT:FONDO','TT:MOTOR','AF:RUN:BQ',
        'YT:MOD-CONTROL','N-IT:MOTOR','N-IT:MOTOR-AVG','N-IT:MOTOR-MARCHA','N-IT:MOTOR-RATIO'
    ],
    'well_column' => 'POZO',
    'battery_column' => 'BATERIA',
    'state_column' => 'ESTADO',
    'communication_column' => 'YAT:COM',
    'communication_mode' => 'raw',
    'production_q164' => true,
    'remote_stop' => true,
    'remote_stop_after' => 'ESTADO',
    'column_state_version' => '340',
    'filter_columns' => ['BATERIA','ESTADO','YT:SISTEMA','YAT:COM'],
    'labels' => [
        'FEHA' => 'ÚLTIMA ACTUALIZACIÓN',
        '__COMUNICACION' => 'FALLA DE COMUNICACIÓN',
        'N-IT:DH' => 'Corriente VSD',
        'N-FP:MOTOR' => 'Factor de potencia',
        'N-FQ:MOTOR' => 'Frecuencia del motor',
        'VT-Z' => 'Vibración del eje Z',
        'N-ET:OUT' => 'Tensión de salida',
        'N-ET:MOTOR' => 'Tensión de Motor',
        'N-IT:MOTOR' => 'Corriente Motor',
        'N-IT:LC' => 'Corriente de Pérdida',
        'PRODUCCION_PETROLEO' => 'PRODUCCIÓN PETRÓLEO',
        'PRODUCCION_LIQUIDO' => 'PRODUCCIÓN LÍQUIDO',
        'PRODUCCION_GAS' => 'PRODUCCIÓN GAS'
    ],
    'filter_labels' => [
        'YT:SISTEMA' => 'Estado del sistema',
        'YAT:COM' => 'Falla de comunicación'
    ],
    'non_numeric' => [
        'POZO','BATERIA','PANTALLA','FEHA','ESTADO','YT:SISTEMA','YAT:COM','RUN:BQ','AF:RUN:BQ','YT:MOD-CONTROL'
    ]
];
// La extensión se carga sólo en BES, sobre la grilla instalada.
ob_start();
require __DIR__ . '/includes/telemetry_grid.php';
$besHtml=ob_get_clean();
$besConfig=json_encode(['wellHistory'=>permissions_can_menu('pi_historico'),'wellHistoryReport'=>$reportEnabled,'wellCommentView'=>permissions_can('comments.view'),'wellAlarms'=>(permissions_can_menu('pozos_alarmas24')||permissions_can_menu('pozos_todas_alarmas')),'wellAlarmsHistory'=>permissions_can_menu('pozos_todas_alarmas')],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$besHead='<link rel="stylesheet" href="assets/css/bes_details.css?v=20261103-1"><link rel="stylesheet" href="assets/css/bes_history.css?v=20261103-1">';
$besScripts='<script>Object.assign(window.CLEAR_TELEMETRY_GRID,'.$besConfig.');</script><script src="assets/js/chart.umd.js"></script><script src="assets/js/chartjs-plugin-zoom.min.js?v=2.0.1"></script><script src="assets/js/bes_history.js?v=20261103-1"></script><script src="assets/js/bes_activity.js?v=20261103-1"></script><script src="assets/js/bes_details.js?v=20261103-1"></script>';
echo str_replace(['</head>','</body>'],[$besHead.'</head>',$besScripts.'</body>'],$besHtml);

