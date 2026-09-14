USE [LC_MDB];
GO
SET NOCOUNT ON;
SET XACT_ABORT ON;

PRINT '=== CLEAR - APLICACION DE INDICES SEGUROS ===';
PRINT 'Inicio: ' + CONVERT(varchar(19),GETDATE(),120);

/* FIXALARMS: búsquedas y ordenamientos por fecha */
IF OBJECT_ID('dbo.FIXALARMS','U') IS NOT NULL
AND COL_LENGTH('dbo.FIXALARMS','ALM_NATIVETIMEIN') IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.FIXALARMS') AND name='IX_CLEAR_OPT_FIXALARMS_FECHA')
BEGIN
    DECLARE @incFecha nvarchar(max)='';
    SELECT @incFecha = @incFecha + CASE WHEN @incFecha='' THEN '' ELSE ',' END + QUOTENAME(v.Col)
    FROM (VALUES
      ('ALM_TAGNAME'),('ALM_DESCR'),('ALM_ALMPRIORITY'),('ALM_ALMSTATUS'),
      ('ALM_MSGTYPE'),('ALM_VALUE'),('ALM_ALMEXTFLD2'),('ALM_LASTTIMEIN')
    ) v(Col)
    WHERE COL_LENGTH('dbo.FIXALARMS',v.Col) IS NOT NULL;

    DECLARE @sqlFecha nvarchar(max)=N'CREATE NONCLUSTERED INDEX [IX_CLEAR_OPT_FIXALARMS_FECHA] ON dbo.FIXALARMS ([ALM_NATIVETIMEIN] DESC)'
      + CASE WHEN @incFecha<>'' THEN N' INCLUDE ('+@incFecha+N')' ELSE N'' END + N';';
    PRINT @sqlFecha;
    EXEC sys.sp_executesql @sqlFecha;
END
ELSE PRINT 'Omitido IX_CLEAR_OPT_FIXALARMS_FECHA (no aplica o ya existe).';
GO

/* FIXALARMS: búsquedas por TAG y rango de fecha. Solo si TAG es indexable. */
IF OBJECT_ID('dbo.FIXALARMS','U') IS NOT NULL
AND COL_LENGTH('dbo.FIXALARMS','ALM_TAGNAME') IS NOT NULL
AND COL_LENGTH('dbo.FIXALARMS','ALM_NATIVETIMEIN') IS NOT NULL
AND EXISTS (
    SELECT 1 FROM sys.columns c
    JOIN sys.types t ON t.user_type_id=c.user_type_id
    WHERE c.object_id=OBJECT_ID('dbo.FIXALARMS') AND c.name='ALM_TAGNAME'
      AND c.max_length<>-1 AND t.name NOT IN ('text','ntext','image','xml')
)
AND NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.FIXALARMS') AND name='IX_CLEAR_OPT_FIXALARMS_TAG_FECHA')
BEGIN
    DECLARE @incTag nvarchar(max)='';
    SELECT @incTag = @incTag + CASE WHEN @incTag='' THEN '' ELSE ',' END + QUOTENAME(v.Col)
    FROM (VALUES ('ALM_DESCR'),('ALM_ALMPRIORITY'),('ALM_ALMSTATUS'),('ALM_MSGTYPE'),('ALM_VALUE'),('ALM_ALMEXTFLD2')) v(Col)
    WHERE COL_LENGTH('dbo.FIXALARMS',v.Col) IS NOT NULL;

    DECLARE @sqlTag nvarchar(max)=N'CREATE NONCLUSTERED INDEX [IX_CLEAR_OPT_FIXALARMS_TAG_FECHA] ON dbo.FIXALARMS ([ALM_TAGNAME] ASC,[ALM_NATIVETIMEIN] DESC)'
      + CASE WHEN @incTag<>'' THEN N' INCLUDE ('+@incTag+N')' ELSE N'' END + N';';
    PRINT @sqlTag;
    EXEC sys.sp_executesql @sqlTag;
END
ELSE PRINT 'Omitido IX_CLEAR_OPT_FIXALARMS_TAG_FECHA (no aplica o ya existe).';
GO

