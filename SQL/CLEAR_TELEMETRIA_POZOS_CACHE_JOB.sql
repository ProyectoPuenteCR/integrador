USE [LC_MDB];
GO

/*
    Cache de la Grilla general de pozos.

    Origen:
      dbo.VW_TELEMETRIA_POZOS_GENERAL
      dbo.FIXALARMS (conteo de las últimas 24 horas)

    Destino:
      dbo.TELEMETRIA_POZOS_GENERAL_CACHE

    Frecuencia:
      Job de SQL Server Agent cada 10 minutos.
*/

IF OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.TELEMETRIA_POZOS_GENERAL_CACHE
    (
        ID                    bigint IDENTITY(1,1) NOT NULL,
        POZO                  nvarchar(255) NOT NULL,
        BATERIA               nvarchar(255) NULL,
        TIPO                  nvarchar(20) NOT NULL,
        ALM                   int NOT NULL CONSTRAINT DF_TELEMETRIA_POZOS_CACHE_ALM DEFAULT (0),
        COMUNICACION          nvarchar(255) NULL,
        ESTADO                nvarchar(255) NULL,
        PANTALLA              nvarchar(1000) NULL,
        ULTIMA_ACTUALIZACION  datetime2(0) NULL,
        FECHA_CACHE           datetime2(0) NOT NULL CONSTRAINT DF_TELEMETRIA_POZOS_CACHE_FECHA DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_TELEMETRIA_POZOS_GENERAL_CACHE PRIMARY KEY CLUSTERED (ID)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE') AND name=N'IX_TELEMETRIA_POZOS_CACHE_TIPO_POZO')
    CREATE INDEX IX_TELEMETRIA_POZOS_CACHE_TIPO_POZO ON dbo.TELEMETRIA_POZOS_GENERAL_CACHE (TIPO, POZO);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE') AND name=N'IX_TELEMETRIA_POZOS_CACHE_BATERIA')
    CREATE INDEX IX_TELEMETRIA_POZOS_CACHE_BATERIA ON dbo.TELEMETRIA_POZOS_GENERAL_CACHE (BATERIA);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE') AND name=N'IX_TELEMETRIA_POZOS_CACHE_COM_ESTADO')
    CREATE INDEX IX_TELEMETRIA_POZOS_CACHE_COM_ESTADO ON dbo.TELEMETRIA_POZOS_GENERAL_CACHE (COMUNICACION, ESTADO);
GO

CREATE OR ALTER PROCEDURE dbo.SP_ACTUALIZAR_TELEMETRIA_POZOS_GENERAL_CACHE
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Ahora datetime2(0) = SYSDATETIME();

    BEGIN TRY
        BEGIN TRANSACTION;

        TRUNCATE TABLE dbo.TELEMETRIA_POZOS_GENERAL_CACHE;

        ;WITH Alarmas24h AS
        (
            SELECT
                UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), ALM_ALMEXTFLD2)))) AS POZO,
                COUNT_BIG(*) AS TOTAL
            FROM dbo.FIXALARMS
            WHERE ALM_ALMEXTFLD2 IS NOT NULL
              AND LTRIM(RTRIM(CONVERT(nvarchar(255), ALM_ALMEXTFLD2))) <> N''
              AND TRY_CONVERT(datetime2, ALM_NATIVETIMEIN) >= DATEADD(HOUR, -24, @Ahora)
            GROUP BY UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), ALM_ALMEXTFLD2))))
        )
        INSERT INTO dbo.TELEMETRIA_POZOS_GENERAL_CACHE
        (
            POZO,
            BATERIA,
            TIPO,
            ALM,
            COMUNICACION,
            ESTADO,
            PANTALLA,
            ULTIMA_ACTUALIZACION,
            FECHA_CACHE
        )
        SELECT
            V.POZO,
            V.BATERIA,
            V.TIPO,
            CONVERT(int, ISNULL(A.TOTAL, 0)) AS ALM,
            V.COMUNICACION,
            V.ESTADO,
            V.PANTALLA,
            V.ULTIMA_ACTUALIZACION,
            @Ahora
        FROM dbo.VW_TELEMETRIA_POZOS_GENERAL AS V
        LEFT JOIN Alarmas24h AS A
          ON A.POZO COLLATE DATABASE_DEFAULT
             = UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255),V.POZO)))) COLLATE DATABASE_DEFAULT
        WHERE V.POZO IS NOT NULL
          AND LTRIM(RTRIM(V.POZO)) <> N'';

        COMMIT TRANSACTION;

        SELECT
            COUNT_BIG(*) AS REGISTROS_CARGADOS,
            @Ahora AS FECHA_CACHE
        FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

-- Primera carga inmediata.
EXEC dbo.SP_ACTUALIZAR_TELEMETRIA_POZOS_GENERAL_CACHE;
GO

/* Crear o recrear el Job. Requiere SQL Server Agent iniciado. */
USE [msdb];
GO

IF EXISTS (SELECT 1 FROM dbo.sysjobs WHERE name = N'CLEAR - Actualizar grilla general de pozos')
BEGIN
    EXEC dbo.sp_delete_job
        @job_name = N'CLEAR - Actualizar grilla general de pozos',
        @delete_unused_schedule = 1;
END;
GO

DECLARE @jobId uniqueidentifier;
DECLARE @fechaInicio int = CONVERT(int, CONVERT(char(8), GETDATE(), 112));

EXEC dbo.sp_add_job
    @job_name = N'CLEAR - Actualizar grilla general de pozos',
    @enabled = 1,
    @description = N'Actualiza cada 10 minutos la tabla cache usada por la Grilla general de pozos.',
    @category_name = N'[Uncategorized (Local)]',
    @owner_login_name = N'sa',
    @job_id = @jobId OUTPUT;

EXEC dbo.sp_add_jobstep
    @job_id = @jobId,
    @step_name = N'Actualizar cache de telemetría',
    @subsystem = N'TSQL',
    @database_name = N'LC_MDB',
    @command = N'EXEC dbo.SP_ACTUALIZAR_TELEMETRIA_POZOS_GENERAL_CACHE;',
    @retry_attempts = 2,
    @retry_interval = 2,
    @on_success_action = 1,
    @on_fail_action = 2;

EXEC dbo.sp_add_schedule
    @schedule_name = N'CLEAR - Cada 10 minutos - Grilla pozos',
    @enabled = 1,
    @freq_type = 4,
    @freq_interval = 1,
    @freq_subday_type = 4,
    @freq_subday_interval = 10,
    @active_start_date = @fechaInicio,
    @active_start_time = 0;

EXEC dbo.sp_attach_schedule
    @job_id = @jobId,
    @schedule_name = N'CLEAR - Cada 10 minutos - Grilla pozos';

EXEC dbo.sp_add_jobserver
    @job_id = @jobId,
    @server_name = N'(LOCAL)';
GO

-- Verificación final.
USE [LC_MDB];
GO
SELECT TOP (100)
    POZO,
    BATERIA,
    TIPO,
    ALM,
    COMUNICACION,
    ESTADO,
    PANTALLA,
    ULTIMA_ACTUALIZACION,
    FECHA_CACHE
FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE
ORDER BY POZO, TIPO;
GO
