USE [LC_MDB];
GO

/*
===============================================================================
CLEAR - Optimización cache general + integración TECSS secundaria
Fecha: 2026-10-06

Objetivo:
  1. Mantener la web desacoplada de las vistas RTQP.
  2. Incorporar al cache general los pozos adicionales de CLEAR_TECSS_POZOS_CACHE.
  3. Evitar duplicar pozos TECSS ya presentes en VW_TELEMETRIA_POZOS_GENERAL.
  4. Hacer la carga fuera de la tabla productiva y dejar el TRUNCATE/INSERT
     dentro de una transacción corta.
  5. Resolver collations explícitamente con DATABASE_DEFAULT.
===============================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* Índice útil para búsquedas por pozo desde comparación Zafiro vs PI. */
IF NOT EXISTS
(
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE')
      AND name = N'IX_TELEMETRIA_POZOS_CACHE_POZO'
)
BEGIN
    CREATE INDEX IX_TELEMETRIA_POZOS_CACHE_POZO
        ON dbo.TELEMETRIA_POZOS_GENERAL_CACHE (POZO)
        INCLUDE (BATERIA,TIPO,COMUNICACION,ESTADO,ULTIMA_ACTUALIZACION,FECHA_CACHE);
END;
GO

/*
  La tabla cache de TECSS ya tiene PK clustered por Pozo.
  No se consulta dbo.Pozos_tecss desde esta rutina.
*/
CREATE OR ALTER PROCEDURE dbo.SP_ACTUALIZAR_TELEMETRIA_POZOS_GENERAL_CACHE
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Ahora datetime2(0) = SYSDATETIME();

    CREATE TABLE #Alarmas24h
    (
        POZO nvarchar(255) COLLATE DATABASE_DEFAULT NOT NULL,
        TOTAL bigint NOT NULL,
        PRIMARY KEY CLUSTERED (POZO)
    );

    /*
      Se conserva la lógica de alarmas existente, pero se materializa una sola
      vez para reutilizarla en la fuente principal y en los TECSS adicionales.
    */
    INSERT INTO #Alarmas24h (POZO,TOTAL)
    SELECT
        UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), ALM_ALMEXTFLD2))))
            COLLATE DATABASE_DEFAULT AS POZO,
        COUNT_BIG(*) AS TOTAL
    FROM dbo.FIXALARMS
    WHERE ALM_ALMEXTFLD2 IS NOT NULL
      AND LTRIM(RTRIM(CONVERT(nvarchar(255), ALM_ALMEXTFLD2))) <> N''
      AND TRY_CONVERT(datetime2, ALM_NATIVETIMEIN) >= DATEADD(HOUR,-24,@Ahora)
    GROUP BY
        UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), ALM_ALMEXTFLD2))))
            COLLATE DATABASE_DEFAULT;

    /*
      Se arma primero la nueva foto completa en #NuevaCache.
      La tabla productiva sigue disponible durante toda esta parte.
    */
    CREATE TABLE #NuevaCache
    (
        POZO                  nvarchar(255) COLLATE DATABASE_DEFAULT NOT NULL,
        BATERIA               nvarchar(255) COLLATE DATABASE_DEFAULT NULL,
        TIPO                  nvarchar(20)  COLLATE DATABASE_DEFAULT NOT NULL,
        ALM                   int NOT NULL,
        COMUNICACION          nvarchar(255) COLLATE DATABASE_DEFAULT NULL,
        ESTADO                nvarchar(255) COLLATE DATABASE_DEFAULT NULL,
        PANTALLA              nvarchar(1000) COLLATE DATABASE_DEFAULT NULL,
        ULTIMA_ACTUALIZACION  datetime2(0) NULL,
        FECHA_CACHE           datetime2(0) NOT NULL
    );

    /* Fuente principal ya existente: BM / PCP / BES / TECSS_RTQP. */
    INSERT INTO #NuevaCache
    (
        POZO,BATERIA,TIPO,ALM,COMUNICACION,ESTADO,PANTALLA,
        ULTIMA_ACTUALIZACION,FECHA_CACHE
    )
    SELECT
        CONVERT(nvarchar(255),V.POZO) COLLATE DATABASE_DEFAULT,
        CONVERT(nvarchar(255),V.BATERIA) COLLATE DATABASE_DEFAULT,
        CONVERT(nvarchar(20),V.TIPO) COLLATE DATABASE_DEFAULT,
        CONVERT(int,ISNULL(A.TOTAL,0)),
        CONVERT(nvarchar(255),V.COMUNICACION) COLLATE DATABASE_DEFAULT,
        CONVERT(nvarchar(255),V.ESTADO) COLLATE DATABASE_DEFAULT,
        CONVERT(nvarchar(1000),V.PANTALLA) COLLATE DATABASE_DEFAULT,
        V.ULTIMA_ACTUALIZACION,
        @Ahora
    FROM dbo.VW_TELEMETRIA_POZOS_GENERAL AS V
    LEFT JOIN #Alarmas24h AS A
      ON A.POZO =
         UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255),V.POZO))))
         COLLATE DATABASE_DEFAULT
    WHERE V.POZO IS NOT NULL
      AND LTRIM(RTRIM(CONVERT(nvarchar(255),V.POZO))) <> N'';

    CREATE NONCLUSTERED INDEX IX_NuevaCache_POZO
        ON #NuevaCache (POZO);

    /*
      Agrega únicamente pozos de la cache secundaria TECSS que no estén ya
      presentes como TECSS/TECCS en la fuente principal.

      Importante:
      - NO consulta dbo.Pozos_tecss.
      - NO consulta RTQP adicional.
      - Usa CLEAR_TECSS_POZOS_CACHE, que se refresca cada hora.
    */
    IF OBJECT_ID(N'dbo.CLEAR_TECSS_POZOS_CACHE',N'U') IS NOT NULL
    BEGIN
        INSERT INTO #NuevaCache
        (
            POZO,BATERIA,TIPO,ALM,COMUNICACION,ESTADO,PANTALLA,
            ULTIMA_ACTUALIZACION,FECHA_CACHE
        )
        SELECT
            CONVERT(nvarchar(255),C.Pozo) COLLATE DATABASE_DEFAULT,
            CAST(NULL AS nvarchar(255)) COLLATE DATABASE_DEFAULT,
            CAST(N'TECSS' AS nvarchar(20)) COLLATE DATABASE_DEFAULT,
            CONVERT(int,ISNULL(A.TOTAL,0)),
            NULLIF(
                LTRIM(RTRIM(CONVERT(nvarchar(255),C.[Falla de Comunicacion]))),
                N''
            ) COLLATE DATABASE_DEFAULT,
            NULLIF(
                LTRIM(RTRIM(CONVERT(nvarchar(255),C.Estado))),
                N''
            ) COLLATE DATABASE_DEFAULT,
            CAST(NULL AS nvarchar(1000)) COLLATE DATABASE_DEFAULT,
            C.FechaCarga,
            @Ahora
        FROM dbo.CLEAR_TECSS_POZOS_CACHE AS C
        LEFT JOIN #Alarmas24h AS A
          ON A.POZO =
             UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255),C.Pozo))))
             COLLATE DATABASE_DEFAULT
        WHERE C.Pozo IS NOT NULL
          AND LTRIM(RTRIM(CONVERT(nvarchar(255),C.Pozo))) <> N''
          AND NOT EXISTS
          (
              SELECT 1
              FROM #NuevaCache AS N
              WHERE N.POZO COLLATE DATABASE_DEFAULT =
                    CONVERT(nvarchar(255),C.Pozo) COLLATE DATABASE_DEFAULT
                AND UPPER(N.TIPO) IN (N'TECSS',N'TECCS')
          );
    END;

    IF NOT EXISTS (SELECT 1 FROM #NuevaCache)
        THROW 51010, 'La nueva cache general quedó vacía. Se conserva la cache anterior.', 1;

    /*
      Reemplazo productivo muy corto.
      Las consultas costosas ya terminaron antes de BEGIN TRANSACTION.
    */
    BEGIN TRY
        BEGIN TRANSACTION;

        TRUNCATE TABLE dbo.TELEMETRIA_POZOS_GENERAL_CACHE;

        INSERT INTO dbo.TELEMETRIA_POZOS_GENERAL_CACHE
        (
            POZO,BATERIA,TIPO,ALM,COMUNICACION,ESTADO,PANTALLA,
            ULTIMA_ACTUALIZACION,FECHA_CACHE
        )
        SELECT
            POZO,BATERIA,TIPO,ALM,COMUNICACION,ESTADO,PANTALLA,
            ULTIMA_ACTUALIZACION,FECHA_CACHE
        FROM #NuevaCache;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        COUNT_BIG(*) AS REGISTROS_CARGADOS,
        SUM(CASE WHEN UPPER(TIPO) IN (N'TECSS',N'TECCS') THEN 1 ELSE 0 END) AS REGISTROS_TECSS,
        @Ahora AS FECHA_CACHE
    FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE;
END;
GO

/* Primera ejecución para validar inmediatamente el nuevo universo. */
EXEC dbo.SP_ACTUALIZAR_TELEMETRIA_POZOS_GENERAL_CACHE;
GO

/* Verificación específica TECSS y total general. */
SELECT
    COUNT_BIG(*) AS TOTAL_CACHE,
    SUM(CASE WHEN UPPER(TIPO) IN (N'TECSS',N'TECCS') THEN 1 ELSE 0 END) AS TOTAL_TECSS
FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE;
GO

SELECT
    TIPO,
    COUNT_BIG(*) AS CANTIDAD
FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE
GROUP BY TIPO
ORDER BY TIPO;
GO
