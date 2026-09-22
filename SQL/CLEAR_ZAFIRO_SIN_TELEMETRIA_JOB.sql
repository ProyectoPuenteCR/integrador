USE [LC_MDB];
GO

/*
    CLEAR - Pozos sin telemetría en Zafiro (captura diaria + comparativa semanal)

    Criterio (idéntico a la Grilla general de pozos):
        Un pozo de dbo.TELEMETRIA_POZOS_GENERAL_CACHE cuyo "Zafiro Activo"
        queda en "Sin dato", es decir, que no tiene un valor isActive en la
        caché Zafiro dbo.CLEAR_API_Q158_POZOS.

    Qué instala:
        dbo.CLEAR_ZAFIRO_SIN_TELEM_CORRIDA   -> una fila por día (totales)
        dbo.CLEAR_ZAFIRO_SIN_TELEM_POZOS     -> listado de pozos "Sin dato" por día
        dbo.FN_CLEAR_ZAFIRO_POZO_CLAVE       -> misma clave compacta que usa la web
        dbo.SP_CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA
        Job "CLEAR - Pozos sin telemetria Zafiro" (cada hora, 06:00 a 23:59)

    Funcionamiento del job:
        - Se guarda UNA captura por día.
        - La captura se toma cuando la caché Zafiro tiene FechaCarga del día
          (es decir, luego de la sincronización diaria de Zafiro).
        - Si Zafiro vuelve a sincronizar el mismo día, la captura se reemplaza.
        - Si a las 22:00 Zafiro todavía no sincronizó, se captura igual con la
          última carga disponible y queda marcada ZAFIRO_DEL_DIA = 0.

    Es re-ejecutable: no borra historia existente.
    Tablas temporales con COLLATE DATABASE_DEFAULT: LC_MDB (Modern_Spanish_CI_AS)
    y tempdb (SQL_Latin1_General_CP1_CI_AS) usan intercalaciones distintas.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* ------------------------------------------------------------------ */
