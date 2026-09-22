<?php
$TELEMETRY = [
    'key' => 'telemetria_general',
    'title' => 'Grilla general de pozos',
    'subtitle' => 'Vista unificada de pozos con recepción SCADA y/o TECSS · actualización SQL cada 10 minutos',
    'table' => 'TELEMETRIA_POZOS_GENERAL_CACHE',
    'alarm_column' => 'ALM',
    'order' => 'POZO',
    'columns' => [
        'POZO',
        'BATERIA',
        'TIPO',
        'ALM',
        'COMUNICACION',
        'ESTADO',
        'PANTALLA',
        'FECHA_CACHE'
    ],
    'display_order' => [
        'POZO',
        'BATERIA',
        'TIPO',
        'ALM',
        '__COMUNICACION',
        'ESTADO',
        'AF-ESTADO-PARO-REMOTO',
        'AF-TIPO-DESC',
        'LINEA_ELECTRICA',
        'PANTALLA',
        'FECHA_CACHE'
    ],
    'well_column' => 'POZO',
    'battery_column' => 'BATERIA',
    'state_column' => 'ESTADO',
    'communication_column' => 'COMUNICACION',
    'communication_mode' => 'raw',
    'production_q164' => true,
    'remote_stop' => true,
    'remote_stop_after' => 'ESTADO',
    'remote_stop_extra_columns' => ['AF-TIPO-DESC','LINEA_ELECTRICA'],
    'persist_filters' => true,
    'select_filter_columns' => ['AF-TIPO-DESC','LINEA_ELECTRICA'],
    'column_state_version' => '343',
    'filter_columns' => ['BATERIA','COMUNICACION','ESTADO'],
    'server_select_filters' => [
        'TIPO' => [
            'param' => 'tipo',
            'label' => 'Tipo de telemetría',
            'values' => ['SCADA','TECSS'],
            'default' => 'TODOS',
            /* El valor histórico TECCS también corresponde a TECSS. */
            'display_value_map' => [
                'TECSS' => 'TECSS',
                'TECCS' => 'TECSS'
            ],
            /* BM, PCP, BES y cualquier otro origen no TECSS llegan por SCADA. */
            'display_default_value' => 'SCADA',
            'source_value_groups' => [
                'SCADA' => [
                    'exclude' => ['TECSS','TECCS']
                ],
                'TECSS' => [
                    'include' => ['TECSS','TECCS']
                ]
            ]
        ]
    ],
    'labels' => [
        'TIPO' => 'TELEMETRÍA',
        'AF-TIPO-DESC' => 'TIPO',
        'LINEA_ELECTRICA' => 'LÍNEA ELÉCTRICA',
        '__COMUNICACION' => 'COM',
        'FECHA_CACHE' => 'ACTUALIZACIÓN DE GRILLA',
        'PRODUCCION_PETROLEO' => 'PRODUCCIÓN PETRÓLEO',
        'PRODUCCION_LIQUIDO' => 'PRODUCCIÓN LÍQUIDO',
        'PRODUCCION_GAS' => 'PRODUCCIÓN GAS'
    ],
    'filter_labels' => [
        'TIPO' => 'Tipo de telemetría',
        'COMUNICACION' => 'Comunicación'
    ],
    'non_numeric' => [
        'POZO','BATERIA','TIPO','COMUNICACION','ESTADO','AF-ESTADO-PARO-REMOTO',
        'AF-TIPO-DESC','LINEA_ELECTRICA','PANTALLA','FECHA_CACHE','ALM'
    ]
];
require __DIR__ . '/includes/telemetry_grid.php';
