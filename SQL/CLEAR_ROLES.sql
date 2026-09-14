-- =============================================================
-- CLEAR PLATAFORMA — Crear tabla de roles
-- Correr UNA SOLA VEZ en SQL Server Management Studio (SSMS),
-- conectado a la base LC_MDB, SOLO si la app no pudo crearla sola.
-- =============================================================

USE [LC_MDB];
GO

IF NOT EXISTS (SELECT * FROM sysobjects WHERE name = 'CLEAR_ROLES' AND xtype = 'U')
BEGIN
    CREATE TABLE dbo.CLEAR_ROLES (
        USUARIO NVARCHAR(128) NOT NULL PRIMARY KEY,
        ROL     NVARCHAR(32)  NOT NULL DEFAULT 'operador'
    );
    PRINT 'Tabla CLEAR_ROLES creada.';
END
ELSE
    PRINT 'La tabla CLEAR_ROLES ya existe.';
GO

-- Opcional: marcar tu usuario administrador inicial.
-- Cambiá 'CLEAR' si tu admin se llama distinto.
IF NOT EXISTS (SELECT * FROM dbo.CLEAR_ROLES WHERE USUARIO = 'CLEAR')
    INSERT INTO dbo.CLEAR_ROLES (USUARIO, ROL) VALUES ('CLEAR', 'admin');
GO

-- =============================================================
-- Tabla de configuración (conexión PI Web API)
-- =============================================================
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name = 'CLEAR_CONFIG' AND xtype = 'U')
BEGIN
    CREATE TABLE dbo.CLEAR_CONFIG (
        CLAVE NVARCHAR(64)  NOT NULL PRIMARY KEY,
        VALOR NVARCHAR(512) NULL
    );
    PRINT 'Tabla CLEAR_CONFIG creada.';
END
ELSE
    PRINT 'La tabla CLEAR_CONFIG ya existe.';
GO
