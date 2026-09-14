USE [LC_MDB];
GO

/*
  3Sigma TECSS - caché diaria de bajo impacto
  La pantalla web nunca consulta RTQP ni recorre la tabla histórica.
  Esta caché contiene como máximo una fila por POZO y se actualiza a las 06:00.
  La actualización lee únicamente la última captura de la tabla histórica.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.TECSS_TECSSAIB_HISTORICO', N'U') IS NULL
BEGIN
    ;THROW 50001, 'No existe dbo.TECSS_TECSSAIB_HISTORICO. No se creó la caché 3Sigma.', 1;
END;
GO

IF COL_LENGTH(N'dbo.TECSS_TECSSAIB_HISTORICO',N'POZO_BUSQUEDA') IS NULL
BEGIN
    ALTER TABLE [dbo].[TECSS_TECSSAIB_HISTORICO]
        ADD [POZO_BUSQUEDA] AS CONVERT(nvarchar(255),[POZO]) PERSISTED;
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE object_id=OBJECT_ID(N'dbo.TECSS_TECSSAIB_HISTORICO')
      AND name=N'IX_TECSS_HIST_POZO_FECHA'
)
BEGIN
    CREATE NONCLUSTERED INDEX [IX_TECSS_HIST_POZO_FECHA]
        ON [dbo].[TECSS_TECSSAIB_HISTORICO] ([POZO_BUSQUEDA],[FECHA_CAPTURA])
        INCLUDE ([HOY],[3SIGMA],[CONT_EXCESOS])
        WITH (MAXDOP=1);
END;
GO

IF NOT EXISTS
(
    SELECT 1
    FROM sys.indexes
    WHERE object_id=OBJECT_ID(N'dbo.TECSS_TECSSAIB_HISTORICO')
      AND name=N'IX_TECSS_HIST_FECHA_CAPTURA'
)
BEGIN
    CREATE NONCLUSTERED INDEX [IX_TECSS_HIST_FECHA_CAPTURA]
        ON [dbo].[TECSS_TECSSAIB_HISTORICO] ([FECHA_CAPTURA])
        WITH (MAXDOP=1);
END;
GO

IF OBJECT_ID(N'dbo.CLEAR_CACHE_TECSS_3SIGMA', N'U') IS NULL
BEGIN
    SELECT TOP (0)
        [POZO],[HOY],[BATERIA],[FC],[PI-005-PL],[3SIGMA],[CONT_EXCESOS],
        [VI-001-V],[SI-002-SPM],[YL-007-WS],[METODO],
        [QT:GOLPES-MIN],[TI-002-TAE],[TI-003-TBP],[PANTALLA],[TI-004-TE],
        CAST(NULL AS datetime2(0)) AS [FECHA_CACHE]
    INTO [dbo].[CLEAR_CACHE_TECSS_3SIGMA]
    FROM [dbo].[TECSS_TECSSAIB_HISTORICO];
END;
GO

IF COL_LENGTH(N'dbo.CLEAR_CACHE_TECSS_3SIGMA',N'POZO_BUSQUEDA') IS NULL
BEGIN
    ALTER TABLE [dbo].[CLEAR_CACHE_TECSS_3SIGMA]
        ADD [POZO_BUSQUEDA] AS CONVERT(nvarchar(255),[POZO]) PERSISTED;
END;
GO

IF COL_LENGTH(N'dbo.CLEAR_CACHE_TECSS_3SIGMA',N'CONT_EXCESOS_NUM') IS NULL
BEGIN
    ALTER TABLE [dbo].[CLEAR_CACHE_TECSS_3SIGMA]
        ADD [CONT_EXCESOS_NUM] AS TRY_CONVERT(decimal(38,10),NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(100),[CONT_EXCESOS]))),'')) PERSISTED;
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE object_id=OBJECT_ID(N'dbo.CLEAR_CACHE_TECSS_3SIGMA')
      AND name=N'IX_CLEAR_CACHE_TECSS_3SIGMA_POZO'
)
BEGIN
    CREATE NONCLUSTERED INDEX [IX_CLEAR_CACHE_TECSS_3SIGMA_POZO]
        ON [dbo].[CLEAR_CACHE_TECSS_3SIGMA] ([POZO_BUSQUEDA])
        INCLUDE ([BATERIA],[HOY],[3SIGMA],[CONT_EXCESOS]);
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE object_id=OBJECT_ID(N'dbo.CLEAR_CACHE_TECSS_3SIGMA')
      AND name=N'IX_CLEAR_CACHE_TECSS_3SIGMA_EXCESOS'
)
BEGIN
    CREATE NONCLUSTERED INDEX [IX_CLEAR_CACHE_TECSS_3SIGMA_EXCESOS]
        ON [dbo].[CLEAR_CACHE_TECSS_3SIGMA] ([CONT_EXCESOS_NUM] DESC)
        INCLUDE ([POZO_BUSQUEDA],[HOY],[BATERIA],[3SIGMA]);
END;
GO

CREATE OR ALTER PROCEDURE [dbo].[SP_TECSS_ACTUALIZAR_3SIGMA_CACHE]
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    /*
      El índice permite ubicar la última captura sin recorrer el histórico.
      ROW_NUMBER se aplica solo a esa captura diaria. Después se actualiza la
      caché por POZO si HOY es más reciente, conservando el último valor ya
      conocido de los pozos que no vinieron en la captura actual.
    */
    DECLARE @ULTIMA_CAPTURA datetime2(0);
    SELECT @ULTIMA_CAPTURA=MAX([FECHA_CAPTURA])
    FROM [dbo].[TECSS_TECSSAIB_HISTORICO];

    IF @ULTIMA_CAPTURA IS NULL
    BEGIN
        SELECT CAST(0 AS bigint) AS POZOS_CACHE, CAST(NULL AS datetime2(0)) AS FECHA_CACHE;
        RETURN;
    END;

    ;WITH Fuente AS
    (
        SELECT
            [POZO],[HOY],[BATERIA],[FC],[PI-005-PL],[3SIGMA],[CONT_EXCESOS],
            [VI-001-V],[SI-002-SPM],[YL-007-WS],[METODO],
            [QT:GOLPES-MIN],[TI-002-TAE],[TI-003-TBP],[PANTALLA],[TI-004-TE],
            [POZO_BUSQUEDA],
            ROW_NUMBER() OVER
            (
                PARTITION BY [POZO_BUSQUEDA]
                ORDER BY
                    CASE WHEN TRY_CONVERT(datetime2,[HOY]) IS NULL THEN 1 ELSE 0 END,
                    TRY_CONVERT(datetime2,[HOY]) DESC,
                    TRY_CONVERT(float,NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(100),[CONT_EXCESOS]))),'')) DESC
            ) AS RN
        FROM [dbo].[TECSS_TECSSAIB_HISTORICO]
        WHERE [POZO] IS NOT NULL
          AND LTRIM(RTRIM(CONVERT(nvarchar(255),[POZO]))) <> ''
          AND [FECHA_CAPTURA]=@ULTIMA_CAPTURA
    )
    SELECT
        [POZO],[HOY],[BATERIA],[FC],[PI-005-PL],[3SIGMA],[CONT_EXCESOS],
        [VI-001-V],[SI-002-SPM],[YL-007-WS],[METODO],
        [QT:GOLPES-MIN],[TI-002-TAE],[TI-003-TBP],[PANTALLA],[TI-004-TE],
        [POZO_BUSQUEDA]
    INTO #ULTIMO_TECSS
    FROM Fuente
    WHERE RN = 1;

    DECLARE @FECHA_CACHE datetime2(0) = SYSDATETIME();

    BEGIN TRANSACTION;
        UPDATE Cache
        SET
            [HOY]=Ultimo.[HOY],
            [BATERIA]=Ultimo.[BATERIA],
            [FC]=Ultimo.[FC],
            [PI-005-PL]=Ultimo.[PI-005-PL],
            [3SIGMA]=Ultimo.[3SIGMA],
            [CONT_EXCESOS]=Ultimo.[CONT_EXCESOS],
            [VI-001-V]=Ultimo.[VI-001-V],
            [SI-002-SPM]=Ultimo.[SI-002-SPM],
            [YL-007-WS]=Ultimo.[YL-007-WS],
            [METODO]=Ultimo.[METODO],
            [QT:GOLPES-MIN]=Ultimo.[QT:GOLPES-MIN],
            [TI-002-TAE]=Ultimo.[TI-002-TAE],
            [TI-003-TBP]=Ultimo.[TI-003-TBP],
            [PANTALLA]=Ultimo.[PANTALLA],
            [TI-004-TE]=Ultimo.[TI-004-TE],
            [FECHA_CACHE]=@FECHA_CACHE
        FROM [dbo].[CLEAR_CACHE_TECSS_3SIGMA] AS Cache
        INNER JOIN #ULTIMO_TECSS AS Ultimo
            ON Cache.[POZO_BUSQUEDA]=Ultimo.[POZO_BUSQUEDA]
        WHERE TRY_CONVERT(datetime2,Cache.[HOY]) IS NULL
           OR TRY_CONVERT(datetime2,Ultimo.[HOY]) >= TRY_CONVERT(datetime2,Cache.[HOY]);

        INSERT INTO [dbo].[CLEAR_CACHE_TECSS_3SIGMA]
        (
            [POZO],[HOY],[BATERIA],[FC],[PI-005-PL],[3SIGMA],[CONT_EXCESOS],
            [VI-001-V],[SI-002-SPM],[YL-007-WS],[METODO],
            [QT:GOLPES-MIN],[TI-002-TAE],[TI-003-TBP],[PANTALLA],[TI-004-TE],
            [FECHA_CACHE]
        )
        SELECT
            [POZO],[HOY],[BATERIA],[FC],[PI-005-PL],[3SIGMA],[CONT_EXCESOS],
            [VI-001-V],[SI-002-SPM],[YL-007-WS],[METODO],
            [QT:GOLPES-MIN],[TI-002-TAE],[TI-003-TBP],[PANTALLA],[TI-004-TE],
            @FECHA_CACHE
        FROM #ULTIMO_TECSS AS Ultimo
        WHERE NOT EXISTS
        (
            SELECT 1
            FROM [dbo].[CLEAR_CACHE_TECSS_3SIGMA] AS Cache
            WHERE Cache.[POZO_BUSQUEDA]=Ultimo.[POZO_BUSQUEDA]
        );
    COMMIT TRANSACTION;

    SELECT COUNT_BIG(*) AS POZOS_CACHE, @FECHA_CACHE AS FECHA_CACHE
    FROM [dbo].[CLEAR_CACHE_TECSS_3SIGMA];
