<?php
$TELEMETRY = [
    'key' => 'telemetria_tecss',
    'title' => 'Telemetría TECCS',
    'subtitle' => 'Monitoreo de pozos TECCS',
    'table' => 'TECSS_RTQP',
    'order' => 'POZO',
    'columns' => [
        'POZO','BATERIA','PANTALLA','HOY','ESTADO','METODO','YAT:COM',
        'PI-005-PL','VIBRACION','SI-002-SPM','QT:GOLPES-MIN'
    ],
    'well_column' => 'POZO',
    'battery_column' => 'BATERIA',
    'state_column' => 'ESTADO',
    'communication_column' => 'YAT:COM',
    'communication_mode' => 'raw',
    'production_q164' => true,
    'column_state_version' => '332',
    'filter_columns' => ['BATERIA','ESTADO','METODO','YAT:COM'],
    'labels' => [
        'HOY' => 'ÚLTIMA ACTUALIZACIÓN',
        '__COMUNICACION' => 'FALLA DE COMUNICACIÓN',
        'PRODUCCION_PETROLEO' => 'PRODUCCIÓN PETRÓLEO',
        'PRODUCCION_LIQUIDO' => 'PRODUCCIÓN LÍQUIDO',
        'PRODUCCION_GAS' => 'PRODUCCIÓN GAS'
    ],
    'filter_labels' => [
        'YAT:COM' => 'Falla de comunicación'
    ],
    'non_numeric' => [
        'POZO','BATERIA','PANTALLA','HOY','ESTADO','METODO','YAT:COM'
    ]
];
require __DIR__ . '/includes/telemetry_grid.php';
