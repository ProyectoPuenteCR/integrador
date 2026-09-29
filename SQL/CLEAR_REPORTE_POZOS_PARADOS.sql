USE [LC_MDB];
GO
/*
 CLEAR - Reporte de Pozos Parados
 Arquitectura:
   - La web SOLO lee dbo.CLEAR_POZOS_PARADOS_CACHE.
   - Un job de SQL Server Agent refresca la cache cada 10 minutos.
   - El historico conserva snapshots de los pozos parados para estimar perdida 24 h.
   - Retencion del historico: 35 dias.
   - No se consulta RTQP/Zafiro desde cada navegador.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.FN_CLEAR_POZO_CLAVE',N'FN') IS NULL
    EXEC(N'CREATE FUNCTION dbo.FN_CLEAR_POZO_CLAVE(@Pozo nvarchar(255)) RETURNS nvarchar(100) AS BEGIN RETURN N''''; END;');
GO
ALTER FUNCTION dbo.FN_CLEAR_POZO_CLAVE(@Pozo nvarchar(255))
RETURNS nvarchar(100)
WITH SCHEMABINDING
AS
BEGIN
    DECLARE @s nvarchar(255)=UPPER(LTRIM(RTRIM(ISNULL(@Pozo,N''))));
    SET @s=REPLACE(@s,N'Á',N'A'); SET @s=REPLACE(@s,N'É',N'E'); SET @s=REPLACE(@s,N'Í',N'I');
    SET @s=REPLACE(@s,N'Ó',N'O'); SET @s=REPLACE(@s,N'Ú',N'U'); SET @s=REPLACE(@s,N'Ü',N'U');
    SET @s=REPLACE(@s,N'Ñ',N'N');
    SET @s=REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@s,N'.',N''),N'-',N''),N'_',N''),N' ',N''),N'/',N'');
    IF LEFT(@s,5)=N'YPFSC' SET @s=SUBSTRING(@s,6,250);
    RETURN LEFT(@s,100);
END;
GO

IF OBJECT_ID(N'dbo.CLEAR_POZOS_PARADOS_CACHE',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_POZOS_PARADOS_CACHE(
        SISTEMA              nvarchar(20)  NOT NULL,
        POZO_CLAVE           nvarchar(100) NOT NULL,
        POZO                 nvarchar(255) NOT NULL,
        BATERIA              nvarchar(255) NULL,
        ESTADO_TELEMETRIA    nvarchar(255) NULL,
        ESTADO_POZO          nvarchar(40)  NOT NULL,
        ESTADO_ZAFIRO        nvarchar(500) NULL,
        METODO_ZAFIRO        nvarchar(500) NULL,
        RPM                  real          NULL,
        VARIADOR             nvarchar(100) NULL,
        LLAVE                nvarchar(100) NULL,
        LLAVE_AUTO           nvarchar(100) NULL,
        PRODUCCION_PETROLEO  decimal(18,3) NULL,
        PERDIDA_INSTANTANEA  decimal(18,3) NULL,
        PERDIDA_24H          decimal(18,3) NULL,
        FECHA_DATO           datetime2(0)  NULL,
        FECHA_CACHE          datetime2(0)  NOT NULL,
        CONSTRAINT PK_CLEAR_POZOS_PARADOS_CACHE PRIMARY KEY CLUSTERED(SISTEMA,POZO_CLAVE)
    );
    CREATE INDEX IX_CLEAR_POZOS_PARADOS_CACHE_POZO ON dbo.CLEAR_POZOS_PARADOS_CACHE(POZO_CLAVE);
    CREATE INDEX IX_CLEAR_POZOS_PARADOS_CACHE_ESTADO ON dbo.CLEAR_POZOS_PARADOS_CACHE(ESTADO_POZO,SISTEMA);
END;
GO

IF OBJECT_ID(N'dbo.CLEAR_POZOS_PARADOS_HIST',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_POZOS_PARADOS_HIST(
        ID                   bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        FECHA_SNAPSHOT       datetime2(0) NOT NULL,
        SISTEMA              nvarchar(20)  NOT NULL,
        POZO_CLAVE           nvarchar(100) NOT NULL,
        POZO                 nvarchar(255) NOT NULL,
        BATERIA              nvarchar(255) NULL,
        ESTADO_POZO          nvarchar(40)  NOT NULL,
        ESTADO_ZAFIRO        nvarchar(500) NULL,
        PRODUCCION_PETROLEO  decimal(18,3) NULL
    );
    CREATE INDEX IX_CLEAR_POZOS_PARADOS_HIST_24H
      ON dbo.CLEAR_POZOS_PARADOS_HIST(FECHA_SNAPSHOT,POZO_CLAVE,SISTEMA)
      INCLUDE(PRODUCCION_PETROLEO,ESTADO_POZO);
END;
GO

IF OBJECT_ID(N'dbo.SP_CLEAR_POZOS_PARADOS_REFRESCAR',N'P') IS NULL
    EXEC(N'CREATE PROCEDURE dbo.SP_CLEAR_POZOS_PARADOS_REFRESCAR AS RETURN 0;');
GO
ALTER PROCEDURE dbo.SP_CLEAR_POZOS_PARADOS_REFRESCAR
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Ahora datetime2(0)=SYSDATETIME();
    DECLARE @Snapshot datetime2(0)=DATEADD(minute,(DATEDIFF(minute,CONVERT(datetime2(0),'20000101'),SYSDATETIME())/10)*10,CONVERT(datetime2(0),'20000101'));
    DECLARE @Lock int;
    EXEC @Lock=sys.sp_getapplock
        @Resource=N'CLEAR_POZOS_PARADOS_REFRESCAR',
        @LockMode=N'Exclusive',@LockOwner=N'Session',@LockTimeout=0;
    IF @Lock<0 RETURN;

    BEGIN TRY
        CREATE TABLE #Zafiro(
            POZO_CLAVE nvarchar(100) COLLATE DATABASE_DEFAULT NOT NULL PRIMARY KEY,
            ESTADO nvarchar(500) COLLATE DATABASE_DEFAULT NULL,
            METODO nvarchar(500) COLLATE DATABASE_DEFAULT NULL
        );

        IF OBJECT_ID(N'dbo.CLEAR_API_Q158_POZOS') IS NOT NULL
        BEGIN
            DECLARE @obj int=OBJECT_ID(N'dbo.CLEAR_API_Q158_POZOS');
            DECLARE @cPozo sysname=N'Pozo', @cEstado sysname, @cMetodo sysname, @cFecha sysname, @cId sysname;
            SELECT TOP(1) @cEstado=name FROM sys.columns WHERE object_id=@obj AND name LIKE N'%Cambio de estado>>Estado' ORDER BY column_id;
            SELECT TOP(1) @cMetodo=name FROM sys.columns WHERE object_id=@obj AND name LIKE N'%Sistema de Extracción[_]name' ORDER BY column_id;
            SELECT TOP(1) @cFecha=name FROM sys.columns WHERE object_id=@obj AND name=N'FechaCarga';
            SELECT TOP(1) @cId=name FROM sys.columns WHERE object_id=@obj AND name=N'CacheId';

            IF @cEstado IS NOT NULL
            BEGIN
                DECLARE @q nvarchar(max)=N'
                ;WITH Z AS(
                  SELECT dbo.FN_CLEAR_POZO_CLAVE(CONVERT(nvarchar(255),'+QUOTENAME(@cPozo)+N')) K,
                         CONVERT(nvarchar(500),'+QUOTENAME(@cEstado)+N') E,'+
                         CASE WHEN @cMetodo IS NULL THEN N'CAST(NULL AS nvarchar(500))' ELSE N'CONVERT(nvarchar(500),'+QUOTENAME(@cMetodo)+N')' END+N' M,
                         ROW_NUMBER() OVER(PARTITION BY dbo.FN_CLEAR_POZO_CLAVE(CONVERT(nvarchar(255),'+QUOTENAME(@cPozo)+N')) ORDER BY '+
                         CASE WHEN @cFecha IS NULL THEN N'(SELECT NULL)' ELSE QUOTENAME(@cFecha)+N' DESC' END+
                         CASE WHEN @cId IS NULL THEN N'' ELSE N','+QUOTENAME(@cId)+N' DESC' END+N') RN
                  FROM dbo.CLEAR_API_Q158_POZOS
                  WHERE '+QUOTENAME(@cPozo)+N' IS NOT NULL
                )
                INSERT INTO #Zafiro(POZO_CLAVE,ESTADO,METODO)
                SELECT K,E,M FROM Z WHERE RN=1 AND K<>N'''';';
                EXEC sys.sp_executesql @q;
            END;
        END;

        CREATE TABLE #Prod(
            POZO_CLAVE nvarchar(100) COLLATE DATABASE_DEFAULT NOT NULL PRIMARY KEY,
            PETROLEO decimal(18,3) NULL
        );
        IF OBJECT_ID(N'dbo.CLEAR_API_Q164_CACHE',N'U') IS NOT NULL
        BEGIN
            ;WITH P AS(
                SELECT dbo.FN_CLEAR_POZO_CLAVE(POZO) K,
                       TRY_CONVERT(decimal(18,3),PRODUCCION_PETROLEO) PET,
                       ROW_NUMBER() OVER(PARTITION BY dbo.FN_CLEAR_POZO_CLAVE(POZO)
                         ORDER BY DIA_OPERATIVO DESC,FECHA_HORA DESC,ID_TEST DESC) RN
                FROM dbo.CLEAR_API_Q164_CACHE
                WHERE POZO IS NOT NULL
            )
            INSERT INTO #Prod(POZO_CLAVE,PETROLEO)
            SELECT K,PET FROM P WHERE RN=1 AND K<>N'';
        END;

        CREATE TABLE #Actual(
            SISTEMA nvarchar(20) NOT NULL,
            POZO_CLAVE nvarchar(100) NOT NULL,
            POZO nvarchar(255) NOT NULL,
            BATERIA nvarchar(255) NULL,
            ESTADO_TELEMETRIA nvarchar(255) NULL,
            ESTADO_POZO nvarchar(40) NOT NULL,
            ESTADO_ZAFIRO nvarchar(500) NULL,
            METODO_ZAFIRO nvarchar(500) NULL,
            RPM real NULL,
            VARIADOR nvarchar(100) NULL,
            LLAVE nvarchar(100) NULL,
            LLAVE_AUTO nvarchar(100) NULL,
            PRODUCCION_PETROLEO decimal(18,3) NULL,
            FECHA_DATO datetime2(0) NULL
        );

        /* BM / Lufkin Pump-Off: solo RPM=0 con dato válido. */
        INSERT INTO #Actual
        SELECT N'MONITOREO',K.K,B.POZO,B.BATERIA,CONVERT(nvarchar(255),B.ESTADO),
               CASE
                 WHEN UPPER(CONVERT(nvarchar(255),B.ESTADO)) LIKE N'%HOA OFF%'
                   OR UPPER(CONVERT(nvarchar(255),B.ESTADO)) LIKE N'%FUNCIONAMIENTO DEFECTUOSO%' THEN N'PARO REAL'
                 WHEN UPPER(CONVERT(nvarchar(255),B.ESTADO)) LIKE N'%TIMED%'
                   OR UPPER(CONVERT(nvarchar(255),B.ESTADO)) LIKE N'%SETPOINT%' THEN N'PARO CONTROLADO'
                 WHEN UPPER(CONVERT(nvarchar(255),B.ESTADO)) LIKE N'%BOMBEO%'
                   OR UPPER(CONVERT(nvarchar(255),B.ESTADO)) LIKE N'%BOMBEANDO%' THEN N'VERIFICAR PARO'
                 ELSE N'PARADO'
               END,
               Z.ESTADO,Z.METODO,TRY_CONVERT(real,B.[QT:RPM]),
               CONVERT(nvarchar(100),B.[ET:VARIADOR]),CONVERT(nvarchar(100),B.[YT:LLAVE]),
               CONVERT(nvarchar(100),B.[YT:LLAVE-AUTO]),P.PETROLEO,TRY_CONVERT(datetime2(0),B.Fecha)
        FROM dbo.BM_RTQP B
        CROSS APPLY(SELECT dbo.FN_CLEAR_POZO_CLAVE(B.POZO) K)K
        LEFT JOIN #Zafiro Z ON Z.POZO_CLAVE=K.K
        LEFT JOIN #Prod P ON P.POZO_CLAVE=K.K
        WHERE TRY_CONVERT(real,B.[QT:RPM])=0 AND K.K<>N'';

        /* PCP: YT:POZO parado. Se informa cuando Zafiro no tiene estado o todavía figura Produciendo. */
        INSERT INTO #Actual
        SELECT N'PCP',K.K,PX.POZO,PX.BATERIA,CONVERT(nvarchar(255),PX.[YT:POZO]),N'PARO REAL',
               Z.ESTADO,Z.METODO,NULL,NULL,NULL,NULL,P.PETROLEO,NULL
        FROM dbo.PCP_RTQP PX
        CROSS APPLY(SELECT dbo.FN_CLEAR_POZO_CLAVE(PX.POZO) K)K
        LEFT JOIN #Zafiro Z ON Z.POZO_CLAVE=K.K
        LEFT JOIN #Prod P ON P.POZO_CLAVE=K.K
        WHERE UPPER(CONVERT(nvarchar(255),PX.[YT:POZO])) LIKE N'%PARAD%'
          AND (NULLIF(LTRIM(RTRIM(Z.ESTADO)),N'') IS NULL OR UPPER(Z.ESTADO) LIKE N'%PRODUCIENDO%')
          AND K.K<>N'';

        /* BES: ESTADO parado con el mismo criterio Zafiro que PCP. */
        INSERT INTO #Actual
        SELECT N'BES',K.K,BX.POZO,BX.BATERIA,CONVERT(nvarchar(255),BX.ESTADO),N'PARO REAL',
               Z.ESTADO,Z.METODO,NULL,NULL,NULL,NULL,P.PETROLEO,TRY_CONVERT(datetime2(0),BX.FEHA)
        FROM dbo.BES_RTQP BX
        CROSS APPLY(SELECT dbo.FN_CLEAR_POZO_CLAVE(BX.POZO) K)K
        LEFT JOIN #Zafiro Z ON Z.POZO_CLAVE=K.K
        LEFT JOIN #Prod P ON P.POZO_CLAVE=K.K
        WHERE UPPER(CONVERT(nvarchar(255),BX.ESTADO)) LIKE N'%PARAD%'
          AND (NULLIF(LTRIM(RTRIM(Z.ESTADO)),N'') IS NULL OR UPPER(Z.ESTADO) LIKE N'%PRODUCIENDO%')
          AND K.K<>N'';

        /* TECSS: ESTADO parado, excluyendo Downtime de Produccion (Perdida Localizada) de Zafiro. */
        INSERT INTO #Actual
        SELECT N'TECSS',K.K,TX.POZO,TX.BATERIA,CONVERT(nvarchar(255),TX.ESTADO),N'PARO REAL',
               Z.ESTADO,Z.METODO,NULL,NULL,NULL,NULL,P.PETROLEO,TRY_CONVERT(datetime2(0),TX.HOY)
        FROM dbo.TECSS_RTQP TX
        CROSS APPLY(SELECT dbo.FN_CLEAR_POZO_CLAVE(TX.POZO) K)K
        LEFT JOIN #Zafiro Z ON Z.POZO_CLAVE=K.K
        LEFT JOIN #Prod P ON P.POZO_CLAVE=K.K
        WHERE UPPER(CONVERT(nvarchar(255),TX.ESTADO)) LIKE N'%PARAD%'
          AND UPPER(ISNULL(Z.ESTADO,N'')) COLLATE Modern_Spanish_CI_AI NOT LIKE N'%DOWNTIME DE PRODUCCION%PERDIDA LOCALIZADA%'
          AND K.K<>N'';

        /* Snapshot: 10 min. La perdida del intervalo = m3/d / 144. */
        INSERT INTO dbo.CLEAR_POZOS_PARADOS_HIST
          (FECHA_SNAPSHOT,SISTEMA,POZO_CLAVE,POZO,BATERIA,ESTADO_POZO,ESTADO_ZAFIRO,PRODUCCION_PETROLEO)
        SELECT @Snapshot,A.SISTEMA,A.POZO_CLAVE,A.POZO,A.BATERIA,A.ESTADO_POZO,A.ESTADO_ZAFIRO,A.PRODUCCION_PETROLEO
        FROM #Actual A
        WHERE NOT EXISTS(
          SELECT 1 FROM dbo.CLEAR_POZOS_PARADOS_HIST H
          WHERE H.FECHA_SNAPSHOT=@Snapshot AND H.SISTEMA=A.SISTEMA AND H.POZO_CLAVE=A.POZO_CLAVE
        );

        DELETE FROM dbo.CLEAR_POZOS_PARADOS_HIST
        WHERE FECHA_SNAPSHOT<DATEADD(day,-35,@Ahora);

        BEGIN TRANSACTION;
          TRUNCATE TABLE dbo.CLEAR_POZOS_PARADOS_CACHE;

          INSERT INTO dbo.CLEAR_POZOS_PARADOS_CACHE
          (SISTEMA,POZO_CLAVE,POZO,BATERIA,ESTADO_TELEMETRIA,ESTADO_POZO,ESTADO_ZAFIRO,METODO_ZAFIRO,
           RPM,VARIADOR,LLAVE,LLAVE_AUTO,PRODUCCION_PETROLEO,PERDIDA_INSTANTANEA,PERDIDA_24H,FECHA_DATO,FECHA_CACHE)
          SELECT A.SISTEMA,A.POZO_CLAVE,A.POZO,A.BATERIA,A.ESTADO_TELEMETRIA,A.ESTADO_POZO,A.ESTADO_ZAFIRO,A.METODO_ZAFIRO,
                 A.RPM,A.VARIADOR,A.LLAVE,A.LLAVE_AUTO,A.PRODUCCION_PETROLEO,A.PRODUCCION_PETROLEO,
                 H.PERDIDA_24H,A.FECHA_DATO,@Ahora
          FROM #Actual A
          OUTER APPLY(
              SELECT CAST(SUM(ISNULL(X.PRODUCCION_PETROLEO,0))/144.0 AS decimal(18,3)) PERDIDA_24H
              FROM dbo.CLEAR_POZOS_PARADOS_HIST X
              WHERE X.SISTEMA=A.SISTEMA AND X.POZO_CLAVE=A.POZO_CLAVE
                AND X.FECHA_SNAPSHOT>=DATEADD(hour,-24,@Ahora)
          )H;
        COMMIT TRANSACTION;

        EXEC sys.sp_releaseapplock @Resource=N'CLEAR_POZOS_PARADOS_REFRESCAR',@LockOwner=N'Session';
        SELECT COUNT(*) AS POZOS_PARADOS,@Ahora AS FECHA_CACHE FROM dbo.CLEAR_POZOS_PARADOS_CACHE;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT>0 ROLLBACK;
        IF APPLOCK_MODE(N'public',N'CLEAR_POZOS_PARADOS_REFRESCAR',N'Session')<>N'NoLock'
          EXEC sys.sp_releaseapplock @Resource=N'CLEAR_POZOS_PARADOS_REFRESCAR',@LockOwner=N'Session';
        THROW;
    END CATCH;
