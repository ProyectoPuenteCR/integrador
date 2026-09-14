USE [LC_MDB];
GO

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* =============================================================
   CLEAR — corrección SCADA Real time
   1) Conserva la hora local de FechaOPC/FechaActualizacion.
   2) Limpia comillas, NBSP y BOM de los TAG del colector.
   3) Amplía el umbral inicial de dato vencido a 15 minutos.

   Este script NO modifica la tabla fuente SCADA, PI_Points_Stage
   ni dbo.FIXALARMS.
============================================================= */

IF OBJECT_ID(N'dbo.CLEAR_SCADA_SETTINGS',N'U') IS NULL
    THROW 51230,N'Primero debe instalarse CLEAR_SCADA_REALTIME_20260908.sql.',1;
GO

/* Sólo migra el valor inicial incorrectamente corto. Si el administrador ya
   eligió otro umbral, se conserva exactamente su configuración. */
UPDATE dbo.CLEAR_SCADA_SETTINGS
   SET STALE_SECONDS=900,
       UPDATED_AT=SYSDATETIME(),
       UPDATED_BY=N'CORRECCION_FECHA_PI_20260908'
 WHERE ID=1 AND STALE_SECONDS=60;
GO

CREATE OR ALTER PROCEDURE dbo.SP_CLEAR_SCADA_CONFIGURAR_FUENTE
    @SourceSchema sysname,
    @SourceTable  sysname,
    @UpdatedBy    nvarchar(128)=N'SISTEMA'
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @SourceSchema=NULLIF(LTRIM(RTRIM(@SourceSchema)),N'');
    SET @SourceTable=NULLIF(LTRIM(RTRIM(@SourceTable)),N'');
    IF @SourceSchema IS NULL OR @SourceTable IS NULL
        THROW 51200,N'La tabla fuente SCADA es obligatoria.',1;

    DECLARE @ObjectId int=OBJECT_ID(QUOTENAME(@SourceSchema)+N'.'+QUOTENAME(@SourceTable),N'U');
    IF @ObjectId IS NULL
        THROW 51201,N'No se encontró la tabla fuente SCADA indicada.',1;

    DECLARE @Missing nvarchar(2000)=N'';
    ;WITH RequiredColumns AS
    (
        SELECT N'Nodo' AS NAME UNION ALL SELECT N'Tag' UNION ALL SELECT N'Descripcion'
        UNION ALL SELECT N'ItemValor' UNION ALL SELECT N'ValorNumerico' UNION ALL SELECT N'ValorTexto'
        UNION ALL SELECT N'Calidad' UNION ALL SELECT N'FechaOPC' UNION ALL SELECT N'FechaActualizacion'
    )
    SELECT @Missing=STUFF((SELECT N', '+R.NAME FROM RequiredColumns R
                           WHERE NOT EXISTS(SELECT 1 FROM sys.columns C WHERE C.object_id=@ObjectId AND C.name=R.NAME)
                           ORDER BY R.NAME FOR XML PATH(''),TYPE).value('.','nvarchar(max)'),1,2,N'');
    IF ISNULL(@Missing,N'')<>N''
        THROW 51202,N'La tabla fuente no contiene todas las columnas requeridas.',1;

    DECLARE @Qualified nvarchar(600)=QUOTENAME(@SourceSchema)+N'.'+QUOTENAME(@SourceTable);
    DECLARE @Sql nvarchar(max)=N'