/* Tablas                                                              */
/* ------------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.CLEAR_ZAFIRO_SIN_TELEM_CORRIDA',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_ZAFIRO_SIN_TELEM_CORRIDA
    (
        FECHA                 date          NOT NULL,
        FECHA_ZAFIRO          datetime2(0)  NULL,
        FECHA_GRILLA          datetime2(0)  NULL,
        TOTAL_POZOS_GRILLA    int           NOT NULL,
        TOTAL_ZAFIRO_ACTIVOS  int           NOT NULL,
        TOTAL_SIN_TELEMETRIA  int           NOT NULL,
        ZAFIRO_DEL_DIA        bit           NOT NULL,
        FECHA_ACTUALIZACION   datetime2(0)  NOT NULL,
        CONSTRAINT PK_CLEAR_ZAFIRO_SIN_TELEM_CORRIDA PRIMARY KEY CLUSTERED (FECHA)
    );
END;
GO

IF OBJECT_ID(N'dbo.CLEAR_ZAFIRO_SIN_TELEM_POZOS',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_ZAFIRO_SIN_TELEM_POZOS
    (
        FECHA              date            NOT NULL,
        POZO_CLAVE         nvarchar(100)   NOT NULL,
        POZO               nvarchar(255)   NOT NULL,
        BATERIA            nvarchar(255)   NULL,
        ZONA               nvarchar(255)   NULL,
        TELEMETRIA         nvarchar(30)    NULL,
        COMUNICACION       nvarchar(255)   NULL,
        ESTADO             nvarchar(255)   NULL,
        OBSERVACIONES      nvarchar(1000)  NULL,
        PRIMERA_DETECCION  date            NOT NULL,
        CONSTRAINT PK_CLEAR_ZAFIRO_SIN_TELEM_POZOS PRIMARY KEY CLUSTERED (FECHA, POZO_CLAVE)
    );
END;
GO

/* ------------------------------------------------------------------ */
/* Clave de pozo: mayúsculas, sin acentos ni separadores, sin YPF.SC   */
/* YPF.SC.BB-132 / BB-132 / BB 132 / BB.132  ->  BB132                 */
/* ------------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.FN_CLEAR_ZAFIRO_POZO_CLAVE',N'FN') IS NULL
    EXEC(N'CREATE FUNCTION dbo.FN_CLEAR_ZAFIRO_POZO_CLAVE(@Pozo nvarchar(255)) RETURNS nvarchar(100) AS BEGIN RETURN N''''; END;');
GO

ALTER FUNCTION dbo.FN_CLEAR_ZAFIRO_POZO_CLAVE(@Pozo nvarchar(255))
RETURNS nvarchar(100)
WITH SCHEMABINDING
AS
BEGIN
    DECLARE @s nvarchar(255)=UPPER(LTRIM(RTRIM(ISNULL(@Pozo,N''))));
    DECLARE @i int;

    SET @s=REPLACE(@s,NCHAR(193),N'A');  /* Á */
    SET @s=REPLACE(@s,NCHAR(201),N'E');  /* É */
    SET @s=REPLACE(@s,NCHAR(205),N'I');  /* Í */
    SET @s=REPLACE(@s,NCHAR(211),N'O');  /* Ó */
    SET @s=REPLACE(@s,NCHAR(218),N'U');  /* Ú */
    SET @s=REPLACE(@s,NCHAR(220),N'U');  /* Ü */
    SET @s=REPLACE(@s,NCHAR(209),N'N');  /* Ñ */

    SET @i=PATINDEX(N'%[^A-Z0-9]%',@s COLLATE Latin1_General_BIN);
    WHILE @i>0
    BEGIN
        SET @s=STUFF(@s,@i,1,N'');
        SET @i=PATINDEX(N'%[^A-Z0-9]%',@s COLLATE Latin1_General_BIN);
    END;

    IF LEFT(@s,5)=N'YPFSC' AND LEN(@s)>5
        SET @s=SUBSTRING(@s,6,250);

    RETURN LEFT(@s,100);
END;
GO

/* ------------------------------------------------------------------ */
/* Captura diaria                                                      */
/* ------------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.SP_CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA',N'P') IS NULL
    EXEC(N'CREATE PROCEDURE dbo.SP_CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA AS RETURN 0;');
GO

ALTER PROCEDURE dbo.SP_CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA
    @Forzar      bit     = 0,   /* 1 = captura ahora aunque Zafiro no haya sincronizado hoy */
    @HoraLimite  tinyint = 22   /* a partir de esta hora se captura con la última carga Zafiro */
