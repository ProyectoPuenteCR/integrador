USE [LC_MDB];
GO

/*
    CLEAR - Caché Top 20 24 h
    ---------------------------------------------
    IMPORTANTE:
    - dbo.FIXALARMS se utiliza exclusivamente como fuente de lectura.
    - Este script NO realiza INSERT, UPDATE, DELETE, TRUNCATE, ALTER ni DROP
      sobre dbo.FIXALARMS.
    - Todas las escrituras se realizan únicamente en dbo.CLEAR_CACHE_TOP20_24H.
*/

IF OBJECT_ID(N'dbo.CLEAR_CACHE_TOP20_24H', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_CACHE_TOP20_24H
    (
        ALM_TAGNAME        NVARCHAR(500)  NOT NULL,
        DESCRIPCION        NVARCHAR(1000) NULL,
        TOTAL_ALARMAS      BIGINT         NOT NULL,
        ALM_ALMEXTFLD2     NVARCHAR(500)  NULL,
        ULTIMA_APARICION   DATETIME2(3)   NULL,
        FECHA_ACTUALIZACION DATETIME2(0)  NOT NULL,

        CONSTRAINT PK_CLEAR_CACHE_TOP20_24H
            PRIMARY KEY CLUSTERED (ALM_TAGNAME)
    );

    CREATE NONCLUSTERED INDEX IX_CLEAR_CACHE_TOP20_24H_TOTAL
        ON dbo.CLEAR_CACHE_TOP20_24H (TOTAL_ALARMAS DESC)
        INCLUDE (DESCRIPCION, ALM_ALMEXTFLD2, ULTIMA_APARICION, FECHA_ACTUALIZACION);
END;
GO

/* Migración segura para instalaciones que ya tienen creada la caché. */
IF COL_LENGTH(N'dbo.CLEAR_CACHE_TOP20_24H', N'ULTIMA_APARICION') IS NULL
BEGIN
    ALTER TABLE dbo.CLEAR_CACHE_TOP20_24H
        ADD ULTIMA_APARICION DATETIME2(3) NULL;
END;
GO

CREATE OR ALTER PROCEDURE dbo.SP_CLEAR_ACTUALIZAR_CACHE_TOP20_24H
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    /*
       dbo.FIXALARMS: SOLO LECTURA.
       Se materializa el ranking completo de TAG de las últimas 24 horas.
       La pantalla aplica orden, búsqueda y paginación sobre esta tabla pequeña.
    */
    CREATE TABLE #NUEVOS_DATOS
    (
        ALM_TAGNAME        NVARCHAR(500)  NOT NULL,
        DESCRIPCION        NVARCHAR(1000) NULL,
        TOTAL_ALARMAS      BIGINT         NOT NULL,
        ALM_ALMEXTFLD2     NVARCHAR(500)  NULL,
        ULTIMA_APARICION   DATETIME2(3)   NULL
    );

    INSERT INTO #NUEVOS_DATOS
    (
        ALM_TAGNAME,
        DESCRIPCION,
        TOTAL_ALARMAS,
        ALM_ALMEXTFLD2,
        ULTIMA_APARICION
    )
    SELECT
        LTRIM(RTRIM(CONVERT(NVARCHAR(500), A.ALM_TAGNAME))) AS ALM_TAGNAME,
        NULLIF(
            MAX(COALESCE(
                NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(1000), A.ALM_DESCR))), N''),
                NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(1000), A.ALM_TAGDESC))), N''),
                N''
            )),
            N''
        ) AS DESCRIPCION,
        COUNT_BIG(*) AS TOTAL_ALARMAS,
        NULLIF(MAX(COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(500), A.ALM_ALMEXTFLD2))), N''),N'')),N'') AS ALM_ALMEXTFLD2,
        MAX(CONVERT(DATETIME2(3), A.ALM_NATIVETIMEIN)) AS ULTIMA_APARICION
    FROM dbo.FIXALARMS AS A
    WHERE A.ALM_NATIVETIMEIN >= DATEADD(HOUR, -24, SYSDATETIME())
      AND NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(500), A.ALM_TAGNAME))), N'') IS NOT NULL
      AND NOT EXISTS
      (
          SELECT 1
          FROM dbo.CLEAR_ALARM_FILTERS AS F
          WHERE F.ACTIVO = 1
            AND
            (
                (
                    F.CAMPO IN (N'TAG', N'AMBOS')
                    AND
                    (
                        (F.MODO = N'EXACTO'  AND LTRIM(RTRIM(CONVERT(NVARCHAR(1000), A.ALM_TAGNAME))) = F.VALOR)
                        OR (F.MODO = N'COMIENZA' AND CONVERT(NVARCHAR(1000), A.ALM_TAGNAME) LIKE F.VALOR + N'%')
                        OR (F.MODO = N'CONTIENE' AND CONVERT(NVARCHAR(1000), A.ALM_TAGNAME) LIKE N'%' + F.VALOR + N'%')
                    )
                )
                OR
                (
                    F.CAMPO IN (N'DESCRIPCION', N'AMBOS')
                    AND
                    (
                        (F.MODO = N'EXACTO'  AND LTRIM(RTRIM(CONVERT(NVARCHAR(1000), A.ALM_DESCR))) = F.VALOR)
                        OR (F.MODO = N'COMIENZA' AND CONVERT(NVARCHAR(1000), A.ALM_DESCR) LIKE F.VALOR + N'%')
                        OR (F.MODO = N'CONTIENE' AND CONVERT(NVARCHAR(1000), A.ALM_DESCR) LIKE N'%' + F.VALOR + N'%')
                    )
                )
            )
      )
    GROUP BY LTRIM(RTRIM(CONVERT(NVARCHAR(500), A.ALM_TAGNAME)));

    BEGIN TRY
        BEGIN TRANSACTION;

        /* TRUNCATE únicamente sobre la tabla caché de CLEAR. */
        TRUNCATE TABLE dbo.CLEAR_CACHE_TOP20_24H;

        INSERT INTO dbo.CLEAR_CACHE_TOP20_24H
        (
            ALM_TAGNAME,
            DESCRIPCION,
            TOTAL_ALARMAS,
            ALM_ALMEXTFLD2,
            ULTIMA_APARICION,
            FECHA_ACTUALIZACION
        )
        SELECT
            ALM_TAGNAME,
            DESCRIPCION,
            TOTAL_ALARMAS,
            ALM_ALMEXTFLD2,
            ULTIMA_APARICION,
            SYSDATETIME()
        FROM #NUEVOS_DATOS;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

