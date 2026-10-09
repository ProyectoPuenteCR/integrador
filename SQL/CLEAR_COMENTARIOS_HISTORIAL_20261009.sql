/* CLEAR - 2026-10-09. Ejecutar una vez en LC_MDB antes de publicar la interfaz. */
IF OBJECT_ID(N'dbo.CLEAR_COMENTARIOS_HISTORIAL',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.CLEAR_COMENTARIOS_HISTORIAL(
  ID bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
  ORIGEN nvarchar(30) NOT NULL,
  COMENTARIO_ID bigint NOT NULL,
  ACCION nvarchar(20) NOT NULL,
  TEXTO_ANTERIOR nvarchar(max) NOT NULL,
  TEXTO_NUEVO nvarchar(max) NULL,
  USUARIO nvarchar(128) NOT NULL,
  FECHA datetime2(0) NOT NULL CONSTRAINT DF_CLEAR_COMENTARIOS_HISTORIAL_FECHA DEFAULT SYSDATETIME()
 );
 CREATE INDEX IX_CLEAR_COMENTARIOS_HISTORIAL_REGISTRO ON dbo.CLEAR_COMENTARIOS_HISTORIAL(ORIGEN,COMENTARIO_ID,ID DESC);
END;
GO
/* Create the procedure explicitly before ALTER to avoid SQL Server name-resolution problems. */
IF OBJECT_ID(N'dbo.CLEAR_COMENTARIO_CAMBIAR',N'P') IS NULL
 EXEC(N'CREATE PROCEDURE dbo.CLEAR_COMENTARIO_CAMBIAR AS BEGIN SET NOCOUNT ON; END');
GO
ALTER PROCEDURE dbo.CLEAR_COMENTARIO_CAMBIAR
 @Origen nvarchar(30), @Id bigint, @Accion nvarchar(20), @Texto nvarchar(max), @Usuario nvarchar(128),
 @Esperado nvarchar(max)
AS
BEGIN
 SET NOCOUNT ON;
 SET XACT_ABORT ON;
 IF @Origen NOT IN (N'alarmas',N'novedades',N'instalaciones',N'pozos') OR @Accion NOT IN (N'EDITAR',N'ELIMINAR')
  THROW 51001,N'Origen o acción no permitida',1;
 IF NULLIF(LTRIM(RTRIM(@Usuario)),N'') IS NULL THROW 51002,N'Usuario obligatorio',1;
 IF @Accion=N'EDITAR' AND NULLIF(LTRIM(RTRIM(@Texto)),N'') IS NULL THROW 51003,N'El comentario no puede quedar vacío',1;
 DECLARE @Tabla sysname=CASE @Origen WHEN N'alarmas' THEN N'FIXALARMS_COMENTARIOS'
   WHEN N'novedades' THEN N'CLEAR_NOVEDADES_SEMANALES_COMENTARIOS'
   WHEN N'instalaciones' THEN N'FIXALARMS_INSTALACION_COMENTARIOS_SEMANALES'
   WHEN N'pozos' THEN N'FIXALARMS_POZO_COMENTARIOS_SEMANALES' END;
 DECLARE @Sql nvarchar(max),@Anterior nvarchar(max),@Existe int=0;
 BEGIN TRY
  BEGIN TRAN;
  SET @Sql=N'SELECT @Anterior=CONVERT(nvarchar(max),COMENTARIO),@Existe=1 FROM dbo.'+QUOTENAME(@Tabla)+
   N' WITH (UPDLOCK,HOLDLOCK) WHERE ID=@Id AND ACTIVO=1';
  EXEC sp_executesql @Sql,N'@Id bigint,@Anterior nvarchar(max) OUTPUT,@Existe int OUTPUT',
      @Id=@Id,@Anterior=@Anterior OUTPUT,@Existe=@Existe OUTPUT;
  IF @Existe=0 THROW 51004,N'Comentario inexistente o eliminado',1;
  IF ISNULL(@Anterior,N'') COLLATE DATABASE_DEFAULT <> ISNULL(@Esperado,N'') COLLATE DATABASE_DEFAULT
    THROW 51005,N'El comentario fue actualizado por otro usuario. Recargá la pantalla.',1;
  IF @Accion=N'EDITAR' AND @Anterior COLLATE DATABASE_DEFAULT = @Texto COLLATE DATABASE_DEFAULT
    THROW 51006,N'No hay cambios para guardar',1;
  INSERT dbo.CLEAR_COMENTARIOS_HISTORIAL(ORIGEN,COMENTARIO_ID,ACCION,TEXTO_ANTERIOR,TEXTO_NUEVO,USUARIO)
   VALUES(@Origen,@Id,@Accion,@Anterior,CASE WHEN @Accion=N'EDITAR' THEN @Texto END,@Usuario);
  IF @Accion=N'ELIMINAR'
   SET @Sql=N'UPDATE dbo.'+QUOTENAME(@Tabla)+N' SET ACTIVO=0 WHERE ID=@Id AND ACTIVO=1';
  ELSE IF @Origen=N'alarmas'
   SET @Sql=N'UPDATE dbo.'+QUOTENAME(@Tabla)+N' SET COMENTARIO=@Texto,FECHA_MODIFICACION=SYSDATETIME() WHERE ID=@Id AND ACTIVO=1';
  ELSE
   SET @Sql=N'UPDATE dbo.'+QUOTENAME(@Tabla)+N' SET COMENTARIO=@Texto,USUARIO_MODIFICACION=@Usuario,FECHA_MODIFICACION=SYSDATETIME() WHERE ID=@Id AND ACTIVO=1';
  EXEC sp_executesql @Sql,N'@Id bigint,@Texto nvarchar(max),@Usuario nvarchar(128)',@Id=@Id,@Texto=@Texto,@Usuario=@Usuario;
  COMMIT;
  SELECT CAST(1 AS int) OK;
 END TRY
 BEGIN CATCH
  IF @@TRANCOUNT>0 ROLLBACK;
  THROW;
 END CATCH
END;
GO
