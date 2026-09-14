<?php
$TELEMETRY = [
    'key' => 'telemetria_bes',
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
require __DIR__ . '/includes/telemetry_grid.php';
