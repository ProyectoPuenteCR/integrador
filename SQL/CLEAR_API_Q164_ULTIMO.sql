USE LC_MDB;
GO

/*
  CLEAR - INFOIL Query 164
  Vista optimizada del ultimo Test de Pozos Productores Aprobados por pozo.
  La aplicacion posee fallback y puede funcionar sin esta vista, pero se
  recomienda crearla porque es utilizada por varias pantallas de telemetria.
*/

IF NOT EXISTS
(
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.CLEAR_API_Q164_CACHE')
      AND name = 'IX_CLEAR_API_Q164_CACHE_POZO_FECHA'
)
BEGIN
    CREATE INDEX IX_CLEAR_API_Q164_CACHE_POZO_FECHA
        ON dbo.CLEAR_API_Q164_CACHE
        (
            POZO,
            DIA_OPERATIVO DESC,
            FECHA_HORA DESC,
            ID_TEST DESC
        )
        INCLUDE
        (
            PRODUCCION_PETROLEO,
            PRODUCCION_LIQUIDO,
            PRODUCCION_GAS,
            FECHA_ACTUALIZACION
        );
END;
GO

CREATE OR ALTER VIEW dbo.CLEAR_API_Q164_ULTIMO
AS
WITH ULTIMO_TEST AS
(
    SELECT
        ID_TEST,
        POZO_ID,
        POZO,
        POZO_NOMBRE,
        POZO_CLASE,
        DIA_OPERATIVO,
        FECHA_HORA,
        ESTADO_ID,
        ESTADO,
        PRODUCCION_GAS,
        PRODUCCION_LIQUIDO,
        PRODUCCION_PETROLEO,
        FECHA_ACTUALIZACION,
        ROW_NUMBER() OVER
        (
            PARTITION BY UPPER(LTRIM(RTRIM(POZO)))
            ORDER BY DIA_OPERATIVO DESC, FECHA_HORA DESC, ID_TEST DESC
        ) AS RN
    FROM dbo.CLEAR_API_Q164_CACHE
    WHERE POZO IS NOT NULL
      AND LTRIM(RTRIM(POZO)) <> ''
)
SELECT
    ID_TEST,
    POZO_ID,
    POZO,
    POZO_NOMBRE,
    POZO_CLASE,
    DIA_OPERATIVO,
    FECHA_HORA,
    ESTADO_ID,
    ESTADO,
    PRODUCCION_GAS,
    PRODUCCION_LIQUIDO,
    PRODUCCION_PETROLEO,
    FECHA_ACTUALIZACION
FROM ULTIMO_TEST
WHERE RN = 1;
GO

SELECT TOP 20
    POZO,
    DIA_OPERATIVO,
    PRODUCCION_PETROLEO,
    PRODUCCION_LIQUIDO,
    PRODUCCION_GAS,
    FECHA_ACTUALIZACION
FROM dbo.CLEAR_API_Q164_ULTIMO
ORDER BY DIA_OPERATIVO DESC, FECHA_HORA DESC;
GO
