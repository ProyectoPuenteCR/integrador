USE [LC_MDB];
GO

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* =============================================================
   CLEAR — SCADA Real time
   - Normaliza la tabla actual del colector OPC sin modificarla.
   - Relaciona Batería / Zona / Área / PI / TAG de alarma.
   - Mantiene una caché pequeña de alarmas para no consultar
     dbo.FIXALARMS desde la web cada 10 segundos.
   - dbo.FIXALARMS permanece exclusivamente como fuente de lectura.
============================================================= */

IF OBJECT_ID(N'dbo.CLEAR_SCADA_SETTINGS',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_SCADA_SETTINGS
    (
        ID                    tinyint       NOT NULL CONSTRAINT PK_CLEAR_SCADA_SETTINGS PRIMARY KEY,
        SOURCE_SCHEMA         sysname       NOT NULL CONSTRAINT DF_CLEAR_SCADA_SETTINGS_SCHEMA DEFAULT N'dbo',
        SOURCE_TABLE          sysname       NULL,
        REFRESH_SECONDS       int           NOT NULL CONSTRAINT DF_CLEAR_SCADA_SETTINGS_REFRESH DEFAULT 10,
        STALE_SECONDS         int           NOT NULL CONSTRAINT DF_CLEAR_SCADA_SETTINGS_STALE DEFAULT 60,
        ALARM_WINDOW_HOURS    int           NOT NULL CONSTRAINT DF_CLEAR_SCADA_SETTINGS_WINDOW DEFAULT 24,
        PI_VISION_BASE_URL    nvarchar(1000) NULL,
        UPDATED_AT            datetime2(0)  NOT NULL CONSTRAINT DF_CLEAR_SCADA_SETTINGS_UPDATED DEFAULT SYSDATETIME(),
        UPDATED_BY            nvarchar(128) NULL,
        CONSTRAINT CK_CLEAR_SCADA_SETTINGS_ID CHECK (ID=1),
        CONSTRAINT CK_CLEAR_SCADA_SETTINGS_REFRESH CHECK (REFRESH_SECONDS BETWEEN 5 AND 300),
        CONSTRAINT CK_CLEAR_SCADA_SETTINGS_STALE CHECK (STALE_SECONDS BETWEEN 10 AND 86400),
        CONSTRAINT CK_CLEAR_SCADA_SETTINGS_WINDOW CHECK (ALARM_WINDOW_HOURS BETWEEN 1 AND 720)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.CLEAR_SCADA_SETTINGS WHERE ID=1)
    INSERT dbo.CLEAR_SCADA_SETTINGS(ID,SOURCE_SCHEMA,SOURCE_TABLE,REFRESH_SECONDS,STALE_SECONDS,ALARM_WINDOW_HOURS,UPDATED_BY)
    VALUES(1,N'dbo',NULL,10,60,24,N'INSTALADOR');
GO

IF OBJECT_ID(N'dbo.CLEAR_SCADA_BATERIA',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_SCADA_BATERIA
    (
        BATERIA       nvarchar(128) NOT NULL CONSTRAINT PK_CLEAR_SCADA_BATERIA PRIMARY KEY,
        NOMBRE        nvarchar(255) NULL,
        ZONA          nvarchar(128) NULL,
        ACTIVA        bit           NOT NULL CONSTRAINT DF_CLEAR_SCADA_BATERIA_ACTIVA DEFAULT 1,
        ORDEN         int           NOT NULL CONSTRAINT DF_CLEAR_SCADA_BATERIA_ORDEN DEFAULT 0,
        UPDATED_AT    datetime2(0)  NOT NULL CONSTRAINT DF_CLEAR_SCADA_BATERIA_UPDATED DEFAULT SYSDATETIME(),
        UPDATED_BY    nvarchar(128) NULL
    );
    CREATE INDEX IX_CLEAR_SCADA_BATERIA_ZONA ON dbo.CLEAR_SCADA_BATERIA(ZONA,ACTIVA,ORDEN) INCLUDE(NOMBRE);
END;
GO

IF OBJECT_ID(N'dbo.CLEAR_SCADA_TAG_MAP',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_SCADA_TAG_MAP
    (
        TAG             nvarchar(255)  NOT NULL CONSTRAINT PK_CLEAR_SCADA_TAG_MAP PRIMARY KEY,
        BATERIA         nvarchar(128)  NULL,
        ZONA            nvarchar(128)  NULL,
        AREA_EQUIPO     nvarchar(255)  NULL,
        UNIDAD          nvarchar(64)   NULL,
        PI_POINT        nvarchar(255)  NULL,
        PI_WEBID        nvarchar(512)  NULL,
        PI_VISION_URL   nvarchar(1000) NULL,
        ALARM_TAG       nvarchar(255)  NULL,
        ACTIVO          bit            NOT NULL CONSTRAINT DF_CLEAR_SCADA_TAG_MAP_ACTIVO DEFAULT 1,
        UPDATED_AT      datetime2(0)   NOT NULL CONSTRAINT DF_CLEAR_SCADA_TAG_MAP_UPDATED DEFAULT SYSDATETIME(),
        UPDATED_BY      nvarchar(128)  NULL
    );
    CREATE INDEX IX_CLEAR_SCADA_TAG_MAP_BATERIA ON dbo.CLEAR_SCADA_TAG_MAP(BATERIA,ACTIVO) INCLUDE(ZONA,AREA_EQUIPO,UNIDAD,PI_WEBID,ALARM_TAG);
    CREATE INDEX IX_CLEAR_SCADA_TAG_MAP_ALARM_TAG ON dbo.CLEAR_SCADA_TAG_MAP(ALARM_TAG) WHERE ALARM_TAG IS NOT NULL;
END;
GO

IF OBJECT_ID(N'dbo.CLEAR_SCADA_ALARM_CACHE',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_SCADA_ALARM_CACHE
    (
        TAG              nvarchar(255) NOT NULL CONSTRAINT PK_CLEAR_SCADA_ALARM_CACHE PRIMARY KEY,
        ALARM_TAG        nvarchar(255) NOT NULL,
        CANTIDAD         int           NOT NULL,
        PRIMERA_ALARMA   datetime2(3)  NULL,
        ULTIMA_ALARMA    datetime2(3)  NULL,
        FECHA_CACHE      datetime2(0)  NOT NULL
    );
    CREATE INDEX IX_CLEAR_SCADA_ALARM_CACHE_COUNT ON dbo.CLEAR_SCADA_ALARM_CACHE(CANTIDAD DESC) INCLUDE(ULTIMA_ALARMA,FECHA_CACHE);
END;
GO

IF OBJECT_ID(N'dbo.CLEAR_SCADA_RUNTIME_STATUS',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_SCADA_RUNTIME_STATUS
    (
        COMPONENTE      nvarchar(64)  NOT NULL CONSTRAINT PK_CLEAR_SCADA_RUNTIME_STATUS PRIMARY KEY,
        ESTADO          nvarchar(20)  NOT NULL,
        INICIO          datetime2(0)  NULL,
        FIN             datetime2(0)  NULL,
        FILAS           bigint        NULL,
        DETALLE         nvarchar(2000) NULL
    );
END;
GO

IF OBJECT_ID(N'dbo.CLEAR_SCADA_REALTIME_V',N'V') IS NULL
    EXEC(N'CREATE VIEW dbo.CLEAR_SCADA_REALTIME_V AS
           SELECT CAST(NULL AS nvarchar(128)) AS NODO,
                  CAST(NULL AS nvarchar(255)) AS TAG,
                  CAST(NULL AS nvarchar(512)) AS DESCRIPCION,
                  CAST(NULL AS nvarchar(512)) AS ITEM_VALOR,
                  CAST(NULL AS float) AS VALOR_NUMERICO,
                  CAST(NULL AS nvarchar(512)) AS VALOR_TEXTO,
                  CAST(NULL AS nvarchar(512)) AS VALOR_MOSTRAR,
                  CAST(NULL AS nvarchar(64)) AS CALIDAD,
                  CAST(NULL AS datetime2(3)) AS FECHA_OPC,
                  CAST(NULL AS datetime2(3)) AS FECHA_ACTUALIZACION,
                  CAST(NULL AS nvarchar(128)) AS BATERIA,
                  CAST(NULL AS nvarchar(128)) AS ZONA,
                  CAST(NULL AS nvarchar(255)) AS AREA_EQUIPO,
                  CAST(NULL AS nvarchar(64)) AS UNIDAD,
                  CAST(NULL AS nvarchar(255)) AS PI_POINT,
                  CAST(NULL AS nvarchar(512)) AS PI_WEBID,
                  CAST(NULL AS nvarchar(1000)) AS PI_VISION_URL,
                  CAST(NULL AS nvarchar(255)) AS ALARM_TAG
           WHERE 1=0');
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
CROSS APPLY(SELECT CONVERT(nvarchar(255),S.[Tag]) COLLATE DATABASE_DEFAULT AS TAGTXT)X
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

CREATE OR ALTER PROCEDURE dbo.SP_CLEAR_SCADA_ACTUALIZAR_ALARMAS_CACHE
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @LockResult int,@WindowHours int,@AlarmObject nvarchar(300),@Sql nvarchar(max),@Rows bigint=0;
    DECLARE @TagType sysname,@DateType sysname,@TagExpression nvarchar(500),@DateExpression nvarchar(500);
    SELECT @WindowHours=ALARM_WINDOW_HOURS FROM dbo.CLEAR_SCADA_SETTINGS WHERE ID=1;
    SET @WindowHours=ISNULL(@WindowHours,24);
    SET @AlarmObject=CASE WHEN OBJECT_ID(N'dbo.CLEAR_F_FIXALARMS_24H',N'V') IS NOT NULL
                          THEN N'dbo.CLEAR_F_FIXALARMS_24H' ELSE N'dbo.FIXALARMS' END;

    IF OBJECT_ID(@AlarmObject) IS NULL
        THROW 51210,N'No se encontró la fuente de alarmas de CLEAR.',1;

    SELECT @TagType=DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA=N'dbo' AND TABLE_NAME=PARSENAME(@AlarmObject,1) AND COLUMN_NAME=N'ALM_TAGNAME';
    SELECT @DateType=DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA=N'dbo' AND TABLE_NAME=PARSENAME(@AlarmObject,1) AND COLUMN_NAME=N'ALM_NATIVETIMEIN';
    SET @TagExpression=CASE WHEN @TagType IN(N'text',N'ntext',N'image',N'xml')
                            THEN N'CONVERT(nvarchar(255),A.ALM_TAGNAME)' ELSE N'A.ALM_TAGNAME' END;
    SET @DateExpression=CASE WHEN @DateType IN(N'date',N'datetime',N'datetime2',N'smalldatetime',N'datetimeoffset')
                             THEN N'A.ALM_NATIVETIMEIN' ELSE N'TRY_CONVERT(datetime2(3),A.ALM_NATIVETIMEIN)' END;

    UPDATE dbo.CLEAR_SCADA_RUNTIME_STATUS
       SET ESTADO=N'EJECUTANDO',INICIO=SYSDATETIME(),FIN=NULL,FILAS=NULL,DETALLE=NULL
     WHERE COMPONENTE=N'ALARMAS';
    IF @@ROWCOUNT=0
        INSERT dbo.CLEAR_SCADA_RUNTIME_STATUS(COMPONENTE,ESTADO,INICIO) VALUES(N'ALARMAS',N'EJECUTANDO',SYSDATETIME());

    BEGIN TRY
        BEGIN TRAN;
        EXEC @LockResult=sys.sp_getapplock
            @Resource=N'CLEAR_SCADA_ALARM_CACHE',@LockMode=N'Exclusive',@LockOwner=N'Transaction',@LockTimeout=0;
        IF @LockResult<0
        BEGIN
            ROLLBACK;
            UPDATE dbo.CLEAR_SCADA_RUNTIME_STATUS
               SET ESTADO=N'OMITIDO',FIN=SYSDATETIME(),DETALLE=N'Ya existe otra actualización en curso.'
             WHERE COMPONENTE=N'ALARMAS';
            RETURN;
        END;

        CREATE TABLE #Alarmas
        (
            TAG nvarchar(255) NOT NULL PRIMARY KEY,
            ALARM_TAG nvarchar(255) NOT NULL,
            CANTIDAD int NOT NULL,
            PRIMERA_ALARMA datetime2(3) NULL,
            ULTIMA_ALARMA datetime2(3) NULL
        );

        SET @Sql=N'
;WITH RealtimeTags AS
(
    SELECT DISTINCT TAG,ALARM_TAG FROM dbo.CLEAR_SCADA_REALTIME_V
    WHERE NULLIF(LTRIM(RTRIM(ALARM_TAG)),N'''') IS NOT NULL
)
INSERT #Alarmas(TAG,ALARM_TAG,CANTIDAD,PRIMERA_ALARMA,ULTIMA_ALARMA)
SELECT R.TAG,R.ALARM_TAG,CONVERT(int,COUNT_BIG(*)),MIN(CONVERT(datetime2(3),'+@DateExpression+N')),MAX(CONVERT(datetime2(3),'+@DateExpression+N'))
FROM '+@AlarmObject+N' A
JOIN RealtimeTags R ON R.ALARM_TAG='+@TagExpression+N' COLLATE DATABASE_DEFAULT
WHERE '+@DateExpression+N'>=DATEADD(hour,-@Hours,SYSDATETIME())
GROUP BY R.TAG,R.ALARM_TAG OPTION(MAXDOP 1);';
        EXEC sys.sp_executesql @Sql,N'@Hours int',@Hours=@WindowHours;

        DELETE FROM dbo.CLEAR_SCADA_ALARM_CACHE;
        INSERT dbo.CLEAR_SCADA_ALARM_CACHE(TAG,ALARM_TAG,CANTIDAD,PRIMERA_ALARMA,ULTIMA_ALARMA,FECHA_CACHE)
        SELECT TAG,ALARM_TAG,CANTIDAD,PRIMERA_ALARMA,ULTIMA_ALARMA,SYSDATETIME() FROM #Alarmas;
        SET @Rows=@@ROWCOUNT;
        COMMIT;

        UPDATE dbo.CLEAR_SCADA_RUNTIME_STATUS
           SET ESTADO=N'COMPLETADO',FIN=SYSDATETIME(),FILAS=@Rows,
               DETALLE=N'Caché actualizada sin modificar dbo.FIXALARMS.'
         WHERE COMPONENTE=N'ALARMAS';
    END TRY
    BEGIN CATCH
        IF XACT_STATE()<>0 ROLLBACK;
        UPDATE dbo.CLEAR_SCADA_RUNTIME_STATUS
           SET ESTADO=N'ERROR',FIN=SYSDATETIME(),DETALLE=LEFT(ERROR_MESSAGE(),2000)
         WHERE COMPONENTE=N'ALARMAS';
        THROW;
    END CATCH;
END;
GO

/* Detectar automáticamente la tabla actual del colector. */
DECLARE @DetectedSchema sysname,@DetectedTable sysname;
;WITH Candidates AS
(
    SELECT S.name AS SchemaName,T.name AS TableName,T.object_id,
           SUM(CASE WHEN C.name IN(N'Nodo',N'Tag',N'Descripcion',N'ItemValor',N'ValorNumerico',N'ValorTexto',N'Calidad',N'FechaOPC',N'FechaActualizacion') THEN 1 ELSE 0 END) AS MatchingColumns
    FROM sys.tables T
    JOIN sys.schemas S ON S.schema_id=T.schema_id
    JOIN sys.columns C ON C.object_id=T.object_id
    WHERE T.name NOT LIKE N'CLEAR[_]SCADA[_]%'
    GROUP BY S.name,T.name,T.object_id
)
SELECT TOP(1) @DetectedSchema=SchemaName,@DetectedTable=TableName
FROM Candidates
WHERE MatchingColumns=9
ORDER BY CASE WHEN SchemaName=N'dbo' THEN 0 ELSE 1 END,TableName;

IF @DetectedTable IS NOT NULL
BEGIN
    EXEC dbo.SP_CLEAR_SCADA_CONFIGURAR_FUENTE @DetectedSchema,@DetectedTable,N'INSTALADOR';
    PRINT N'Fuente SCADA detectada: '+QUOTENAME(@DetectedSchema)+N'.'+QUOTENAME(@DetectedTable);
END
ELSE
    PRINT N'AVISO: no se detectó automáticamente la fuente. Selecciónela desde Configuración de SCADA Real time.';
GO

/* Job liviano: actualiza solamente el contador agregado por TAG. */
BEGIN TRY
    DECLARE @JobName sysname=N'CLEAR - Alarmas SCADA Real time';
    DECLARE @JobId uniqueidentifier;
    SELECT @JobId=job_id FROM msdb.dbo.sysjobs WHERE name=@JobName;
    IF @JobId IS NULL
    BEGIN
        EXEC msdb.dbo.sp_add_job @job_name=@JobName,@enabled=1,
             @description=N'Actualiza cada 5 minutos la caché de alarmas por TAG para SCADA Real time.',@job_id=@JobId OUTPUT;
        EXEC msdb.dbo.sp_add_jobstep @job_id=@JobId,@step_name=N'Actualizar caché',
             @subsystem=N'TSQL',@database_name=N'LC_MDB',@command=N'EXEC dbo.SP_CLEAR_SCADA_ACTUALIZAR_ALARMAS_CACHE;',@retry_attempts=2,@retry_interval=1;
        EXEC msdb.dbo.sp_add_jobschedule @job_id=@JobId,@name=N'Cada 5 minutos',
             @freq_type=4,@freq_interval=1,@freq_subday_type=4,@freq_subday_interval=5,@active_start_time=000000;
        EXEC msdb.dbo.sp_add_jobserver @job_id=@JobId;
    END
    ELSE
        EXEC msdb.dbo.sp_update_job @job_id=@JobId,@enabled=1;
END TRY
BEGIN CATCH
    PRINT N'AVISO: no se pudo crear o habilitar el Job. '+ERROR_MESSAGE();
END CATCH;
GO

BEGIN TRY
    IF EXISTS(SELECT 1 FROM dbo.CLEAR_SCADA_SETTINGS WHERE ID=1 AND NULLIF(SOURCE_TABLE,N'') IS NOT NULL)
        EXEC dbo.SP_CLEAR_SCADA_ACTUALIZAR_ALARMAS_CACHE;
END TRY
BEGIN CATCH
    PRINT N'AVISO: la primera actualización de alarmas no pudo completarse. El Job reintentará luego. '+ERROR_MESSAGE();
END CATCH;
GO

SELECT SOURCE_SCHEMA,SOURCE_TABLE,REFRESH_SECONDS,STALE_SECONDS,ALARM_WINDOW_HOURS,UPDATED_AT
FROM dbo.CLEAR_SCADA_SETTINGS WHERE ID=1;

SELECT COUNT_BIG(*) AS VARIABLES_SCADA,COUNT(DISTINCT BATERIA) AS BATERIAS,MAX(FECHA_ACTUALIZACION) AS ULTIMA_ACTUALIZACION
FROM dbo.CLEAR_SCADA_REALTIME_V;

SELECT COMPONENTE,ESTADO,INICIO,FIN,FILAS,DETALLE FROM dbo.CLEAR_SCADA_RUNTIME_STATUS;
GO