/* Sin EXECUTE AS OWNER: en LC_MDB el usuario dbo no puede suplantarse (Msg 15517). */
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Ahora datetime2(0)=SYSDATETIME();
    DECLARE @Hoy date=CAST(SYSDATETIME() AS date);
    DECLARE @Bloqueo int;
    DECLARE @Resultado nvarchar(200)=N'';

    /*
       Si una ejecución anterior en ESTA misma sesión terminó con un error de
       compilación (no capturable por TRY/CATCH), el bloqueo de sesión quedó
       tomado. Se libera acá; nunca afecta bloqueos de otras sesiones.
    */
    WHILE APPLOCK_MODE(N'public',N'CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA',N'Session')<>N'NoLock'
        EXEC sys.sp_releaseapplock
            @Resource=N'CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA',
            @LockOwner=N'Session';

    EXEC @Bloqueo=sys.sp_getapplock
        @Resource=N'CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA',
        @LockMode=N'Exclusive',
        @LockOwner=N'Session',
        @LockTimeout=0;

    IF @Bloqueo<0
        THROW 50061, 'La captura de pozos sin telemetría en Zafiro ya está en ejecución.', 1;

    BEGIN TRY
        IF OBJECT_ID(N'dbo.CLEAR_API_Q158_POZOS') IS NULL
            THROW 50062, 'No existe dbo.CLEAR_API_Q158_POZOS (caché Zafiro).', 1;
        IF OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE',N'U') IS NULL
            THROW 50063, 'No existe dbo.TELEMETRIA_POZOS_GENERAL_CACHE (grilla general).', 1;

        /* Columnas Zafiro: se detectan por nombre para no depender de acentos. */
        DECLARE @ObjZafiro int=OBJECT_ID(N'dbo.CLEAR_API_Q158_POZOS');
        DECLARE @ColPozo sysname, @ColActivo sysname, @ColFecha sysname, @ColCacheId sysname;

        SELECT TOP (1) @ColPozo=name FROM sys.columns WHERE object_id=@ObjZafiro AND name=N'Pozo';
        SELECT TOP (1) @ColActivo=name FROM sys.columns WHERE object_id=@ObjZafiro AND name LIKE N'%Cambio de estado>>Estado[_]isActive' ORDER BY column_id;
        IF @ColActivo IS NULL
            SELECT TOP (1) @ColActivo=name FROM sys.columns WHERE object_id=@ObjZafiro AND name LIKE N'%isActive' ORDER BY column_id;
        SELECT TOP (1) @ColFecha=name FROM sys.columns WHERE object_id=@ObjZafiro AND name=N'FechaCarga';
        SELECT TOP (1) @ColCacheId=name FROM sys.columns WHERE object_id=@ObjZafiro AND name=N'CacheId';

        IF @ColPozo IS NULL OR @ColActivo IS NULL
            THROW 50064, 'dbo.CLEAR_API_Q158_POZOS no expone las columnas Pozo / Estado_isActive.', 1;

        DECLARE @Sql nvarchar(max);
        DECLARE @FechaZafiro datetime2(0)=NULL;

        IF @ColFecha IS NOT NULL
        BEGIN
            SET @Sql=N'SELECT @F=MAX(CONVERT(datetime2(0),'+QUOTENAME(@ColFecha)+N')) FROM dbo.CLEAR_API_Q158_POZOS;';
            EXEC sys.sp_executesql @Sql,N'@F datetime2(0) OUTPUT',@F=@FechaZafiro OUTPUT;
        END;

        DECLARE @ZafiroDelDia bit=CASE WHEN @FechaZafiro IS NULL OR CAST(@FechaZafiro AS date)>=@Hoy THEN 1 ELSE 0 END;

        IF @Forzar=0 AND EXISTS
        (
            SELECT 1 FROM dbo.CLEAR_ZAFIRO_SIN_TELEM_CORRIDA
            WHERE FECHA=@Hoy
              AND ISNULL(FECHA_ZAFIRO,'19000101')>=ISNULL(@FechaZafiro,'19000101')
        )
            SET @Resultado=N'Sin cambios: la captura del día ya usa la última sincronización Zafiro.';
        ELSE IF @Forzar=0 AND @ZafiroDelDia=0 AND DATEPART(hour,@Ahora)<@HoraLimite
            SET @Resultado=N'Esperando la sincronización diaria de Zafiro.';

        IF @Resultado=N''
        BEGIN
            /* 1) Zafiro: último valor isActive válido por pozo. */
            CREATE TABLE #ZafiroRaw
            (
                POZO          nvarchar(255) COLLATE DATABASE_DEFAULT NOT NULL,
                ACTIVO_LABEL  nvarchar(10) COLLATE DATABASE_DEFAULT  NULL,
                FECHA_CARGA   datetime2(0)  NULL,
                ORDEN         bigint        NOT NULL
            );

            SET @Sql=N'INSERT INTO #ZafiroRaw(POZO,ACTIVO_LABEL,FECHA_CARGA,ORDEN) '
                +N'SELECT LTRIM(RTRIM(CONVERT(nvarchar(255),'+QUOTENAME(@ColPozo)+N'))),'
                +N'CASE WHEN UPPER(LTRIM(RTRIM(CONVERT(nvarchar(10),'+QUOTENAME(@ColActivo)+N')))) IN (N''1'',N''TRUE'',N''SI'',N''S'+NCHAR(205)+N''',N''YES'',N''ACTIVO'',N''ACTIVE'') THEN N''true'' '
                +N'WHEN UPPER(LTRIM(RTRIM(CONVERT(nvarchar(10),'+QUOTENAME(@ColActivo)+N')))) IN (N''0'',N''FALSE'',N''NO'',N''INACTIVO'',N''INACTIVE'') THEN N''false'' ELSE NULL END,'
                +CASE WHEN @ColFecha IS NULL THEN N'NULL,' ELSE N'CONVERT(datetime2(0),'+QUOTENAME(@ColFecha)+N'),' END
                +N'ROW_NUMBER() OVER (ORDER BY '
                +CASE WHEN @ColFecha IS NULL THEN N'(SELECT NULL)' ELSE QUOTENAME(@ColFecha)+N' DESC' END
                +CASE WHEN @ColCacheId IS NULL THEN N'' ELSE N','+QUOTENAME(@ColCacheId)+N' DESC' END
                +N') '
                +N'FROM dbo.CLEAR_API_Q158_POZOS WHERE '+QUOTENAME(@ColPozo)+N' IS NOT NULL;';
            EXEC sys.sp_executesql @Sql;

            IF NOT EXISTS (SELECT 1 FROM #ZafiroRaw)
                THROW 50065, 'La caché Zafiro está vacía. Se conserva la última captura válida.', 1;

            SELECT P.POZO,dbo.FN_CLEAR_ZAFIRO_POZO_CLAVE(P.POZO) AS CLAVE
            INTO #ZafiroClaves
            FROM (SELECT DISTINCT POZO FROM #ZafiroRaw) P;

            /* Igual que la web: gana el valor isActive más reciente no vacío. */
            SELECT R.CLAVE,R.ACTIVO_LABEL
            INTO #Zafiro
            FROM
            (
                SELECT K.CLAVE,Z.ACTIVO_LABEL,
                       ROW_NUMBER() OVER (PARTITION BY K.CLAVE ORDER BY Z.ORDEN) AS RN
                FROM #ZafiroRaw Z
                INNER JOIN #ZafiroClaves K ON K.POZO COLLATE DATABASE_DEFAULT = Z.POZO COLLATE DATABASE_DEFAULT
                WHERE Z.ACTIVO_LABEL IS NOT NULL AND K.CLAVE<>N''
            ) R
            WHERE R.RN=1;

            CREATE UNIQUE CLUSTERED INDEX IX_TMP_ZAFIRO ON #Zafiro(CLAVE);

            DECLARE @TotalZafiroActivos int=(SELECT COUNT(*) FROM #Zafiro WHERE ACTIVO_LABEL=N'true');

            /* 2) Grilla general: un registro por pozo (SCADA y TECSS se unifican). */
            SELECT
                LTRIM(RTRIM(CONVERT(nvarchar(255),G.POZO)))               AS POZO,
                LTRIM(RTRIM(CONVERT(nvarchar(255),G.BATERIA)))            AS BATERIA,
                CASE WHEN UPPER(LTRIM(RTRIM(CONVERT(nvarchar(20),G.TIPO)))) IN (N'TECSS',N'TECCS')
                     THEN N'TECSS' ELSE N'SCADA' END                      AS CANAL,
                CONVERT(nvarchar(255),G.COMUNICACION)                      AS COMUNICACION,
                CONVERT(nvarchar(255),G.ESTADO)                            AS ESTADO,
                G.FECHA_CACHE,
                dbo.FN_CLEAR_ZAFIRO_POZO_CLAVE(G.POZO)                     AS CLAVE
            INTO #GrillaRaw
            FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE G
            WHERE G.POZO IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(255),G.POZO)))<>N'';

            IF NOT EXISTS (SELECT 1 FROM #GrillaRaw)
                THROW 50066, 'La grilla general de pozos está vacía. Se conserva la última captura válida.', 1;

            DECLARE @FechaGrilla datetime2(0)=(SELECT MAX(FECHA_CACHE) FROM #GrillaRaw);

            SELECT CLAVE,POZO,BATERIA,COMUNICACION,ESTADO,
                   CASE WHEN TIENE_SCADA=1 AND TIENE_TECSS=1 THEN N'SCADA + TECSS'
                        WHEN TIENE_TECSS=1 THEN N'TECSS' ELSE N'SCADA' END AS TELEMETRIA
            INTO #Grilla
            FROM
            (
                SELECT *,
                       ROW_NUMBER() OVER (PARTITION BY CLAVE ORDER BY CASE WHEN CANAL=N'SCADA' THEN 0 ELSE 1 END,POZO) AS RN,
                       MAX(CASE WHEN CANAL=N'SCADA' THEN 1 ELSE 0 END) OVER (PARTITION BY CLAVE) AS TIENE_SCADA,
                       MAX(CASE WHEN CANAL=N'TECSS' THEN 1 ELSE 0 END) OVER (PARTITION BY CLAVE) AS TIENE_TECSS
                FROM #GrillaRaw
                WHERE CLAVE<>N''
            ) X
            WHERE RN=1;

            DECLARE @TotalGrilla int=(SELECT COUNT(*) FROM #Grilla);

            /* 3) Pozos con Zafiro Activo = Sin dato. */
            CREATE TABLE #SinTelemetria
            (
                POZO_CLAVE     nvarchar(100) COLLATE DATABASE_DEFAULT  NOT NULL PRIMARY KEY,
                POZO           nvarchar(255) COLLATE DATABASE_DEFAULT  NOT NULL,
                BATERIA        nvarchar(255) COLLATE DATABASE_DEFAULT  NULL,
                ZONA           nvarchar(255) COLLATE DATABASE_DEFAULT  NULL,
                TELEMETRIA     nvarchar(30) COLLATE DATABASE_DEFAULT   NULL,
                COMUNICACION   nvarchar(255) COLLATE DATABASE_DEFAULT  NULL,
                ESTADO         nvarchar(255) COLLATE DATABASE_DEFAULT  NULL,
                OBSERVACIONES  nvarchar(1000) COLLATE DATABASE_DEFAULT NULL
            );

            INSERT INTO #SinTelemetria(POZO_CLAVE,POZO,BATERIA,TELEMETRIA,COMUNICACION,ESTADO)
            SELECT G.CLAVE,G.POZO,G.BATERIA,G.TELEMETRIA,G.COMUNICACION,G.ESTADO
            FROM #Grilla G
            LEFT JOIN #Zafiro Z ON Z.CLAVE COLLATE DATABASE_DEFAULT = G.CLAVE COLLATE DATABASE_DEFAULT
            WHERE Z.CLAVE IS NULL;

            /* Zona desde el catálogo CLEAR.ZONAS (misma normalización que el filtro Zona). */
            IF OBJECT_ID(N'CLEAR.ZONAS') IS NOT NULL
            BEGIN
                SET @Sql=N'UPDATE S SET ZONA=Z.ZONA FROM #SinTelemetria S CROSS APPLY ('
                    +N'SELECT MIN(LTRIM(RTRIM(CONVERT(nvarchar(255),C.ZONA)))) AS ZONA FROM [CLEAR].[ZONAS] C '
                    +N'WHERE REPLACE(REPLACE(REPLACE(UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100),C.BATERIA_POZOS)))),NCHAR(160),N''''),CHAR(9),N''''),N'' '',N'''') COLLATE DATABASE_DEFAULT '
                    +N'= REPLACE(REPLACE(REPLACE(UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100),S.BATERIA)))),NCHAR(160),N''''),CHAR(9),N''''),N'' '',N'''') COLLATE DATABASE_DEFAULT'
                    +N') Z WHERE Z.ZONA IS NOT NULL;';
                EXEC sys.sp_executesql @Sql;
            END;

            /* Observaciones: tipo de instalación desde paro remoto (igual que la grilla). */
            IF OBJECT_ID(N'dbo.CLEAR_PARO_REMOTO',N'U') IS NOT NULL
            BEGIN
                SET @Sql=N'UPDATE S SET OBSERVACIONES=P.TIPO FROM #SinTelemetria S CROSS APPLY ('
                    +N'SELECT MAX(CONVERT(nvarchar(1000),R.[AF-TIPO-DESC])) AS TIPO FROM dbo.CLEAR_PARO_REMOTO R '
                    +N'WHERE UPPER(LTRIM(RTRIM(R.[AF-POZO]))) COLLATE DATABASE_DEFAULT = UPPER(LTRIM(RTRIM(S.POZO))) COLLATE DATABASE_DEFAULT'
                    +N') P WHERE P.TIPO IS NOT NULL AND LTRIM(RTRIM(P.TIPO))<>N'''';';
                EXEC sys.sp_executesql @Sql;
            END;

            /* Primera detección: continúa la racha si el pozo estaba en la captura anterior. */
            DECLARE @Anterior date=(SELECT MAX(FECHA) FROM dbo.CLEAR_ZAFIRO_SIN_TELEM_CORRIDA WHERE FECHA<@Hoy);

            BEGIN TRANSACTION;
                DELETE FROM dbo.CLEAR_ZAFIRO_SIN_TELEM_POZOS WHERE FECHA=@Hoy;
                DELETE FROM dbo.CLEAR_ZAFIRO_SIN_TELEM_CORRIDA WHERE FECHA=@Hoy;

                INSERT INTO dbo.CLEAR_ZAFIRO_SIN_TELEM_POZOS
                (FECHA,POZO_CLAVE,POZO,BATERIA,ZONA,TELEMETRIA,COMUNICACION,ESTADO,OBSERVACIONES,PRIMERA_DETECCION)
                SELECT @Hoy,S.POZO_CLAVE,S.POZO,S.BATERIA,S.ZONA,S.TELEMETRIA,S.COMUNICACION,S.ESTADO,S.OBSERVACIONES,
                       ISNULL(A.PRIMERA_DETECCION,@Hoy)
                FROM #SinTelemetria S
                LEFT JOIN dbo.CLEAR_ZAFIRO_SIN_TELEM_POZOS A
                       ON A.FECHA=@Anterior AND A.POZO_CLAVE COLLATE DATABASE_DEFAULT = S.POZO_CLAVE COLLATE DATABASE_DEFAULT;

                INSERT INTO dbo.CLEAR_ZAFIRO_SIN_TELEM_CORRIDA
                (FECHA,FECHA_ZAFIRO,FECHA_GRILLA,TOTAL_POZOS_GRILLA,TOTAL_ZAFIRO_ACTIVOS,TOTAL_SIN_TELEMETRIA,ZAFIRO_DEL_DIA,FECHA_ACTUALIZACION)
                SELECT @Hoy,@FechaZafiro,@FechaGrilla,@TotalGrilla,@TotalZafiroActivos,COUNT(*),@ZafiroDelDia,@Ahora
                FROM #SinTelemetria;
            COMMIT TRANSACTION;

            SET @Resultado=N'Captura guardada.';
        END;

        IF APPLOCK_MODE(N'public',N'CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA',N'Session')<>N'NoLock'
            EXEC sys.sp_releaseapplock
                @Resource=N'CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA',
                @LockOwner=N'Session';

        SELECT @Resultado AS RESULTADO,
               C.FECHA,C.FECHA_ZAFIRO,C.TOTAL_POZOS_GRILLA,C.TOTAL_ZAFIRO_ACTIVOS,C.TOTAL_SIN_TELEMETRIA,C.ZAFIRO_DEL_DIA
        FROM (SELECT 1 AS X) D
        LEFT JOIN dbo.CLEAR_ZAFIRO_SIN_TELEM_CORRIDA C ON C.FECHA=@Hoy;
    END TRY
    BEGIN CATCH
        /* La liberación está protegida para que nunca oculte el error original. */
        IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;
        IF APPLOCK_MODE(N'public',N'CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA',N'Session')<>N'NoLock'
            EXEC sys.sp_releaseapplock
                @Resource=N'CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA',
                @LockOwner=N'Session';
        THROW;
    END CATCH;