END;
GO

/* Primera carga: deja la pantalla operativa sin esperar al día siguiente. */
EXEC [dbo].[SP_TECSS_ACTUALIZAR_3SIGMA_CACHE];
GO

USE [msdb];
GO

DECLARE @JobName sysname = N'CLEAR - Histórico TECSS diario 06hs';
DECLARE @StepName sysname = N'Guardar histórico TECSS';
DECLARE @ScheduleName sysname = N'CLEAR - TECSS todos los días 06hs';
DECLARE @Description nvarchar(512) = N'Guarda el histórico TECSS y actualiza la caché 3Sigma una vez al día a las 06:00.';
DECLARE @JobId uniqueidentifier;
DECLARE @StepId int;
DECLARE @FechaInicio int = CONVERT(int, CONVERT(char(8), GETDATE(), 112));

SELECT @JobId = job_id FROM dbo.sysjobs WHERE name = @JobName;
IF @JobId IS NULL
BEGIN
    EXEC dbo.sp_add_job
        @job_name = @JobName,
        @enabled = 1,
        @description = @Description,
        @job_id = @JobId OUTPUT;
END
ELSE
BEGIN
    EXEC dbo.sp_update_job @job_id=@JobId, @enabled=1, @description=@Description;
END;

SELECT @StepId = step_id FROM dbo.sysjobsteps WHERE job_id=@JobId AND step_name=@StepName;
IF @StepId IS NULL
BEGIN
    EXEC dbo.sp_add_jobstep
        @job_id=@JobId,
        @step_name=@StepName,
        @subsystem=N'TSQL',
        @database_name=N'LC_MDB',
        @command=N'SET NOCOUNT ON; SET XACT_ABORT ON;
