USE [LC_MDB];
GO
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* Índice acotado para las lecturas comunes de comentarios centralizados.
   No modifica datos, jobs ni procedimientos. Puede ejecutarse más de una vez. */
IF OBJECT_ID(N'dbo.FIXALARMS_COMENTARIOS', N'U') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1
       FROM sys.indexes
       WHERE object_id = OBJECT_ID(N'dbo.FIXALARMS_COMENTARIOS')
         AND name = N'IX_FIXALARMS_COMENTARIOS_TAG_ACTIVO_MOTIVO'
   )
BEGIN
    CREATE INDEX IX_FIXALARMS_COMENTARIOS_TAG_ACTIVO_MOTIVO
        ON dbo.FIXALARMS_COMENTARIOS (TAG_FIX, ACTIVO, MOTIVO)
        INCLUDE (COMENTARIO, USUARIO_CARGA, FECHA_CARGA, FECHA_MODIFICACION);
END;
GO

/* Verificación de sólo lectura. */
SELECT
    i.name AS Indice,
    i.type_desc AS Tipo,
    i.is_disabled AS Deshabilitado
FROM sys.indexes AS i
WHERE i.object_id = OBJECT_ID(N'dbo.FIXALARMS_COMENTARIOS')
  AND i.name = N'IX_FIXALARMS_COMENTARIOS_TAG_ACTIVO_MOTIVO';
GO