END;
GO

/* La aplicación web de CLEAR usa el usuario de base fix. */
IF DATABASE_PRINCIPAL_ID(N'fix') IS NOT NULL
BEGIN
    GRANT SELECT ON dbo.CLEAR_ZAFIRO_SIN_TELEM_CORRIDA TO [fix];
    GRANT SELECT ON dbo.CLEAR_ZAFIRO_SIN_TELEM_POZOS TO [fix];
    /* Solo lo usa el botón "Capturar ahora" (visible para administradores). */
    GRANT EXECUTE ON dbo.SP_CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA TO [fix];
END;
GO

/* Primera captura inmediata para tener el punto de partida. */
EXEC dbo.SP_CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA @Forzar=1;
GO

/* ------------------------------------------------------------------ */
/* Job del SQL Server Agent: cada hora entre 06:00 y 23:59             */
/* ------------------------------------------------------------------ */
USE [msdb];
GO

IF EXISTS (SELECT 1 FROM dbo.sysjobs WHERE name=N'CLEAR - Pozos sin telemetria Zafiro')
BEGIN
    EXEC dbo.sp_delete_job
        @job_name=N'CLEAR - Pozos sin telemetria Zafiro',
        @delete_unused_schedule=1;
END;
GO

DECLARE @JobId uniqueidentifier;
DECLARE @FechaInicio int=CONVERT(int,CONVERT(char(8),GETDATE(),112));

