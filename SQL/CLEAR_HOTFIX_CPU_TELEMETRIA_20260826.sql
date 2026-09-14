USE [LC_MDB];
GO

/*
  HOTFIX DE RENDIMIENTO - TELEMETRIA / ALARMAS 24 H

  - El sitio ya no debe agrupar dbo.FIXALARMS por cada carga de pantalla.
  - Este procedimiento concentra el cálculo en el Job existente.
  - El filtro de fecha es sargable (sin TRY_CONVERT sobre la columna).
  - MAXDOP 1 limita el impacto de CPU.
  - La tabla visible se reemplaza dentro de una transacción muy corta.
  - sp_getapplock evita dos ejecuciones simultáneas del Job.

  El script NO ejecuta la carga al finalizar. El Job existente la realizará
  en su próxima programación. Si se necesita probar manualmente, hacerlo
  fuera del horario de mayor uso con:
      EXEC dbo.SP_ACTUALIZAR_TELEMETRIA_POZOS_GENERAL_CACHE;
*/

IF OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE',N'U') IS NULL
    THROW 51000, 'No existe dbo.TELEMETRIA_POZOS_GENERAL_CACHE. Instale primero CLEAR_TELEMETRIA_POZOS_CACHE_JOB.sql.', 1;
GO

CREATE OR ALTER PROCEDURE dbo.SP_ACTUALIZAR_TELEMETRIA_POZOS_GENERAL_CACHE
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    SET DEADLOCK_PRIORITY LOW;
    SET LOCK_TIMEOUT 15000;

    DECLARE @Ahora datetime2(0)=SYSDATETIME();
    DECLARE @LockResult int;

    EXEC @LockResult=sys.sp_getapplock
        @Resource=N'CLEAR_TELEMETRIA_POZOS_GENERAL_CACHE',
        @LockMode=N'Exclusive',
        @LockOwner=N'Session',
        @LockTimeout=0;

    IF @LockResult<0
    BEGIN
        SELECT CAST(0 AS bit) AS EJECUTADO,
               N'Ya existe otra actualización de telemetría en curso.' AS MENSAJE;
        RETURN;
    END;

    BEGIN TRY
        CREATE TABLE #Alarmas24h
        (
            POZO nvarchar(255) COLLATE DATABASE_DEFAULT NOT NULL PRIMARY KEY,
            TOTAL bigint NOT NULL
        );

        INSERT INTO #Alarmas24h(POZO,TOTAL)
        SELECT
            UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255),A.ALM_ALMEXTFLD2)))) AS POZO,
            COUNT_BIG(*) AS TOTAL
        FROM dbo.FIXALARMS AS A
        WHERE A.ALM_NATIVETIMEIN>=DATEADD(HOUR,-24,@Ahora)
          AND A.ALM_ALMEXTFLD2 IS NOT NULL
          AND A.ALM_ALMEXTFLD2<>N''
        GROUP BY UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255),A.ALM_ALMEXTFLD2))))
        OPTION (MAXDOP 1, RECOMPILE);

        CREATE TABLE #CacheNueva
        (
            POZO nvarchar(255) COLLATE DATABASE_DEFAULT NOT NULL,
            BATERIA nvarchar(255) COLLATE DATABASE_DEFAULT NULL,
            TIPO nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
            ALM int NOT NULL,
            COMUNICACION nvarchar(255) COLLATE DATABASE_DEFAULT NULL,
            ESTADO nvarchar(255) COLLATE DATABASE_DEFAULT NULL,
            PANTALLA nvarchar(1000) COLLATE DATABASE_DEFAULT NULL,
            ULTIMA_ACTUALIZACION datetime2(0) NULL,
            FECHA_CACHE datetime2(0) NOT NULL
        );

        INSERT INTO #CacheNueva
        (
            POZO,BATERIA,TIPO,ALM,COMUNICACION,ESTADO,PANTALLA,
            ULTIMA_ACTUALIZACION,FECHA_CACHE
        )
        SELECT
            V.POZO,V.BATERIA,V.TIPO,
            CONVERT(int,ISNULL(A.TOTAL,0)),
            V.COMUNICACION,V.ESTADO,V.PANTALLA,V.ULTIMA_ACTUALIZACION,@Ahora
        FROM dbo.VW_TELEMETRIA_POZOS_GENERAL AS V
        LEFT JOIN #Alarmas24h AS A
          ON A.POZO COLLATE DATABASE_DEFAULT
             =UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255),V.POZO)))) COLLATE DATABASE_DEFAULT
        WHERE V.POZO IS NOT NULL
          AND V.POZO<>N''
        OPTION (MAXDOP 1);

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
            FROM #CacheNueva;
        COMMIT TRANSACTION;

        EXEC sys.sp_releaseapplock
            @Resource=N'CLEAR_TELEMETRIA_POZOS_GENERAL_CACHE',
            @LockOwner=N'Session';

        SELECT CAST(1 AS bit) AS EJECUTADO,
               COUNT_BIG(*) AS REGISTROS_CARGADOS,
               @Ahora AS FECHA_CACHE
        FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;
        EXEC sys.sp_releaseapplock
            @Resource=N'CLEAR_TELEMETRIA_POZOS_GENERAL_CACHE',
            @LockOwner=N'Session';
        THROW;
    END CATCH;
END;
GO

/* Verificación liviana: el Job debe seguir habilitado y no solaparse. */
SELECT
    J.name AS JOB,
    J.enabled AS HABILITADO,
    S.name AS PROGRAMACION,
    S.freq_subday_interval AS INTERVALO,
    CASE S.freq_subday_type WHEN 4 THEN N'Minutos' WHEN 8 THEN N'Horas' ELSE CONVERT(nvarchar(20),S.freq_subday_type) END AS UNIDAD
FROM msdb.dbo.sysjobs AS J
LEFT JOIN msdb.dbo.sysjobschedules AS JS ON JS.job_id=J.job_id
LEFT JOIN msdb.dbo.sysschedules AS S ON S.schedule_id=JS.schedule_id
WHERE J.name=N'CLEAR - Actualizar grilla general de pozos';
GO