END;
GO

IF DATABASE_PRINCIPAL_ID(N'fix') IS NOT NULL
BEGIN
    GRANT SELECT ON dbo.CLEAR_POZOS_PARADOS_CACHE TO [fix];
    GRANT SELECT ON dbo.CLEAR_POZOS_PARADOS_HIST TO [fix];
    GRANT EXECUTE ON dbo.SP_CLEAR_POZOS_PARADOS_REFRESCAR TO [fix];
END;
GO

EXEC dbo.SP_CLEAR_POZOS_PARADOS_REFRESCAR;
GO

USE [msdb];
GO
IF EXISTS(SELECT 1 FROM dbo.sysjobs WHERE name=N'CLEAR - Reporte Pozos Parados')
    EXEC dbo.sp_delete_job @job_name=N'CLEAR - Reporte Pozos Parados',@delete_unused_schedule=1;
GO
DECLARE @JobId uniqueidentifier,@Fecha int=CONVERT(int,CONVERT(char(8),GETDATE(),112));
EXEC dbo.sp_add_job @job_name=N'CLEAR - Reporte Pozos Parados',@enabled=1,
 @description=N'Refresca cada 10 minutos la cache del reporte de pozos parados. La web no consulta RTQP directamente.',
 @owner_login_name=N'sa',@job_id=@JobId OUTPUT;
EXEC dbo.sp_add_jobstep @job_id=@JobId,@step_name=N'Refrescar cache',@subsystem=N'TSQL',@database_name=N'LC_MDB',
 @command=N'EXEC dbo.SP_CLEAR_POZOS_PARADOS_REFRESCAR;',@retry_attempts=2,@retry_interval=2,@on_success_action=1,@on_fail_action=2;
EXEC dbo.sp_add_schedule @schedule_name=N'CLEAR - Pozos Parados cada 10 minutos',@enabled=1,
 @freq_type=4,@freq_interval=1,@freq_subday_type=4,@freq_subday_interval=10,@active_start_date=@Fecha,@active_start_time=0;
EXEC dbo.sp_attach_schedule @job_id=@JobId,@schedule_name=N'CLEAR - Pozos Parados cada 10 minutos';
EXEC dbo.sp_add_jobserver @job_id=@JobId,@server_name=N'(LOCAL)';
GO