EXEC dbo.sp_add_job
    @job_name=N'CLEAR - Pozos sin telemetria Zafiro',
    @enabled=1,
    @description=N'Guarda una vez por día el listado de pozos con Zafiro Activo = Sin dato, luego de la sincronización de Zafiro.',
    @category_name=N'[Uncategorized (Local)]',
    @owner_login_name=N'sa',
    @job_id=@JobId OUTPUT;

EXEC dbo.sp_add_jobstep
    @job_id=@JobId,
    @step_name=N'Capturar pozos sin telemetria',
    @subsystem=N'TSQL',
    @database_name=N'LC_MDB',
    @command=N'EXEC dbo.SP_CLEAR_ZAFIRO_SIN_TELEMETRIA_CAPTURA;',
    @retry_attempts=1,
    @retry_interval=5,
    @on_success_action=1,
    @on_fail_action=2;

EXEC dbo.sp_add_schedule
    @schedule_name=N'CLEAR - Sin telemetria Zafiro cada hora',
    @enabled=1,
    @freq_type=4,
    @freq_interval=1,
    @freq_subday_type=8,
    @freq_subday_interval=1,
    @active_start_date=@FechaInicio,
    @active_start_time=060500,
    @active_end_time=235959;

EXEC dbo.sp_attach_schedule
    @job_id=@JobId,
    @schedule_name=N'CLEAR - Sin telemetria Zafiro cada hora';

EXEC dbo.sp_add_jobserver
    @job_id=@JobId,
    @server_name=N'(LOCAL)';
GO

USE [LC_MDB];
GO

SELECT TOP (10) *
FROM dbo.CLEAR_ZAFIRO_SIN_TELEM_CORRIDA
ORDER BY FECHA DESC;
GO
