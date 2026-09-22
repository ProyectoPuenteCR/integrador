/* =============================================================
   CLEAR PLATAFORMA — Revertir permisos de lectura de Jobs
   Fecha: 17/09/2026. Quita SOLO los SELECT otorgados por
   CLEAR_ESTADO_JOBS_PERMISOS_20260917.sql. La pantalla seguirá
   abriendo y mostrará el aviso de permisos en la sección Jobs.
============================================================= */
USE [msdb];
GO
SET NOCOUNT ON;
DECLARE @Login sysname = N'fix';
DECLARE @User  sysname = (SELECT name FROM sys.database_principals WHERE sid = SUSER_SID(@Login));
DECLARE @sql   nvarchar(max);

IF @User IS NULL BEGIN PRINT N'No hay usuario en msdb para ' + @Login + N'. Nada que revertir.'; RETURN; END

SET @sql =
      N'REVOKE SELECT ON dbo.sysjobs         FROM ' + QUOTENAME(@User) + N';'
    + N'REVOKE SELECT ON dbo.sysjobhistory   FROM ' + QUOTENAME(@User) + N';'
    + N'REVOKE SELECT ON dbo.sysjobschedules FROM ' + QUOTENAME(@User) + N';'
    + N'REVOKE SELECT ON dbo.sysschedules    FROM ' + QUOTENAME(@User) + N';'
    + N'REVOKE SELECT ON dbo.sysjobactivity  FROM ' + QUOTENAME(@User) + N';'
    + N'REVOKE SELECT ON dbo.syssessions     FROM ' + QUOTENAME(@User) + N';';
EXEC (@sql);
PRINT N'Permisos revocados para ' + @User + N'.';

/* OPCIONAL: eliminar el usuario de msdb SOLO si fue creado por el script
   de permisos y no se usa para otra cosa (verificar antes).
-- DROP USER [fix];
*/
GO
