USE [msdb];
GO
/* Corrección puntual del error 102 confirmado el 26/10/2026 a las 15:00.
   Por defecto sólo muestra la propuesta. Cambiar @Aplicar a 1 tras revisarla.
   No ejecuta el job, no cambia horarios y no modifica procedimientos ni datos.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;
DECLARE @Aplicar bit = 0;
DECLARE @JobId uniqueidentifier, @StepId int, @Actual nvarchar(max), @Nuevo nvarchar(max);
DECLARE @Ret int, @LF nchar(1) = NCHAR(10);

IF (SELECT COUNT(*) FROM dbo.sysjobs j INNER JOIN dbo.sysjobsteps s ON s.job_id=j.job_id
    WHERE j.name=N'CLEAR - Histórico TECSS diario 06hs' AND s.step_name=N'Guardar histórico TECSS') <> 1
BEGIN
    THROW 50010, 'No se encontró un único job/paso esperado. No se modifica nada.', 1;
END;
SELECT @JobId=j.job_id,@StepId=s.step_id,@Actual=s.command
FROM dbo.sysjobs j INNER JOIN dbo.sysjobsteps s ON s.job_id=j.job_id
WHERE j.name=N'CLEAR - Histórico TECSS diario 06hs' AND s.step_name=N'Guardar histórico TECSS';

DECLARE @Antes nvarchar(max) = N'IF OBJECT_ID(N''dbo.SP_TECSS_GUARDAR_HISTORICO'', N''P'') IS NULL'
    + @LF + N'    ;THROW 50002, ''No existe dbo.SP_TECSS_GUARDAR_HISTORICO.'', 1;';
DECLARE @Despues nvarchar(max) = N'IF OBJECT_ID(N''dbo.SP_TECSS_GUARDAR_HISTORICO'', N''P'') IS NULL'
    + @LF + N'BEGIN' + @LF + N'    THROW 50002, ''No existe dbo.SP_TECSS_GUARDAR_HISTORICO.'', 1;'
    + @LF + N'END;';
SET @Nuevo = REPLACE(REPLACE(@Actual,NCHAR(13)+@LF,@LF), @Antes, @Despues);
IF CHARINDEX(@Antes, REPLACE(@Actual,NCHAR(13)+@LF,@LF))=0
BEGIN
    THROW 50011, 'El paso no coincide con el error conocido. Revisar el comando antes de modificarlo.', 1;
END;
SELECT j.name AS JOB,@StepId AS PASO,@Actual AS COMANDO_ANTERIOR,@Nuevo AS COMANDO_PROPUESTO,@Aplicar AS APLICAR
FROM dbo.sysjobs j WHERE j.job_id=@JobId;
IF @Aplicar=0 RETURN;

BEGIN TRY
    BEGIN TRANSACTION;
    IF NOT EXISTS (SELECT 1 FROM dbo.sysjobsteps WITH (UPDLOCK,HOLDLOCK)
        WHERE job_id=@JobId AND step_id=@StepId AND command COLLATE Latin1_General_100_BIN2=@Actual COLLATE Latin1_General_100_BIN2)
    BEGIN
        THROW 50012, 'El paso cambió durante la revisión. Volver a revisar.', 1;
    END;
    EXEC @Ret=dbo.sp_update_jobstep @job_id=@JobId,@step_id=@StepId,@command=@Nuevo;
    IF @Ret<>0
    BEGIN
        THROW 50013, 'No se pudo actualizar el comando del paso.', 1;
    END;
    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
PRINT N'Comando corregido. No se ejecutó el job ni se modificó el horario.';
GO
