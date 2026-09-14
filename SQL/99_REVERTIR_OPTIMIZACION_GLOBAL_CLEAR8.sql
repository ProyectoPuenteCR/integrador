USE [msdb];
GO
IF EXISTS(SELECT 1 FROM dbo.sysjobs WHERE name=N'CLEAR - Caché operativa cada 5 minutos')
  EXEC dbo.sp_delete_job @job_name=N'CLEAR - Caché operativa cada 5 minutos',@delete_unused_schedule=1;
GO

USE [LC_MDB];
GO
IF OBJECT_ID(N'dbo.SP_CLEAR_ACTUALIZAR_CACHE_OPERATIVA',N'P') IS NOT NULL
  DROP PROCEDURE dbo.SP_CLEAR_ACTUALIZAR_CACHE_OPERATIVA;
GO
IF OBJECT_ID(N'dbo.CLEAR_CACHE_OPERATIVA',N'U') IS NOT NULL
  DROP TABLE dbo.CLEAR_CACHE_OPERATIVA;
GO

PRINT N'Reversión de caché operativa completada. dbo.FIXALARMS no fue modificada.';
GO