IF OBJECT_ID(N''dbo.SP_TECSS_GUARDAR_HISTORICO'', N''P'') IS NULL
    ;THROW 50002, ''No existe dbo.SP_TECSS_GUARDAR_HISTORICO.'', 1;
EXEC dbo.SP_TECSS_GUARDAR_HISTORICO;
EXEC dbo.SP_TECSS_ACTUALIZAR_3SIGMA_CACHE;',
        @retry_attempts=2,
        @retry_interval=5,
        @on_success_action=1,
        @on_fail_action=2;
    SELECT @StepId = step_id FROM dbo.sysjobsteps WHERE job_id=@JobId AND step_name=@StepName;
END
ELSE
BEGIN
    EXEC dbo.sp_update_jobstep
        @job_id=@JobId,
        @step_id=@StepId,
        @subsystem=N'TSQL',
        @database_name=N'LC_MDB',
        @command=N'SET NOCOUNT ON; SET XACT_ABORT ON;
IF OBJECT_ID(N''dbo.SP_TECSS_GUARDAR_HISTORICO'', N''P'') IS NULL
    ;THROW 50002, ''No existe dbo.SP_TECSS_GUARDAR_HISTORICO.'', 1;
EXEC dbo.SP_TECSS_GUARDAR_HISTORICO;
EXEC dbo.SP_TECSS_ACTUALIZAR_3SIGMA_CACHE;',
        @retry_attempts=2,
        @retry_interval=5,
        @on_success_action=1,
        @on_fail_action=2;
