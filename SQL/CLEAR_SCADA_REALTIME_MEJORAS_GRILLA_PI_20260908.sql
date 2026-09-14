USE [LC_MDB];
GO

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* =============================================================
   CLEAR — SCADA Real time · vínculo PI liviano
   - Lleva WebId, nombre de punto y EngineeringUnits desde
     dbo.PI_Points_Stage hacia dbo.CLEAR_SCADA_TAG_MAP.
   - El navegador sigue consultando solamente la vista operativa.
   - Sin escrituras sobre PI_Points_Stage, la fuente SCADA o FIXALARMS.
============================================================= */

IF OBJECT_ID(N'dbo.CLEAR_SCADA_TAG_MAP',N'U') IS NULL
 OR OBJECT_ID(N'dbo.CLEAR_SCADA_REALTIME_V',N'V') IS NULL
    THROW 51240,N'Primero debe instalarse el módulo SCADA Real time.',1;

IF OBJECT_ID(N'dbo.PI_Points_Stage',N'U') IS NULL
    THROW 51241,N'No se encontró dbo.PI_Points_Stage.',1;

IF COL_LENGTH(N'dbo.PI_Points_Stage',N'Name') IS NULL
 OR COL_LENGTH(N'dbo.PI_Points_Stage',N'WebId') IS NULL
 OR COL_LENGTH(N'dbo.PI_Points_Stage',N'EngineeringUnits') IS NULL
    THROW 51242,N'PI_Points_Stage no contiene Name, WebId y EngineeringUnits.',1;
GO

