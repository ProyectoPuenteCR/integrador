/*
   Reversión de la caché Top 20 24 h.
   Este script NO modifica dbo.FIXALARMS.

   Antes de ejecutarlo, restaurar los archivos PHP originales o cambiar en
   includes/screens.php la tabla de top20_24h nuevamente a:
   dbo.FIXALARMS_TOP20_24H
*/

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

USE [LC_MDB];
GO

DROP PROCEDURE IF EXISTS dbo.SP_CLEAR_ACTUALIZAR_CACHE_TOP20_24H;
DROP TABLE IF EXISTS dbo.CLEAR_CACHE_TOP20_24H;
GO
