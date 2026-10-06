<?php
/*
 * TECSS:
 * - TECSS_RTQP sigue siendo la fuente principal de la pantalla.
 * - CLEAR_TECSS_POZOS_CACHE aporta solamente los pozos que no existen en TECSS_RTQP.
 * - Si la cache todavia no fue instalada, la pagina vuelve automaticamente al
 *   comportamiento anterior y consulta solo TECSS_RTQP.
 */
require_once __DIR__ . '/includes/db.php';

$tecssDb = clear_db();
$tecssCacheReady = $tecssDb->ok()
    && (int)$tecssDb->scalar(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_TECSS_POZOS_CACHE',N'U') IS NOT NULL THEN 1 ELSE 0 END"
    ) === 1;

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
    'column_state_version' => '333',
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

/*
 * La union se habilita solamente cuando la cache local existe.
 * Se conserva completa TECSS_RTQP y se agregan exclusivamente los pozos
 * faltantes de Pozos_tecss (cache), evitando duplicados y cambios sobre los
 * 734 pozos que hoy aparecen en ambas fuentes.
 */
if ($tecssCacheReady) {
    $TELEMETRY['source_columns'] = [
        'POZO','BATERIA','PANTALLA','HOY','ESTADO','METODO','YAT:COM',
        'PI-005-PL','VIBRACION','SI-002-SPM','QT:GOLPES-MIN'
    ];

    $TELEMETRY['source_sql'] = "
        SELECT
            CONVERT(nvarchar(255),R.[POZO]) COLLATE DATABASE_DEFAULT AS [POZO],
            CONVERT(nvarchar(255),R.[BATERIA]) COLLATE DATABASE_DEFAULT AS [BATERIA],
            CONVERT(nvarchar(1000),R.[PANTALLA]) COLLATE DATABASE_DEFAULT AS [PANTALLA],
            R.[HOY],
            CONVERT(nvarchar(255),R.[ESTADO]) COLLATE DATABASE_DEFAULT AS [ESTADO],
            CONVERT(nvarchar(255),R.[METODO]) COLLATE DATABASE_DEFAULT AS [METODO],
            CONVERT(nvarchar(255),R.[YAT:COM]) COLLATE DATABASE_DEFAULT AS [YAT:COM],
            R.[PI-005-PL],
            R.[VIBRACION],
            R.[SI-002-SPM],
            R.[QT:GOLPES-MIN]
        FROM dbo.TECSS_RTQP AS R

        UNION ALL

        SELECT
            CONVERT(nvarchar(255),C.[Pozo]) COLLATE DATABASE_DEFAULT AS [POZO],
            CAST(NULL AS nvarchar(255)) COLLATE DATABASE_DEFAULT AS [BATERIA],
            CAST(NULL AS nvarchar(1000)) COLLATE DATABASE_DEFAULT AS [PANTALLA],
            C.[FechaCarga] AS [HOY],
            CONVERT(nvarchar(255),C.[Estado]) COLLATE DATABASE_DEFAULT AS [ESTADO],
            CAST(NULL AS nvarchar(255)) COLLATE DATABASE_DEFAULT AS [METODO],
            CONVERT(nvarchar(255),C.[Falla de Comunicacion]) COLLATE DATABASE_DEFAULT AS [YAT:COM],
            C.[Presion] AS [PI-005-PL],
            C.[Vibracion] AS [VIBRACION],
            CAST(NULL AS float) AS [SI-002-SPM],
            C.[Golpes por minuto] AS [QT:GOLPES-MIN]
        FROM dbo.CLEAR_TECSS_POZOS_CACHE AS C
        WHERE NOT EXISTS
        (
            SELECT 1
            FROM dbo.TECSS_RTQP AS R2
            WHERE UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255),R2.[POZO]))))
                  COLLATE DATABASE_DEFAULT
                = UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255),C.[Pozo]))))
                  COLLATE DATABASE_DEFAULT
        )
    ";
}

require __DIR__ . '/includes/telemetry_grid.php';
