/* CLEAR - Exclusiones globales de alarmas
   Este script es opcional: la aplicación intenta crear estos objetos automáticamente.
   Ejecutarlo manualmente con un usuario con permisos DDL si la pantalla informa un error. */
IF OBJECT_ID('dbo.CLEAR_ALARM_FILTERS','U') IS NULL
BEGIN
  CREATE TABLE dbo.CLEAR_ALARM_FILTERS(
    ID INT IDENTITY(1,1) PRIMARY KEY,
    CAMPO NVARCHAR(20) NOT NULL DEFAULT 'AMBOS',
    MODO NVARCHAR(20) NOT NULL DEFAULT 'CONTIENE',
    VALOR NVARCHAR(500) NOT NULL,
    ACTIVO BIT NOT NULL DEFAULT 1,
    FECHA_ALTA DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    USUARIO_ALTA NVARCHAR(128) NULL
  );
END;
IF NOT EXISTS (SELECT 1 FROM dbo.CLEAR_ALARM_FILTERS WHERE VALOR=N'Shutdown attempt in progress...PTALH03')
  INSERT INTO dbo.CLEAR_ALARM_FILTERS(CAMPO,MODO,VALOR,ACTIVO,USUARIO_ALTA)
  VALUES(N'DESCRIPCION',N'CONTIENE',N'Shutdown attempt in progress...PTALH03',1,N'SISTEMA');
