USE [LC_MDB];
GO

IF NOT EXISTS (SELECT * FROM sysobjects WHERE name = 'CLEAR_USER_PERMISSIONS' AND xtype = 'U')
BEGIN
    CREATE TABLE dbo.CLEAR_USER_PERMISSIONS (
        USUARIO NVARCHAR(128) NOT NULL PRIMARY KEY,
        COMENTARIOS_LECTURA BIT NOT NULL CONSTRAINT DF_CLEAR_PERM_LECTURA DEFAULT (1),
        COMENTARIOS_ESCRITURA BIT NOT NULL CONSTRAINT DF_CLEAR_PERM_ESCRITURA DEFAULT (0)
    );
    PRINT 'Tabla CLEAR_USER_PERMISSIONS creada.';
END
ELSE
    PRINT 'La tabla CLEAR_USER_PERMISSIONS ya existe.';
GO

-- Los usuarios sin fila conservan por compatibilidad: lectura permitida y escritura bloqueada.
-- Los administradores tienen lectura y escritura completas desde la aplicación.