/* FIXALARMS: filtros de pozo y fecha */
IF OBJECT_ID('dbo.FIXALARMS','U') IS NOT NULL
AND COL_LENGTH('dbo.FIXALARMS','ALM_ALMEXTFLD2') IS NOT NULL
AND COL_LENGTH('dbo.FIXALARMS','ALM_NATIVETIMEIN') IS NOT NULL
AND EXISTS (
    SELECT 1 FROM sys.columns c JOIN sys.types t ON t.user_type_id=c.user_type_id
    WHERE c.object_id=OBJECT_ID('dbo.FIXALARMS') AND c.name='ALM_ALMEXTFLD2'
      AND c.max_length<>-1 AND t.name NOT IN ('text','ntext','image','xml')
)
AND NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.FIXALARMS') AND name='IX_CLEAR_OPT_FIXALARMS_POZO_FECHA')
BEGIN
    DECLARE @incPozo nvarchar(max)='';
    SELECT @incPozo = @incPozo + CASE WHEN @incPozo='' THEN '' ELSE ',' END + QUOTENAME(v.Col)
    FROM (VALUES ('ALM_TAGNAME'),('ALM_DESCR'),('ALM_ALMPRIORITY'),('ALM_ALMSTATUS'),('ALM_VALUE')) v(Col)
    WHERE COL_LENGTH('dbo.FIXALARMS',v.Col) IS NOT NULL;

    DECLARE @sqlPozo nvarchar(max)=N'CREATE NONCLUSTERED INDEX [IX_CLEAR_OPT_FIXALARMS_POZO_FECHA] ON dbo.FIXALARMS ([ALM_ALMEXTFLD2] ASC,[ALM_NATIVETIMEIN] DESC)'
      + CASE WHEN @incPozo<>'' THEN N' INCLUDE ('+@incPozo+N')' ELSE N'' END + N';';
    PRINT @sqlPozo;
    EXEC sys.sp_executesql @sqlPozo;
END
ELSE PRINT 'Omitido IX_CLEAR_OPT_FIXALARMS_POZO_FECHA (no aplica o ya existe).';
GO

/* BM_RTQP: último estado por pozo. No aplica si BM_RTQP es vista. */
IF OBJECT_ID('dbo.BM_RTQP','U') IS NOT NULL
AND COL_LENGTH('dbo.BM_RTQP','POZO') IS NOT NULL
AND COL_LENGTH('dbo.BM_RTQP','Fecha') IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.BM_RTQP') AND name='IX_CLEAR_OPT_BMRTQP_POZO_FECHA')
BEGIN
    DECLARE @incBm nvarchar(max)='';
    SELECT @incBm = @incBm + CASE WHEN @incBm='' THEN '' ELSE ',' END + QUOTENAME(v.Col)
    FROM (VALUES ('BATERIA'),('ESTADO-GRAL'),('ESTADO'),('ECO'),('PANTALLA'),('CARTAS')) v(Col)
    WHERE COL_LENGTH('dbo.BM_RTQP',v.Col) IS NOT NULL;

    DECLARE @sqlBm nvarchar(max)=N'CREATE NONCLUSTERED INDEX [IX_CLEAR_OPT_BMRTQP_POZO_FECHA] ON dbo.BM_RTQP ([POZO] ASC,[Fecha] DESC)'
      + CASE WHEN @incBm<>'' THEN N' INCLUDE ('+@incBm+N')' ELSE N'' END + N';';
    PRINT @sqlBm;
    EXEC sys.sp_executesql @sqlBm;
END
ELSE PRINT 'Omitido IX_CLEAR_OPT_BMRTQP_POZO_FECHA (BM_RTQP puede ser vista, faltan columnas o ya existe).';
GO

/* BM_RTQP: filtros por batería */
IF OBJECT_ID('dbo.BM_RTQP','U') IS NOT NULL
AND COL_LENGTH('dbo.BM_RTQP','BATERIA') IS NOT NULL
AND COL_LENGTH('dbo.BM_RTQP','POZO') IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.BM_RTQP') AND name='IX_CLEAR_OPT_BMRTQP_BATERIA_POZO')
BEGIN
    DECLARE @incBat nvarchar(max)='';
    SELECT @incBat = @incBat + CASE WHEN @incBat='' THEN '' ELSE ',' END + QUOTENAME(v.Col)
    FROM (VALUES ('Fecha'),('ESTADO-GRAL'),('ESTADO'),('ECO')) v(Col)
    WHERE COL_LENGTH('dbo.BM_RTQP',v.Col) IS NOT NULL;

    DECLARE @sqlBat nvarchar(max)=N'CREATE NONCLUSTERED INDEX [IX_CLEAR_OPT_BMRTQP_BATERIA_POZO] ON dbo.BM_RTQP ([BATERIA] ASC,[POZO] ASC)'
      + CASE WHEN @incBat<>'' THEN N' INCLUDE ('+@incBat+N')' ELSE N'' END + N';';
    PRINT @sqlBat;
    EXEC sys.sp_executesql @sqlBat;
END
ELSE PRINT 'Omitido IX_CLEAR_OPT_BMRTQP_BATERIA_POZO (BM_RTQP puede ser vista, faltan columnas o ya existe).';
GO

PRINT 'Fin: ' + CONVERT(varchar(19),GETDATE(),120);
PRINT 'Indices CLEAR creados:';
SELECT OBJECT_NAME(object_id) AS Tabla,name AS Indice,type_desc
FROM sys.indexes
WHERE name LIKE 'IX_CLEAR_OPT_%'
ORDER BY Tabla,Indice;