/* Primera carga para que la pantalla tenga información antes de iniciar el Job. */
EXEC dbo.SP_CLEAR_ACTUALIZAR_CACHE_TOP20_24H;
GO

/* Crear o recrear el Job cada 5 minutos. */
USE [msdb];
GO

IF EXISTS
(
    SELECT 1
    FROM dbo.sysjobs
    WHERE name = N'CLEAR - Actualizar caché Top 20 24H'
)
BEGIN
    EXEC dbo.sp_delete_job
        @job_name = N'CLEAR - Actualizar caché Top 20 24H',
        @delete_unused_schedule = 1;
END;
GO

EXEC dbo.sp_add_job
    @job_name = N'CLEAR - Actualizar caché Top 20 24H',
    @enabled = 1,
    @description = N'Actualiza cada 5 minutos la caché del Top 20 24 h. dbo.FIXALARMS se utiliza únicamente como fuente de lectura.';
GO

EXEC dbo.sp_add_jobstep
    @job_name = N'CLEAR - Actualizar caché Top 20 24H',
    @step_name = N'Actualizar tabla caché',
    @subsystem = N'TSQL',
    @database_name = N'LC_MDB',
    @command = N'EXEC dbo.SP_CLEAR_ACTUALIZAR_CACHE_TOP20_24H;',
    @retry_attempts = 2,
    @retry_interval = 1;
GO

EXEC dbo.sp_add_schedule
    @schedule_name = N'CLEAR - Top 20 24H cada 5 minutos',
    @enabled = 1,
    @freq_type = 4,
    @freq_interval = 1,
    @freq_subday_type = 4,
    @freq_subday_interval = 5,
    @active_start_time = 0;
GO

EXEC dbo.sp_attach_schedule
    @job_name = N'CLEAR - Actualizar caché Top 20 24H',
    @schedule_name = N'CLEAR - Top 20 24H cada 5 minutos';
GO

EXEC dbo.sp_add_jobserver
    @job_name = N'CLEAR - Actualizar caché Top 20 24H';
GO

USE [LC_MDB];
GO

SELECT TOP (20)
    ALM_TAGNAME,
    DESCRIPCION,
    TOTAL_ALARMAS,
    ALM_ALMEXTFLD2,
    ULTIMA_APARICION,
    FECHA_ACTUALIZACION
FROM dbo.CLEAR_CACHE_TOP20_24H
ORDER BY TOTAL_ALARMAS DESC;
GO