ALTER VIEW dbo.CLEAR_SCADA_REALTIME_V
AS
SELECT
    NODO=CONVERT(nvarchar(128),S.[Nodo]) COLLATE DATABASE_DEFAULT,
    TAG=X.TAGTXT,
    DESCRIPCION=CONVERT(nvarchar(512),S.[Descripcion]) COLLATE DATABASE_DEFAULT,
    ITEM_VALOR=CONVERT(nvarchar(512),S.[ItemValor]) COLLATE DATABASE_DEFAULT,
    VALOR_NUMERICO=TRY_CONVERT(float,S.[ValorNumerico]),
    VALOR_TEXTO=CONVERT(nvarchar(512),S.[ValorTexto]) COLLATE DATABASE_DEFAULT,
    VALOR_MOSTRAR=COALESCE(NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(512),S.[ValorTexto]))),N''''),CONVERT(nvarchar(100),TRY_CONVERT(float,S.[ValorNumerico]))),
    CALIDAD=CONVERT(nvarchar(64),S.[Calidad]) COLLATE DATABASE_DEFAULT,
    FECHA_OPC=TRY_CONVERT(datetime2(3),S.[FechaOPC]),
    FECHA_ACTUALIZACION=TRY_CONVERT(datetime2(3),S.[FechaActualizacion]),
    BATERIA=COALESCE(NULLIF(M.BATERIA,N''''),D.BATERIA_DERIVADA),
    ZONA=COALESCE(NULLIF(M.ZONA,N''''),NULLIF(B.ZONA,N''''),N''Sin asignar''),
    AREA_EQUIPO=COALESCE(NULLIF(M.AREA_EQUIPO,N''''),D.AREA_DERIVADA,N''Sin asignar''),
    UNIDAD=ISNULL(M.UNIDAD,N''''),
    PI_POINT=COALESCE(NULLIF(M.PI_POINT,N''''),X.TAGTXT),
    PI_WEBID=ISNULL(M.PI_WEBID,N''''),
    PI_VISION_URL=ISNULL(M.PI_VISION_URL,N''''),
    ALARM_TAG=COALESCE(NULLIF(M.ALARM_TAG,N''''),X.TAGTXT)
FROM '+@Qualified+N' S
CROSS APPLY(SELECT LTRIM(RTRIM(REPLACE(REPLACE(REPLACE(CONVERT(nvarchar(255),S.[Tag]),NCHAR(34),N''''),NCHAR(160),N'' ''),NCHAR(65279),N''''))) COLLATE DATABASE_DEFAULT AS TAGTXT)X
CROSS APPLY(SELECT CHARINDEX(N''_'',X.TAGTXT) AS P1)P
CROSS APPLY(SELECT CASE WHEN P.P1>0 THEN CHARINDEX(N''_'',X.TAGTXT+N''_'',P.P1+1) ELSE 0 END AS P2)Q
CROSS APPLY(SELECT
    CASE WHEN P.P1>1 THEN LEFT(X.TAGTXT,P.P1-1) ELSE X.TAGTXT END AS BATERIA_DERIVADA,
    CASE WHEN P.P1>0 AND Q.P2>P.P1+1 THEN SUBSTRING(X.TAGTXT,P.P1+1,Q.P2-P.P1-1) ELSE N''Sin asignar'' END AS AREA_DERIVADA
)D
LEFT JOIN dbo.CLEAR_SCADA_TAG_MAP M ON M.TAG=X.TAGTXT
LEFT JOIN dbo.CLEAR_SCADA_BATERIA B ON B.BATERIA=COALESCE(NULLIF(M.BATERIA,N''''),D.BATERIA_DERIVADA)
WHERE ISNULL(M.ACTIVO,1)=1 AND ISNULL(B.ACTIVA,1)=1;';

    EXEC sys.sp_executesql @Sql;

    UPDATE dbo.CLEAR_SCADA_SETTINGS
       SET SOURCE_SCHEMA=@SourceSchema,SOURCE_TABLE=@SourceTable,UPDATED_AT=SYSDATETIME(),UPDATED_BY=@UpdatedBy
     WHERE ID=1;
END;
GO

DECLARE @SourceSchema sysname,@SourceTable sysname;
SELECT @SourceSchema=SOURCE_SCHEMA,@SourceTable=SOURCE_TABLE
FROM dbo.CLEAR_SCADA_SETTINGS WHERE ID=1;

IF NULLIF(@SourceTable,N'') IS NULL
    THROW 51231,N'No hay una tabla fuente configurada para SCADA Real time.',1;

EXEC dbo.SP_CLEAR_SCADA_CONFIGURAR_FUENTE
     @SourceSchema=@SourceSchema,
     @SourceTable=@SourceTable,
     @UpdatedBy=N'CORRECCION_FECHA_PI_20260908';
GO

BEGIN TRY
    EXEC dbo.SP_CLEAR_SCADA_ACTUALIZAR_ALARMAS_CACHE;
END TRY
BEGIN CATCH
    PRINT N'AVISO: la caché de campanas se actualizará en el próximo ciclo. '+ERROR_MESSAGE();
END CATCH;
GO

SELECT
    S.SOURCE_SCHEMA,
    S.SOURCE_TABLE,
    S.REFRESH_SECONDS,
    S.STALE_SECONDS,
    COUNT_BIG(V.TAG) AS VARIABLES,
    MAX(V.FECHA_OPC) AS ULTIMA_FECHA_OPC,
    N'CORRECCION APLICADA' AS RESULTADO
FROM dbo.CLEAR_SCADA_SETTINGS S
LEFT JOIN dbo.CLEAR_SCADA_REALTIME_V V ON 1=1
WHERE S.ID=1
GROUP BY S.SOURCE_SCHEMA,S.SOURCE_TABLE,S.REFRESH_SECONDS,S.STALE_SECONDS;
GO

