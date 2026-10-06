/*
===============================================================================
CLEAR - Cache local para dbo.Pozos_tecss (RTQP)
Objetivo:
  - Evitar que la web consulte dbo.Pozos_tecss directamente.
  - Refrescar una copia local cada 1 hora.
  - Mantener TECSS_RTQP como fuente principal.
  - La grilla telemetria_tecss.php agrega solo los pozos faltantes desde cache.

Ejecucion:
  1) Ejecutar este script una sola vez en LC_MDB.
  2) Verificar la carga inicial.
  3) El Job SQL Agent queda programado cada 1 hora.
===============================================================================
*/

USE [LC_MDB];
GO

/* ---------------------------------------------------------------------------
   1. Tabla de log
--------------------------------------------------------------------------- */
IF OBJECT_ID(N'dbo.CLEAR_TECSS_POZOS_CACHE_CARGAS',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_TECSS_POZOS_CACHE_CARGAS
    (
        CargaId           bigint IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_CLEAR_TECSS_POZOS_CACHE_CARGAS PRIMARY KEY,
        FechaInicio       datetime2(0) NOT NULL,
        FechaFin          datetime2(0) NULL,
        RegistrosOrigen   int NULL,
        RegistrosCache    int NULL,
        Estado            varchar(20) NOT NULL,
        Mensaje           nvarchar(2000) NULL
    );
END
GO

/* ---------------------------------------------------------------------------
   2. Cache productiva
--------------------------------------------------------------------------- */
IF OBJECT_ID(N'dbo.CLEAR_TECSS_POZOS_CACHE',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_TECSS_POZOS_CACHE
    (
        Pozo                    nvarchar(255) NOT NULL,
        Presion                 float NULL,
        Vibracion               float NULL,
        [Golpes por minuto]     float NULL,
        Estado                  nvarchar(255) NULL,
        tipo                    nvarchar(255) NULL,
        [Falla de Comunicacion] nvarchar(255) NULL,
        FechaCarga              datetime2(0) NOT NULL,

        CONSTRAINT PK_CLEAR_TECSS_POZOS_CACHE
            PRIMARY KEY CLUSTERED (Pozo)
    );
END
GO

/* ---------------------------------------------------------------------------
   3. Stage
--------------------------------------------------------------------------- */
IF OBJECT_ID(N'dbo.CLEAR_TECSS_POZOS_CACHE_STAGE',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_TECSS_POZOS_CACHE_STAGE
    (
        Pozo                    nvarchar(255) NOT NULL,
        Presion                 float NULL,
        Vibracion               float NULL,
        [Golpes por minuto]     float NULL,
        Estado                  nvarchar(255) NULL,
        tipo                    nvarchar(255) NULL,
        [Falla de Comunicacion] nvarchar(255) NULL,
        FechaCarga              datetime2(0) NOT NULL
    );
END
GO

/* ---------------------------------------------------------------------------
   4. Procedimiento de refresco seguro
--------------------------------------------------------------------------- */
CREATE OR ALTER PROCEDURE dbo.SP_CLEAR_TECSS_POZOS_CACHE_REFRESCAR
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE
        @CargaId bigint,
        @Inicio datetime2(0) = SYSDATETIME(),
        @Origen int = 0,
        @Stage int = 0;

    INSERT INTO dbo.CLEAR_TECSS_POZOS_CACHE_CARGAS
    (
        FechaInicio,
        Estado
    )
    VALUES
    (
        @Inicio,
        'INICIADA'
    );

    SET @CargaId = SCOPE_IDENTITY();

    BEGIN TRY
        TRUNCATE TABLE dbo.CLEAR_TECSS_POZOS_CACHE_STAGE;

        /*
          Se usa ROW_NUMBER por seguridad. Si la vista RTQP devolviera el mismo
          pozo mas de una vez, la cache final conserva una sola fila.
        */
        ;WITH ORIGEN AS
        (
            SELECT
                LTRIM(RTRIM(CONVERT(nvarchar(255),P.[Pozo]))) AS Pozo,
                TRY_CONVERT(float,P.[Presion]) AS Presion,
                TRY_CONVERT(float,P.[Vibracion]) AS Vibracion,
                TRY_CONVERT(float,P.[Golpes por minuto]) AS [Golpes por minuto],
                CONVERT(nvarchar(255),P.[Estado]) AS Estado,
                CONVERT(nvarchar(255),P.[tipo]) AS tipo,
                CONVERT(nvarchar(255),P.[Falla de Comunicacion]) AS [Falla de Comunicacion],
                ROW_NUMBER() OVER
                (
                    PARTITION BY UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255),P.[Pozo]))))
                    ORDER BY (SELECT NULL)
                ) AS RN
            FROM dbo.Pozos_tecss AS P
            WHERE P.[Pozo] IS NOT NULL
              AND LTRIM(RTRIM(CONVERT(nvarchar(255),P.[Pozo]))) <> ''
        )
        INSERT INTO dbo.CLEAR_TECSS_POZOS_CACHE_STAGE
        (
            Pozo,
            Presion,
            Vibracion,
            [Golpes por minuto],
            Estado,
            tipo,
            [Falla de Comunicacion],
            FechaCarga
        )
        SELECT
            Pozo,
            Presion,
            Vibracion,
            [Golpes por minuto],
            Estado,
            tipo,
            [Falla de Comunicacion],
            SYSDATETIME()
        FROM ORIGEN
        WHERE RN = 1;

        SET @Stage = @@ROWCOUNT;
        SET @Origen = @Stage;

        /*
          Proteccion basica:
          no se pisa la cache productiva si el origen devuelve cero filas.
        */
        IF @Stage <= 0
            THROW 51001, 'Pozos_tecss devolvio 0 registros. Se conserva la cache anterior.', 1;

        BEGIN TRANSACTION;

            TRUNCATE TABLE dbo.CLEAR_TECSS_POZOS_CACHE;

            INSERT INTO dbo.CLEAR_TECSS_POZOS_CACHE
            (
                Pozo,
                Presion,
                Vibracion,
                [Golpes por minuto],
                Estado,
                tipo,
                [Falla de Comunicacion],
                FechaCarga
            )
            SELECT
                Pozo,
                Presion,
                Vibracion,
                [Golpes por minuto],
                Estado,
                tipo,
                [Falla de Comunicacion],
                FechaCarga
            FROM dbo.CLEAR_TECSS_POZOS_CACHE_STAGE;

        COMMIT TRANSACTION;

        UPDATE dbo.CLEAR_TECSS_POZOS_CACHE_CARGAS
        SET
            FechaFin = SYSDATETIME(),
            RegistrosOrigen = @Origen,
            RegistrosCache = @Stage,
            Estado = 'OK',
            Mensaje = N'Cache TECSS actualizada correctamente.'
        WHERE CargaId = @CargaId;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;

        UPDATE dbo.CLEAR_TECSS_POZOS_CACHE_CARGAS
        SET
            FechaFin = SYSDATETIME(),
            RegistrosOrigen = @Origen,
            RegistrosCache = @Stage,
            Estado = 'ERROR',
            Mensaje = ERROR_MESSAGE()
        WHERE CargaId = @CargaId;

        THROW;
    END CATCH;
