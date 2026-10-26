/*
  Cambio puntual para una instalación existente. No reinstala tablas, índices
  ni procedimientos y no dispara ninguna captura al ejecutar este archivo.
  Horarios del servidor: 03, 07, 11, 15, 19 y 23 h.
  Referencia solicitada: 07:00; se incluye 03:00 para mantener 4 h de noche.
  Conserva los nombres heredados para no duplicar el job ni su horario.
*/
USE [msdb];
GO
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

DECLARE @JobId uniqueidentifier;
DECLARE @ScheduleId int;
DECLARE @Result int;

SELECT @JobId=job_id FROM dbo.sysjobs
WHERE name=N'CLEAR - Histórico TECSS diario 06hs';

IF @JobId IS NULL
    THROW 50010, 'No existe el job TECSS esperado. No se cambio el horario.', 1;

SELECT @ScheduleId=s.schedule_id
FROM dbo.sysschedules s
JOIN dbo.sysjobschedules js ON js.schedule_id=s.schedule_id
WHERE js.job_id=@JobId AND s.name=N'CLEAR - TECSS todos los días 06hs';

IF @ScheduleId IS NULL
    THROW 50011, 'No existe el horario TECSS esperado asociado al job.', 1;

IF EXISTS (SELECT 1 FROM dbo.sysjobschedules WHERE schedule_id=@ScheduleId AND job_id<>@JobId)
    THROW 50012, 'El horario esta compartido con otro job. Se cancelo el cambio.', 1;

IF EXISTS (SELECT 1 FROM dbo.sysjobschedules WHERE job_id=@JobId AND schedule_id<>@ScheduleId)
    THROW 50013, 'El job tiene otros horarios. Revisarlos antes de cambiar la frecuencia.', 1;

BEGIN TRY
    BEGIN TRANSACTION;
    EXEC @Result=dbo.sp_update_schedule
        @schedule_id=@ScheduleId,
        @enabled=1,
        @freq_type=4,
        @freq_interval=1,
        @freq_subday_type=8,
        @freq_subday_interval=4,
        @active_start_time=030000,
        @active_end_time=235959;
    IF @Result<>0
        THROW 50014, 'No se pudo actualizar el horario TECSS.', 1;

    EXEC @Result=dbo.sp_update_job
        @job_id=@JobId,
        @description=N'Guarda el histórico TECSS y actualiza la caché 3Sigma cada 4 horas: 03, 07, 11, 15, 19 y 23 h (hora del servidor).';
    IF @Result<>0
        THROW 50015, 'No se pudo actualizar la descripcion del job TECSS.', 1;
    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT j.name AS JOB, j.enabled AS JOB_HABILITADO,
       s.name AS HORARIO, s.enabled AS HORARIO_HABILITADO,
       s.freq_type, s.freq_interval, s.freq_subday_type, s.freq_subday_interval,
       s.active_start_time, s.active_end_time
FROM dbo.sysjobs j
JOIN dbo.sysjobschedules js ON js.job_id=j.job_id
JOIN dbo.sysschedules s ON s.schedule_id=js.schedule_id
WHERE j.job_id=@JobId;
GO
