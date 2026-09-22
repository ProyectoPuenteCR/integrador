/* =============================================================
   CLEAR PLATAFORMA — Permisos de LECTURA para ver el estado de Jobs
   Fecha: 17/09/2026
   Ejecutar UNA vez en SSMS, en 10.17.40.37, con un usuario sysadmin.

   Qué hace:
   - Crea (si no existe) el usuario de msdb para el login que usa la web.
   - Otorga SELECT sobre 6 tablas de msdb. Nada más.
   Qué NO hace:
   - No otorga permisos para crear, iniciar, detener ni modificar Jobs.
   - No toca LC_MDB, ni Jobs existentes, ni roles del servidor.
   Si el login ya es sysadmin, termina sin cambios.
============================================================= */
USE [msdb];
GO
SET NOCOUNT ON;

DECLARE @Login sysname = N'fix';   -- <== mismo usuario que config.php ('db' => 'user')
DECLARE @User  sysname;
DECLARE @sql   nvarchar(max);

IF SUSER_SID(@Login) IS NULL
BEGIN
    RAISERROR(N'El login %s no existe en el servidor. Revisar el nombre.', 16, 1, @Login);
    RETURN;
END

IF IS_SRVROLEMEMBER(N'sysadmin', @Login) = 1
BEGIN
    PRINT N'El login ' + @Login + N' ya es sysadmin: no hace falta otorgar permisos.';
    RETURN;
END

SELECT @User = name FROM sys.database_principals WHERE sid = SUSER_SID(@Login);

IF @User IS NULL
BEGIN
    SET @User = @Login;
    SET @sql = N'CREATE USER ' + QUOTENAME(@User) + N' FOR LOGIN ' + QUOTENAME(@Login) + N';';
    EXEC (@sql);
    PRINT N'Usuario creado en msdb: ' + @User;
END
ELSE
    PRINT N'Usuario existente en msdb: ' + @User;

SET @sql =
      N'GRANT SELECT ON dbo.sysjobs         TO ' + QUOTENAME(@User) + N';'
    + N'GRANT SELECT ON dbo.sysjobhistory   TO ' + QUOTENAME(@User) + N';'
    + N'GRANT SELECT ON dbo.sysjobschedules TO ' + QUOTENAME(@User) + N';'
    + N'GRANT SELECT ON dbo.sysschedules    TO ' + QUOTENAME(@User) + N';'
    + N'GRANT SELECT ON dbo.sysjobactivity  TO ' + QUOTENAME(@User) + N';'
    + N'GRANT SELECT ON dbo.syssessions     TO ' + QUOTENAME(@User) + N';';
EXEC (@sql);
PRINT N'Permisos de lectura otorgados.';

/* OPCIONAL: para que la pantalla muestre el estado real del servicio
   SQL Server Agent (sys.dm_server_services). Sin esto la pantalla lo
   deduce por la última ejecución registrada. VIEW SERVER STATE permite
   ver DMVs de todo el servidor: habilitar solo si se acepta ese alcance.
USE [master];
GRANT VIEW SERVER STATE TO [fix];
*/
GO

/* ---- Verificación: ejecutar como la cuenta web ---- */
USE [msdb];
GO
EXECUTE AS LOGIN = N'fix';   -- <== mismo login
SELECT TOP (20) name AS Job, enabled AS Habilitado FROM msdb.dbo.sysjobs ORDER BY name;
SELECT COUNT(*) AS FilasHistorial FROM msdb.dbo.sysjobhistory;
REVERT;
GO
