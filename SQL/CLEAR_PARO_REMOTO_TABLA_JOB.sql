USE [LC_MDB];
GO

/*
    CLEAR - Estado de paro remoto por pozo

    Origen:  [SCADA_POZOS].[SCADA].[dbo].[Estado_Pozos2]
    Destino: [LC_MDB].[dbo].[CLEAR_PARO_REMOTO]
    Horario: 06:00 y 18:00, hora local del servidor SQL.

    La lectura del linked server se completa antes de vaciar la tabla local.
    Si el origen falla o devuelve cero filas, se conserva la última carga válida.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.CLEAR_PARO_REMOTO',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_PARO_REMOTO
    (
        ID                         bigint IDENTITY(1,1) NOT NULL,
        [Element]                  nvarchar(1000) NULL,
        [AF-PARAM]                 nvarchar(255) NULL,
        [AF-POZO]                  nvarchar(255) NULL,
        [AF-TIPO]                  nvarchar(255) NULL,
        [AF-TIPO-DESC]             nvarchar(1000) NULL,
        [AF-ESTADO-DESC]           nvarchar(1000) NULL,
        [AF-TIEMPO-PARO]           nvarchar(100) NULL,
        [SQL-YACIMIENTO]           nvarchar(255) NULL,
        [SQL-BATERIA]              nvarchar(255) NULL,
        [LINEA_ELECTRICA]          nvarchar(255) NULL,
        [AF-ESTADO-PARO-REMOTO]    nvarchar(100) NULL,
        [FECHA_ACTUALIZACION]      datetime2(0) NOT NULL,
        CONSTRAINT PK_CLEAR_PARO_REMOTO PRIMARY KEY CLUSTERED (ID)
    );
END;
GO

IF NOT EXISTS
(
    SELECT 1
    FROM sys.indexes
    WHERE object_id=OBJECT_ID(N'dbo.CLEAR_PARO_REMOTO')
      AND name=N'IX_CLEAR_PARO_REMOTO_AF_POZO'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_CLEAR_PARO_REMOTO_AF_POZO
        ON dbo.CLEAR_PARO_REMOTO ([AF-POZO])
        INCLUDE ([AF-ESTADO-PARO-REMOTO],[FECHA_ACTUALIZACION]);
END;
GO

/* La aplicación web de CLEAR usa el usuario de base fix. */
IF DATABASE_PRINCIPAL_ID(N'fix') IS NOT NULL
    GRANT SELECT ON dbo.CLEAR_PARO_REMOTO TO [fix];
GO

IF OBJECT_ID(N'dbo.SP_CLEAR_ACTUALIZAR_PARO_REMOTO',N'P') IS NULL
    EXEC(N'CREATE PROCEDURE dbo.SP_CLEAR_ACTUALIZAR_PARO_REMOTO AS RETURN 0;');
GO