END;

EXEC dbo.sp_update_job @job_id=@JobId, @start_step_id=@StepId;

IF NOT EXISTS (SELECT 1 FROM dbo.sysschedules WHERE name=@ScheduleName)
BEGIN
    EXEC dbo.sp_add_schedule
        @schedule_name=@ScheduleName,
        @enabled=1,
        @freq_type=4,
        @freq_interval=1,
        @freq_subday_type=1,
        @freq_subday_interval=0,
        @active_start_date=@FechaInicio,
        @active_start_time=060000;
END
ELSE
BEGIN
    EXEC dbo.sp_update_schedule
        @name=@ScheduleName,
        @enabled=1,
        @freq_type=4,
        @freq_interval=1,
        @freq_subday_type=1,
        @freq_subday_interval=0,
        @active_start_date=@FechaInicio,
        @active_start_time=060000;
END;

IF NOT EXISTS
(
    SELECT 1
    FROM dbo.sysjobschedules js
    INNER JOIN dbo.sysschedules s ON s.schedule_id=js.schedule_id
    WHERE js.job_id=@JobId AND s.name=@ScheduleName
)
    EXEC dbo.sp_attach_schedule @job_id=@JobId, @schedule_name=@ScheduleName;

IF NOT EXISTS (SELECT 1 FROM dbo.sysjobservers WHERE job_id=@JobId)
    EXEC dbo.sp_add_jobserver @job_id=@JobId, @server_name=N'(LOCAL)';

PRINT N'3Sigma TECSS instalado. Caché local actualizada y Job diario configurado a las 06:00.';
GO