END
GO

/* ---------------------------------------------------------------------------
   5. Carga inicial
--------------------------------------------------------------------------- */
EXEC dbo.SP_CLEAR_TECSS_POZOS_CACHE_REFRESCAR;
GO

/* ---------------------------------------------------------------------------
   6. Job SQL Agent cada 1 hora
--------------------------------------------------------------------------- */
USE [msdb];
GO

IF NOT EXISTS
(
    SELECT 1
    FROM dbo.sysjobs
    WHERE name = N'CLEAR - Refrescar cache Pozos TECSS'
)
BEGIN
    EXEC dbo.sp_add_job
        @job_name = N'CLEAR - Refrescar cache Pozos TECSS',
        @enabled = 1,
        @description = N'Refresca cada hora la cache local de dbo.Pozos_tecss para CLEAR.';
END
GO

IF NOT EXISTS
(
    SELECT 1
    FROM dbo.sysjobsteps s
    INNER JOIN dbo.sysjobs j ON j.job_id = s.job_id
    WHERE j.name = N'CLEAR - Refrescar cache Pozos TECSS'
      AND s.step_name = N'Refrescar cache'
)
BEGIN
    EXEC dbo.sp_add_jobstep
        @job_name = N'CLEAR - Refrescar cache Pozos TECSS',
        @step_name = N'Refrescar cache',
        @subsystem = N'TSQL',
        @database_name = N'LC_MDB',
        @command = N'EXEC dbo.SP_CLEAR_TECSS_POZOS_CACHE_REFRESCAR;',
        @retry_attempts = 3,
        @retry_interval = 5;
END
GO

IF NOT EXISTS
(
    SELECT 1
    FROM dbo.sysschedules
    WHERE name = N'CLEAR - TECSS cada 1 hora'
)
BEGIN
    EXEC dbo.sp_add_schedule
        @schedule_name = N'CLEAR - TECSS cada 1 hora',
        @enabled = 1,
        @freq_type = 4,
        @freq_interval = 1,
        @freq_subday_type = 8,
        @freq_subday_interval = 1,
        @active_start_time = 000000;
END
GO

IF NOT EXISTS
(
    SELECT 1
    FROM dbo.sysjobs j
    INNER JOIN dbo.sysjobschedules js ON js.job_id = j.job_id
    INNER JOIN dbo.sysschedules s ON s.schedule_id = js.schedule_id
    WHERE j.name = N'CLEAR - Refrescar cache Pozos TECSS'
      AND s.name = N'CLEAR - TECSS cada 1 hora'
)
BEGIN
    EXEC dbo.sp_attach_schedule
        @job_name = N'CLEAR - Refrescar cache Pozos TECSS',
        @schedule_name = N'CLEAR - TECSS cada 1 hora';
END
GO

IF NOT EXISTS
(
    SELECT 1
    FROM dbo.sysjobservers js
    INNER JOIN dbo.sysjobs j ON j.job_id = js.job_id
    WHERE j.name = N'CLEAR - Refrescar cache Pozos TECSS'
)
BEGIN
    EXEC dbo.sp_add_jobserver
        @job_name = N'CLEAR - Refrescar cache Pozos TECSS';
END
GO

/* ---------------------------------------------------------------------------
   7. Verificacion
--------------------------------------------------------------------------- */
USE [LC_MDB];
GO

SELECT COUNT(*) AS CantidadCache
FROM dbo.CLEAR_TECSS_POZOS_CACHE;

SELECT TOP (20)
    *
FROM dbo.CLEAR_TECSS_POZOS_CACHE
ORDER BY Pozo;

SELECT TOP (20)
    *
FROM dbo.CLEAR_TECSS_POZOS_CACHE_CARGAS
ORDER BY CargaId DESC;
GO
