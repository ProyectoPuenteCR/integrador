USE master;
GO

/*
  CLEAR - Query 164
  Permisos para el login utilizado por la web.
  Compatible con SQL Server 2016.

  El login configurado actualmente por CLEAR es "fix".
  Si cambia en config.php, modificar solo @LoginWeb.
*/

DECLARE @LoginWeb sysname = N'fix';

IF SUSER_ID(@LoginWeb) IS NULL
BEGIN
    RAISERROR('El login SQL configurado para CLEAR no existe en el servidor.', 16, 1);
    RETURN;
END;

SELECT
    sp.name AS LOGIN_SQL,
    sp.type_desc,
    sp.is_disabled
FROM sys.server_principals AS sp
WHERE sp.name = @LoginWeb;
GO

USE LC_MDB;
GO

DECLARE @LoginWeb sysname = N'fix';
DECLARE @LoginSid varbinary(85) = SUSER_SID(@LoginWeb);
DECLARE @UsuarioBD sysname = NULL;
DECLARE @PropietarioBD sysname = NULL;
DECLARE @sql nvarchar(max);

SELECT @PropietarioBD = SUSER_SNAME(owner_sid)
FROM sys.databases
WHERE name = DB_NAME();

SELECT TOP (1)
    @UsuarioBD = dp.name
FROM sys.database_principals AS dp
WHERE dp.sid = @LoginSid
  AND dp.principal_id > 4;

SELECT
    @LoginWeb AS LOGIN_SQL,
    @UsuarioBD AS USUARIO_EN_LC_MDB,
    @PropietarioBD AS PROPIETARIO_BD;

/* Si el login es propietario de LC_MDB, entra como dbo y no requiere GRANT. */
IF @PropietarioBD = @LoginWeb
BEGIN
    PRINT 'El login de CLEAR es propietario de LC_MDB y se conecta como dbo. No requiere GRANT adicional.';
END
ELSE
BEGIN
    /* Si existe un usuario con el mismo nombre pero SID antiguo, remapearlo. */
    IF @UsuarioBD IS NULL AND USER_ID(@LoginWeb) IS NOT NULL
    BEGIN
        SET @sql = N'ALTER USER ' + QUOTENAME(@LoginWeb) + N' WITH LOGIN = ' + QUOTENAME(@LoginWeb) + N';';
        EXEC sys.sp_executesql @sql;
        SET @UsuarioBD = @LoginWeb;
    END;

    /* Si todavía no hay usuario de base, crearlo para el login. */
    IF @UsuarioBD IS NULL
    BEGIN
        SET @sql = N'CREATE USER ' + QUOTENAME(@LoginWeb) + N' FOR LOGIN ' + QUOTENAME(@LoginWeb) + N';';
        EXEC sys.sp_executesql @sql;
        SET @UsuarioBD = @LoginWeb;
    END;

    SET @sql = N'GRANT SELECT ON OBJECT::dbo.CLEAR_API_Q164_CACHE TO ' + QUOTENAME(@UsuarioBD) + N';';
    EXEC sys.sp_executesql @sql;

    IF OBJECT_ID(N'dbo.CLEAR_API_Q164', N'V') IS NOT NULL
    BEGIN
        SET @sql = N'GRANT SELECT ON OBJECT::dbo.CLEAR_API_Q164 TO ' + QUOTENAME(@UsuarioBD) + N';';
        EXEC sys.sp_executesql @sql;
    END;

    IF OBJECT_ID(N'dbo.CLEAR_API_Q164_ULTIMO', N'V') IS NOT NULL
    BEGIN
        SET @sql = N'GRANT SELECT ON OBJECT::dbo.CLEAR_API_Q164_ULTIMO TO ' + QUOTENAME(@UsuarioBD) + N';';
        EXEC sys.sp_executesql @sql;
    END;

    PRINT 'Permisos de lectura de Query 164 otorgados correctamente.';
END;
GO

/* Verificacion real usando el mismo LOGIN de la web. */
USE master;
GO
EXECUTE AS LOGIN = 'fix';
USE LC_MDB;

SELECT
    SUSER_SNAME() AS LOGIN_EN_PRUEBA,
    USER_NAME() AS USUARIO_EN_LC_MDB,
    COUNT(*) AS REGISTROS_Q164,
    COUNT(DISTINCT UPPER(LTRIM(RTRIM(POZO)))) AS POZOS_Q164,
    MAX(FECHA_ACTUALIZACION) AS ULTIMA_ACTUALIZACION
FROM dbo.CLEAR_API_Q164_CACHE;

REVERT;
GO
