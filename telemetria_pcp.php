<?php
$TELEMETRY = [
    'key' => 'telemetria_pcp',
    'title' => 'Telemetría PCP',
    'subtitle' => 'Monitoreo de pozos con bombeo PCP',

    /*
     * La vista local dbo.PCP_RTQP conserva una definición anterior que solicita
     * PANTALLA. La vista RTQP unificada ya no publica ese atributo, por eso esta
     * pantalla consulta directamente el linked server sin modificar objetos SQL.
     */
    'source_sql' => <<<'SQL'
SELECT
    ID,
    Name,
    Description,
    Comment,
    PLANTILLA,
    POZO,
    BATERIA,
    [YT:POZO],
    [PT:FONDO],
    [YAT:COM]
FROM OPENQUERY([RTQP_IUP], '
    SELECT
        ID,
        Name,
        Description,
        Comment,
        PLANTILLA,
        POZO,
        BATERIA,
        [YT:POZO],
        [PT:FONDO],
        [YAT:COM]
    FROM [IUP].[pozos_IUP].[PCP]
')
SQL,
    'source_columns' => [
        'ID','Name','Description','Comment','PLANTILLA','POZO','BATERIA',
        'YT:POZO','PT:FONDO','YAT:COM'
    ],
    'table' => 'PCP_RTQP', // Solo se conserva como referencia visual.
    'order' => 'POZO',
    'columns' => [
        'POZO','BATERIA','PANTALLA','Description','PLANTILLA','YT:POZO','PT:FONDO','YAT:COM'
    ],

    /*
     * PANTALLA no forma parte de la vista PCP unificada. Se incorpora como
     * columna virtual y se completa leyendo directamente las plantillas PC12
     * y PC14. PCP TECSS no posee PANTALLA y por eso queda vacío.
     */
    'virtual_columns' => ['PANTALLA'],
    'supplemental_queries' => [
        [
            'key_column' => 'POZO',
            'value_columns' => ['PANTALLA'],
            'sql' => <<<'SQL'
SELECT POZO, PANTALLA
FROM OPENQUERY([RTQP_IUP], '
    SELECT
        v.[POZO] AS POZO,
        v.[PANTALLA] AS PANTALLA
    FROM [Master].[Element].[Element] e
    INNER JOIN [Master].[Element].[Value]
    <
        N''PC12'',
        { N''|POZO'', NULL, N''POZO'', NULL, NULL },
        { N''|PANTALLA'', NULL, N''PANTALLA'', NULL, NULL }
    > v
        ON e.ID = v.ElementID
    WHERE e.Template = N''PC12''
')
SQL
        ],
        [
            'key_column' => 'POZO',
            'value_columns' => ['PANTALLA'],
            'sql' => <<<'SQL'
SELECT POZO, PANTALLA
FROM OPENQUERY([RTQP_IUP], '
    SELECT
        v.[POZO] AS POZO,
        v.[PANTALLA] AS PANTALLA
    FROM [Master].[Element].[Element] e
    INNER JOIN [Master].[Element].[Value]
    <
        N''PC14'',
        { N''|POZO'', NULL, N''POZO'', NULL, NULL },
        { N''|PANTALLA'', NULL, N''PANTALLA'', NULL, NULL }
    > v
        ON e.ID = v.ElementID
    WHERE e.Template = N''PC14''
')
SQL
        ]
    ],
    'display_order' => [
        'POZO','BATERIA','ALM','__COMUNICACION','YT:POZO',
        'PRODUCCION_PETROLEO','PRODUCCION_LIQUIDO','PANTALLA','Description','PLANTILLA',
        'PT:FONDO','PRODUCCION_GAS'
    ],
    'well_column' => 'POZO',
    'battery_column' => 'BATERIA',
    'state_column' => 'YT:POZO',
    'communication_column' => 'YAT:COM',
    'communication_mode' => 'raw',
    'production_q164' => true,
    'remote_stop' => true,
    'remote_stop_after' => 'YT:POZO',
    'column_state_version' => '340',
    'filter_columns' => ['BATERIA','PLANTILLA','YT:POZO','YAT:COM'],
    'labels' => [
        'Description' => 'DESCRIPCIÓN',
        '__COMUNICACION' => 'FALLA DE COMUNICACIÓN',
        'PRODUCCION_PETROLEO' => 'PRODUCCIÓN PETRÓLEO',
        'PRODUCCION_LIQUIDO' => 'PRODUCCIÓN LÍQUIDO',
        'PRODUCCION_GAS' => 'PRODUCCIÓN GAS'
    ],
    'filter_labels' => [
        'PLANTILLA' => 'Plantilla PCP',
        'YT:POZO' => 'Estado del pozo',
        'YAT:COM' => 'Falla de comunicación'
    ],
    'non_numeric' => [
        'POZO','BATERIA','PANTALLA','Description','PLANTILLA','YT:POZO','YAT:COM'
    ]
];
require __DIR__ . '/includes/telemetry_grid.php';