CREATE OR ALTER PROCEDURE dbo.SP_CLEAR_SCADA_SINCRONIZAR_PI_MAP
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @LockResult int,@Updated bigint=0,@Inserted bigint=0;

    UPDATE dbo.CLEAR_SCADA_RUNTIME_STATUS
       SET ESTADO=N'EJECUTANDO',INICIO=SYSDATETIME(),FIN=NULL,FILAS=NULL,DETALLE=NULL
     WHERE COMPONENTE=N'PI_MAP';
    IF @@ROWCOUNT=0
        INSERT dbo.CLEAR_SCADA_RUNTIME_STATUS(COMPONENTE,ESTADO,INICIO)
        VALUES(N'PI_MAP',N'EJECUTANDO',SYSDATETIME());

    BEGIN TRY
        BEGIN TRAN;
        EXEC @LockResult=sys.sp_getapplock
            @Resource=N'CLEAR_SCADA_PI_MAP',
            @LockMode=N'Exclusive',
            @LockOwner=N'Transaction',
            @LockTimeout=0;

        IF @LockResult<0
        BEGIN
            ROLLBACK;
            UPDATE dbo.CLEAR_SCADA_RUNTIME_STATUS
               SET ESTADO=N'OMITIDO',FIN=SYSDATETIME(),DETALLE=N'Ya existe otra sincronización PI en curso.'
             WHERE COMPONENTE=N'PI_MAP';
            RETURN;
        END;

        CREATE TABLE #PiPoints
        (
            CLEAN_NAME nvarchar(450) COLLATE DATABASE_DEFAULT NOT NULL,
            PI_POINT   nvarchar(255) COLLATE DATABASE_DEFAULT NOT NULL,
            PI_WEBID   nvarchar(512) COLLATE DATABASE_DEFAULT NOT NULL,
            UNIDAD     nvarchar(64)  COLLATE DATABASE_DEFAULT NULL
        );

        INSERT #PiPoints(CLEAN_NAME,PI_POINT,PI_WEBID,UNIDAD)
        SELECT
            LEFT(P.CLEAN_NAME,450),
            LEFT(P.CLEAN_NAME,255),
            LEFT(W.CLEAN_WEBID,512),
            LEFT(NULLIF(U.CLEAN_UNIT,N''),64)
        FROM dbo.PI_Points_Stage S
        CROSS APPLY(SELECT LTRIM(RTRIM(REPLACE(REPLACE(REPLACE(
            CONVERT(nvarchar(512),S.[Name]),NCHAR(34),N''),NCHAR(160),N' '),NCHAR(65279),N''))) AS CLEAN_NAME)P
        CROSS APPLY(SELECT LTRIM(RTRIM(REPLACE(REPLACE(REPLACE(
            CONVERT(nvarchar(512),S.[WebId]),NCHAR(34),N''),NCHAR(160),N' '),NCHAR(65279),N''))) AS CLEAN_WEBID)W
        CROSS APPLY(SELECT LTRIM(RTRIM(REPLACE(REPLACE(REPLACE(
            CONVERT(nvarchar(128),S.[EngineeringUnits]),NCHAR(34),N''),NCHAR(160),N' '),NCHAR(65279),N''))) AS CLEAN_UNIT)U
        WHERE NULLIF(P.CLEAN_NAME,N'') IS NOT NULL
          AND NULLIF(W.CLEAN_WEBID,N'') IS NOT NULL;

        CREATE INDEX IX_TEMP_PI_NAME ON #PiPoints(CLEAN_NAME);

        CREATE TABLE #Best
        (
            TAG       nvarchar(255) COLLATE DATABASE_DEFAULT NOT NULL PRIMARY KEY,
            PI_POINT  nvarchar(255) COLLATE DATABASE_DEFAULT NOT NULL,
            PI_WEBID  nvarchar(512) COLLATE DATABASE_DEFAULT NOT NULL,
            UNIDAD    nvarchar(64)  COLLATE DATABASE_DEFAULT NULL
        );

        ;WITH Tags AS
        (
            SELECT DISTINCT TAG
            FROM dbo.CLEAR_SCADA_REALTIME_V
            WHERE NULLIF(LTRIM(RTRIM(TAG)),N'') IS NOT NULL
        ),
        Matches AS
        (
            SELECT T.TAG,P.PI_POINT,P.PI_WEBID,P.UNIDAD,
                   ROW_NUMBER() OVER
                   (
                       PARTITION BY T.TAG
                       ORDER BY CASE WHEN P.CLEAN_NAME=T.TAG THEN 0 ELSE 1 END,P.PI_POINT
                   ) AS RN
            FROM Tags T
            JOIN #PiPoints P
              ON P.CLEAN_NAME=T.TAG COLLATE DATABASE_DEFAULT
              OR P.CLEAN_NAME=(N'LHC_'+T.TAG) COLLATE DATABASE_DEFAULT
        )
        INSERT #Best(TAG,PI_POINT,PI_WEBID,UNIDAD)
        SELECT TAG,PI_POINT,PI_WEBID,UNIDAD FROM Matches WHERE RN=1;

        UPDATE M
           SET M.PI_POINT=B.PI_POINT,
               M.PI_WEBID=B.PI_WEBID,
               M.UNIDAD=COALESCE(NULLIF(M.UNIDAD,N''),B.UNIDAD),
               M.UPDATED_AT=SYSDATETIME(),
               M.UPDATED_BY=N'PI_POINTS_STAGE'
        FROM dbo.CLEAR_SCADA_TAG_MAP M
        JOIN #Best B ON B.TAG=M.TAG
        WHERE NULLIF(LTRIM(RTRIM(ISNULL(M.PI_WEBID,N''))),N'') IS NULL;
        SET @Updated=@@ROWCOUNT;

        INSERT dbo.CLEAR_SCADA_TAG_MAP
        (
            TAG,UNIDAD,PI_POINT,PI_WEBID,ALARM_TAG,ACTIVO,UPDATED_AT,UPDATED_BY
        )
        SELECT
            B.TAG,B.UNIDAD,B.PI_POINT,B.PI_WEBID,B.TAG,1,SYSDATETIME(),N'PI_POINTS_STAGE'
        FROM #Best B
        WHERE NOT EXISTS
        (
            SELECT 1 FROM dbo.CLEAR_SCADA_TAG_MAP M WHERE M.TAG=B.TAG
        );
        SET @Inserted=@@ROWCOUNT;

        COMMIT;

        UPDATE dbo.CLEAR_SCADA_RUNTIME_STATUS
           SET ESTADO=N'COMPLETADO',FIN=SYSDATETIME(),FILAS=@Updated+@Inserted,
               DETALLE=N'WebId y unidades sincronizados desde PI_Points_Stage.'
         WHERE COMPONENTE=N'PI_MAP';
    END TRY
    BEGIN CATCH
        IF XACT_STATE()<>0 ROLLBACK;
        UPDATE dbo.CLEAR_SCADA_RUNTIME_STATUS
           SET ESTADO=N'ERROR',FIN=SYSDATETIME(),DETALLE=LEFT(ERROR_MESSAGE(),2000)
         WHERE COMPONENTE=N'PI_MAP';
        THROW;
    END CATCH;
