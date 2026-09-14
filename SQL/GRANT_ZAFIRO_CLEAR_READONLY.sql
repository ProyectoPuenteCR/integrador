/*
  CLEAR - Permiso mínimo de lectura para Zafiro
  Servidor: 10.17.40.37
  Base origen: LCMDB
  Usuario utilizado por IIS/CLEAR: fix

  Ejecutar en SQL Server Management Studio con una cuenta administradora.
  Este script NO modifica dbo.FIXALARMS ni los datos de Zafiro.
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

USE [master];

IF DB_ID(N'LCMDB') IS NULL
    THROW 51000, 'No existe la base LCMDB en este servidor.', 1;

IF SUSER_ID(N'fix') IS NULL
    THROW 51001, 'No existe el login SQL fix en este servidor.', 1;

USE [LCMDB];

IF OBJECT_ID(N'dbo.VW_CLEAR_ZAFIRO_TELEMETRIA') IS NULL
    THROW 51002, 'No existe dbo.VW_CLEAR_ZAFIRO_TELEMETRIA en LCMDB.', 1;

BEGIN TRY
    BEGIN TRANSACTION;

    IF DATABASE_PRINCIPAL_ID(N'fix') IS NULL
        CREATE USER [fix] FOR LOGIN [fix];
    ELSE
        ALTER USER [fix] WITH LOGIN = [fix];

    GRANT CONNECT TO [fix];
    GRANT SELECT ON OBJECT::[dbo].[VW_CLEAR_ZAFIRO_TELEMETRIA] TO [fix];
    GRANT VIEW DEFINITION ON OBJECT::[dbo].[VW_CLEAR_ZAFIRO_TELEMETRIA] TO [fix];

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

PRINT 'Permisos de solo lectura aplicados a LCMDB.dbo.VW_CLEAR_ZAFIRO_TELEMETRIA.';

BEGIN TRY
    EXECUTE AS USER = N'fix';

    SELECT TOP (5) *
    FROM [dbo].[VW_CLEAR_ZAFIRO_TELEMETRIA];

    REVERT;
END TRY
BEGIN CATCH
    IF USER_NAME() = N'fix' REVERT;
    THROW;
END CATCH;

PRINT 'Verificación terminada. Recargue Monitoreo Pozos con Ctrl+F5.';