ALTER PROCEDURE dbo.SP_CLEAR_ACTUALIZAR_PARO_REMOTO
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Ahora datetime2(0)=SYSDATETIME();
    DECLARE @Bloqueo int;

    EXEC @Bloqueo=sys.sp_getapplock
        @Resource=N'CLEAR_PARO_REMOTO_ACTUALIZACION',
        @LockMode=N'Exclusive',
        @LockOwner=N'Session',
        @LockTimeout=0;

    IF @Bloqueo<0
        THROW 50041, 'La actualización de CLEAR_PARO_REMOTO ya está en ejecución.', 1;

    BEGIN TRY
        SELECT
            CONVERT(nvarchar(1000),[Element])               AS [Element],
            CONVERT(nvarchar(255), [AF-PARAM])              AS [AF-PARAM],
            CONVERT(nvarchar(255), [AF-POZO])               AS [AF-POZO],
            CONVERT(nvarchar(255), [AF-TIPO])               AS [AF-TIPO],
            CONVERT(nvarchar(1000),[AF-TIPO-DESC])          AS [AF-TIPO-DESC],
            CONVERT(nvarchar(1000),[AF-ESTADO-DESC])        AS [AF-ESTADO-DESC],
            CONVERT(nvarchar(100), [AF-TIEMPO-PARO])        AS [AF-TIEMPO-PARO],
            CONVERT(nvarchar(255), [SQL-YACIMIENTO])        AS [SQL-YACIMIENTO],
            CONVERT(nvarchar(255), [SQL-BATERIA])           AS [SQL-BATERIA],
            CONVERT(nvarchar(255), [LINEA_ELECTRICA])       AS [LINEA_ELECTRICA],
            CONVERT(nvarchar(100), [AF-ESTADO-PARO-REMOTO]) AS [AF-ESTADO-PARO-REMOTO]
        INTO #CLEAR_PARO_REMOTO_NUEVO
        FROM [SCADA_POZOS].[SCADA].[dbo].[Estado_Pozos2]
        WHERE [AF-POZO] IS NOT NULL
          AND LTRIM(RTRIM(CONVERT(nvarchar(255),[AF-POZO])))<>N'';

        IF NOT EXISTS (SELECT 1 FROM #CLEAR_PARO_REMOTO_NUEVO)
            THROW 50042, 'El linked server no devolvió pozos. Se conserva la última carga válida.', 1;

        BEGIN TRANSACTION;
            TRUNCATE TABLE dbo.CLEAR_PARO_REMOTO;

            INSERT INTO dbo.CLEAR_PARO_REMOTO
            (
                [Element],[AF-PARAM],[AF-POZO],[AF-TIPO],[AF-TIPO-DESC],
                [AF-ESTADO-DESC],[AF-TIEMPO-PARO],[SQL-YACIMIENTO],
                [SQL-BATERIA],[LINEA_ELECTRICA],[AF-ESTADO-PARO-REMOTO],
                [FECHA_ACTUALIZACION]
            )
            SELECT
                [Element],[AF-PARAM],[AF-POZO],[AF-TIPO],[AF-TIPO-DESC],
                [AF-ESTADO-DESC],[AF-TIEMPO-PARO],[SQL-YACIMIENTO],
                [SQL-BATERIA],[LINEA_ELECTRICA],[AF-ESTADO-PARO-REMOTO],
                @Ahora
            FROM #CLEAR_PARO_REMOTO_NUEVO;
        COMMIT TRANSACTION;

        EXEC sys.sp_releaseapplock
            @Resource=N'CLEAR_PARO_REMOTO_ACTUALIZACION',
            @LockOwner=N'Session';

        SELECT COUNT_BIG(*) AS REGISTROS_CARGADOS, @Ahora AS FECHA_ACTUALIZACION
        FROM dbo.CLEAR_PARO_REMOTO;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;
        EXEC sys.sp_releaseapplock
            @Resource=N'CLEAR_PARO_REMOTO_ACTUALIZACION',
            @LockOwner=N'Session';
        THROW;
    END CATCH;
END;
GO

/* Primera carga inmediata para no esperar al próximo horario. */
EXEC dbo.SP_CLEAR_ACTUALIZAR_PARO_REMOTO;
GO

USE [msdb];
GO

IF EXISTS (SELECT 1 FROM dbo.sysjobs WHERE name=N'CLEAR - Actualizar paro remoto')
BEGIN
    EXEC dbo.sp_delete_job
        @job_name=N'CLEAR - Actualizar paro remoto',
        @delete_unused_schedule=1;
END;
GO

DECLARE @JobId uniqueidentifier;
DECLARE @FechaInicio int=CONVERT(int,CONVERT(char(8),GETDATE(),112));

EXEC dbo.sp_add_job
    @job_name=N'CLEAR - Actualizar paro remoto',
    @enabled=1,
    @description=N'Reemplaza CLEAR_PARO_REMOTO desde SCADA_POZOS dos veces por día (06:00 y 18:00).',
    @category_name=N'[Uncategorized (Local)]',
    @owner_login_name=N'sa',
    @job_id=@JobId OUTPUT;

EXEC dbo.sp_add_jobstep
    @job_id=@JobId,
    @step_name=N'Reemplazar datos de paro remoto',
    @subsystem=N'TSQL',
    @database_name=N'LC_MDB',
    @command=N'EXEC dbo.SP_CLEAR_ACTUALIZAR_PARO_REMOTO;',
    @retry_attempts=2,
    @retry_interval=5,
    @on_success_action=1,
    @on_fail_action=2;

EXEC dbo.sp_add_schedule
    @schedule_name=N'CLEAR - Paro remoto 06 y 18',
    @enabled=1,
    @freq_type=4,
    @freq_interval=1,
    @freq_subday_type=8,
    @freq_subday_interval=12,
    @active_start_date=@FechaInicio,
    @active_start_time=060000,
    @active_end_time=180000;

EXEC dbo.sp_attach_schedule
    @job_id=@JobId,
    @schedule_name=N'CLEAR - Paro remoto 06 y 18';

EXEC dbo.sp_add_jobserver
    @job_id=@JobId,
    @server_name=N'(LOCAL)';
GO

USE [LC_MDB];
GO

SELECT
    COUNT_BIG(*) AS TOTAL_REGISTROS,
    COUNT(DISTINCT CASE WHEN UPPER(LTRIM(RTRIM([AF-ESTADO-PARO-REMOTO])))=N'HABILITADO' THEN [AF-POZO] END) AS POZOS_HABILITADOS,
    MAX([FECHA_ACTUALIZACION]) AS ULTIMA_ACTUALIZACION
FROM dbo.CLEAR_PARO_REMOTO;
GO
