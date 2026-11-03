<?php
$TELEMETRY = [
    'key' => 'telemetria_pcp',
    'collapsible_toolbar' => true,
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
// La extensión se carga sólo en PCP, sobre la grilla instalada.
ob_start();
require __DIR__ . '/includes/telemetry_grid.php';
$pcpHtml=ob_get_clean();
$pcpConfig=json_encode(['wellHistory'=>permissions_can_menu('pi_historico'),'wellHistoryReport'=>$reportEnabled,'wellCommentView'=>permissions_can('comments.view'),'wellAlarms'=>(permissions_can_menu('pozos_alarmas24')||permissions_can_menu('pozos_todas_alarmas')),'wellAlarmsHistory'=>permissions_can_menu('pozos_todas_alarmas')],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$pcpHead='<link rel="stylesheet" href="assets/css/pcp_details.css?v=20261103-1"><link rel="stylesheet" href="assets/css/pcp_history.css?v=20261103-1">';
$pcpScripts='<script>Object.assign(window.CLEAR_TELEMETRY_GRID,'.$pcpConfig.');</script><script src="assets/js/chart.umd.js"></script><script src="assets/js/chartjs-plugin-zoom.min.js?v=2.0.1"></script><script src="assets/js/pcp_history.js?v=20261103-1"></script><script src="assets/js/pcp_activity.js?v=20261103-1"></script><script src="assets/js/pcp_details.js?v=20261103-1"></script>';
echo str_replace(['</head>','</body>'],[$pcpHead.'</head>',$pcpScripts.'</body>'],$pcpHtml);