END;
GO

EXEC dbo.SP_CLEAR_SCADA_SINCRONIZAR_PI_MAP;
GO

/* Sincronización cada 6 horas, fuera del refresco web. */
BEGIN TRY
    DECLARE @JobName sysname=N'CLEAR - Sincronizar puntos PI SCADA';
    DECLARE @JobId uniqueidentifier;
    SELECT @JobId=job_id FROM msdb.dbo.sysjobs WHERE name=@JobName;

    IF @JobId IS NULL
    BEGIN
        EXEC msdb.dbo.sp_add_job
            @job_name=@JobName,
            @enabled=1,
            @description=N'Actualiza WebId y unidades de SCADA Real time desde PI_Points_Stage.',
            @job_id=@JobId OUTPUT;
        EXEC msdb.dbo.sp_add_jobstep
            @job_id=@JobId,
            @step_name=N'Sincronizar mapa PI',
            @subsystem=N'TSQL',
            @database_name=N'LC_MDB',
            @command=N'EXEC dbo.SP_CLEAR_SCADA_SINCRONIZAR_PI_MAP;',
            @retry_attempts=2,
            @retry_interval=2;
        EXEC msdb.dbo.sp_add_jobschedule
            @job_id=@JobId,
            @name=N'Cada 6 horas',
            @freq_type=4,
            @freq_interval=1,
            @freq_subday_type=8,
            @freq_subday_interval=6,
            @active_start_time=000500;
        EXEC msdb.dbo.sp_add_jobserver @job_id=@JobId;
    END
    ELSE
        EXEC msdb.dbo.sp_update_job @job_id=@JobId,@enabled=1;
END TRY
BEGIN CATCH
    PRINT N'AVISO: la sincronización inicial terminó, pero no se pudo crear el Job horario. '+ERROR_MESSAGE();
END CATCH;
GO

SELECT
    COUNT_BIG(*) AS TAGS_VISIBLES,
    SUM(CASE WHEN NULLIF(LTRIM(RTRIM(PI_WEBID)),N'') IS NOT NULL THEN CONVERT(bigint,1) ELSE CONVERT(bigint,0) END) AS TAGS_CON_WEBID,
    SUM(CASE WHEN NULLIF(LTRIM(RTRIM(PI_WEBID)),N'') IS NULL THEN CONVERT(bigint,1) ELSE CONVERT(bigint,0) END) AS TAGS_SIN_WEBID,
    SUM(CASE WHEN NULLIF(LTRIM(RTRIM(UNIDAD)),N'') IS NOT NULL THEN CONVERT(bigint,1) ELSE CONVERT(bigint,0) END) AS TAGS_CON_UNIDAD,
    N'CORRECTO' AS RESULTADO
FROM dbo.CLEAR_SCADA_REALTIME_V;

SELECT COMPONENTE,ESTADO,INICIO,FIN,FILAS,DETALLE
FROM dbo.CLEAR_SCADA_RUNTIME_STATUS
WHERE COMPONENTE=N'PI_MAP';
GO
