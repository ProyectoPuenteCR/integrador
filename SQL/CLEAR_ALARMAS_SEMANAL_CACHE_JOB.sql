USE [LC_MDB];
GO

/*
    CLEAR - Alarmas semanal (Pozos e Instalaciones)
    =============================================================
    Protección de rendimiento:
      - dbo.FIXALARMS / dbo.CLEAR_F_FIXALARMS son SOLO LECTURA.
      - Las páginas consultan los gráficos desde una tabla resumida.
      - El Job recarga únicamente hoy y ayer cada 10 minutos.
      - La carga inicial se limita a 14 días.
      - No se ejecuta TRUNCATE ni DELETE sobre las tablas de alarmas.

    Definiciones:
      P = Pozos cuyo ALM_ALMEXTFLD2 comienza con YPF.SC.
      I = Instalaciones, usando el prefijo de ALM_TAGNAME hasta el primer '_'.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.CLEAR_ALARMAS_SEMANA_CACHE',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_ALARMAS_SEMANA_CACHE
    (
        ID                   bigint IDENTITY(1,1) NOT NULL,
        TIPO                 char(1) NOT NULL,
        FECHA                date NOT NULL,
        HORA                 tinyint NOT NULL,
        ENTIDAD              nvarchar(255) NOT NULL,
        TIPO_INSTALACION     nvarchar(30) NULL,
        TAG                  nvarchar(255) NOT NULL,
        DESCRIPCION          nvarchar(1000) NULL,
        TOTAL                bigint NOT NULL,
        FECHA_ACTUALIZACION  datetime2(0) NOT NULL,
        CONSTRAINT PK_CLEAR_ALARMAS_SEMANA_CACHE PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_CLEAR_ALARMAS_SEMANA_TIPO CHECK (TIPO IN ('P','I')),
        CONSTRAINT CK_CLEAR_ALARMAS_SEMANA_HORA CHECK (HORA BETWEEN 0 AND 23)
    );
END;
GO

/* Migración segura para instalaciones existentes del caché. */
IF COL_LENGTH(N'dbo.CLEAR_ALARMAS_SEMANA_CACHE',N'TIPO_INSTALACION') IS NULL
BEGIN
    ALTER TABLE dbo.CLEAR_ALARMAS_SEMANA_CACHE ADD TIPO_INSTALACION nvarchar(30) NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.CLEAR_ALARMAS_SEMANA_CACHE') AND name=N'IX_CLEAR_ALARMAS_SEMANA_GRAFICO')
BEGIN
    CREATE NONCLUSTERED INDEX IX_CLEAR_ALARMAS_SEMANA_GRAFICO
        ON dbo.CLEAR_ALARMAS_SEMANA_CACHE (TIPO,FECHA,HORA,ENTIDAD)
        INCLUDE (TOTAL,TAG,DESCRIPCION,FECHA_ACTUALIZACION);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.CLEAR_ALARMAS_SEMANA_CACHE') AND name=N'IX_CLEAR_ALARMAS_SEMANA_TIPO_INSTALACION')
BEGIN
    CREATE NONCLUSTERED INDEX IX_CLEAR_ALARMAS_SEMANA_TIPO_INSTALACION
        ON dbo.CLEAR_ALARMAS_SEMANA_CACHE (TIPO,TIPO_INSTALACION,FECHA,HORA,ENTIDAD)
        INCLUDE (TOTAL,TAG,DESCRIPCION,FECHA_ACTUALIZACION);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.CLEAR_ALARMAS_SEMANA_CACHE') AND name=N'IX_CLEAR_ALARMAS_SEMANA_ENTIDAD_TAG')
BEGIN
    CREATE NONCLUSTERED INDEX IX_CLEAR_ALARMAS_SEMANA_ENTIDAD_TAG
        ON dbo.CLEAR_ALARMAS_SEMANA_CACHE (TIPO,FECHA,TAG)
        INCLUDE (ENTIDAD,HORA,TOTAL,DESCRIPCION);
END;
GO

IF NOT EXISTS
(
    SELECT 1
    FROM sys.indexes I
    JOIN sys.index_columns IC ON IC.object_id=I.object_id AND IC.index_id=I.index_id AND IC.key_ordinal=1
    JOIN sys.columns C ON C.object_id=IC.object_id AND C.column_id=IC.column_id
    WHERE I.object_id=OBJECT_ID(N'dbo.FIXALARMS') AND C.name=N'ALM_NATIVETIMEIN'
)
BEGIN
    RAISERROR(N'ADVERTENCIA: no se detectó un índice cuyo primer campo sea dbo.FIXALARMS.ALM_NATIVETIMEIN. Se recomienda ejecutar SQL/01_APLICAR_INDICES_SEGUROS.sql antes de habilitar el Job.',10,1) WITH NOWAIT;
END;
GO

CREATE OR ALTER PROCEDURE dbo.SP_CLEAR_ACTUALIZAR_ALARMAS_SEMANA_CACHE
    @DiasRecarga int = 2
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @DiasRecarga = CASE WHEN @DiasRecarga < 1 THEN 1 WHEN @DiasRecarga > 120 THEN 120 ELSE @DiasRecarga END;
    DECLARE @Desde date = CONVERT(date,DATEADD(day,-(@DiasRecarga-1),SYSDATETIME()));
    DECLARE @Hasta date = DATEADD(day,1,CONVERT(date,SYSDATETIME()));
    DECLARE @Actualizacion datetime2(0) = SYSDATETIME();

    CREATE TABLE #BASE
    (
        FECHA_HORA datetime2(3) NOT NULL,
        TAG nvarchar(255) NOT NULL,
        DESCRIPCION nvarchar(1000) NULL,
        POZO nvarchar(255) NULL
    );

    /* Se lee una sola vez el rango acotado. Se prioriza la vista con tags filtrados. */
    IF OBJECT_ID(N'dbo.CLEAR_F_FIXALARMS',N'V') IS NOT NULL
    BEGIN
        INSERT INTO #BASE(FECHA_HORA,TAG,DESCRIPCION,POZO)
        SELECT
            CONVERT(datetime2(3),A.ALM_NATIVETIMEIN),
            LTRIM(RTRIM(CONVERT(nvarchar(255),A.ALM_TAGNAME))),
            NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(1000),A.ALM_DESCR))),N''),
            NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255),A.ALM_ALMEXTFLD2))),N'')
        FROM dbo.CLEAR_F_FIXALARMS A
        WHERE A.ALM_NATIVETIMEIN>=@Desde
          AND A.ALM_NATIVETIMEIN<@Hasta
          AND A.ALM_TAGNAME IS NOT NULL
          AND LTRIM(RTRIM(CONVERT(nvarchar(255),A.ALM_TAGNAME)))<>N'';
    END
    ELSE
    BEGIN
        INSERT INTO #BASE(FECHA_HORA,TAG,DESCRIPCION,POZO)
        SELECT
            CONVERT(datetime2(3),A.ALM_NATIVETIMEIN),
            LTRIM(RTRIM(CONVERT(nvarchar(255),A.ALM_TAGNAME))),
            NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(1000),A.ALM_DESCR))),N''),
            NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255),A.ALM_ALMEXTFLD2))),N'')
        FROM dbo.FIXALARMS A
        WHERE A.ALM_NATIVETIMEIN>=@Desde
          AND A.ALM_NATIVETIMEIN<@Hasta
          AND A.ALM_TAGNAME IS NOT NULL
          AND LTRIM(RTRIM(CONVERT(nvarchar(255),A.ALM_TAGNAME)))<>N'';
    END;

    CREATE CLUSTERED INDEX IX_BASE_FECHA ON #BASE(FECHA_HORA);

    CREATE TABLE #NUEVO
    (
        TIPO char(1) NOT NULL,
        FECHA date NOT NULL,
        HORA tinyint NOT NULL,
        ENTIDAD nvarchar(255) NOT NULL,
        TIPO_INSTALACION nvarchar(30) NOT NULL,
        TAG nvarchar(255) NOT NULL,
        DESCRIPCION nvarchar(1000) NULL,
        TOTAL bigint NOT NULL
    );

    /* Instalaciones de superficie: mismo criterio utilizado por las grillas actuales. */
    INSERT INTO #NUEVO(TIPO,FECHA,HORA,ENTIDAD,TIPO_INSTALACION,TAG,DESCRIPCION,TOTAL)
    SELECT
        'I',
        CONVERT(date,B.FECHA_HORA),
        CONVERT(tinyint,DATEPART(hour,B.FECHA_HORA)),
        I.INSTALACION,
        T.TIPO_INSTALACION,
        B.TAG,
        MAX(B.DESCRIPCION),
        COUNT_BIG(*)
    FROM #BASE B
    CROSS APPLY (VALUES(LEFT(B.TAG,CHARINDEX(N'_',B.TAG+N'_')-1))) I(INSTALACION)
    CROSS APPLY (VALUES(CASE
        WHEN UPPER(I.INSTALACION) = N'PIALH3' THEN N'PIAS'
        WHEN UPPER(ISNULL(B.POZO,N'')) LIKE N'YPF.SC%' THEN N'POZO'
        WHEN UPPER(I.INSTALACION) LIKE N'EBB%' OR UPPER(I.INSTALACION) LIKE N'ELH%' THEN N'ENERGÍA'
        WHEN UPPER(I.INSTALACION) LIKE N'GL%' THEN N'GAS'
        WHEN UPPER(I.INSTALACION) LIKE N'PLH%' THEN N'PLANTA LH'
        WHEN UPPER(I.INSTALACION) LIKE N'PT%' THEN N'PLANTA TRAT.'
        WHEN UPPER(I.INSTALACION) LIKE N'RL%' OR UPPER(I.INSTALACION) LIKE N'B%' THEN N'BATERÍA'
        WHEN UPPER(I.INSTALACION) LIKE N'S%' THEN N'SATÉLITE'
        ELSE N'SIN CLASIFICAR'
    END)) T(TIPO_INSTALACION)
    GROUP BY CONVERT(date,B.FECHA_HORA),DATEPART(hour,B.FECHA_HORA),I.INSTALACION,T.TIPO_INSTALACION,B.TAG;

    /* Pozos: identificación compatible con las pantallas existentes. */
    INSERT INTO #NUEVO(TIPO,FECHA,HORA,ENTIDAD,TIPO_INSTALACION,TAG,DESCRIPCION,TOTAL)
    SELECT
        'P',
        CONVERT(date,B.FECHA_HORA),
        CONVERT(tinyint,DATEPART(hour,B.FECHA_HORA)),
        B.POZO,
        N'POZO',
        B.TAG,
        MAX(B.DESCRIPCION),
        COUNT_BIG(*)
    FROM #BASE B
    WHERE B.POZO IS NOT NULL AND UPPER(B.POZO) LIKE N'YPF.SC%'
    GROUP BY CONVERT(date,B.FECHA_HORA),DATEPART(hour,B.FECHA_HORA),B.POZO,B.TAG;

    BEGIN TRY
        BEGIN TRANSACTION;

        DELETE FROM dbo.CLEAR_ALARMAS_SEMANA_CACHE
        WHERE FECHA>=@Desde AND FECHA<@Hasta;

        INSERT INTO dbo.CLEAR_ALARMAS_SEMANA_CACHE
            (TIPO,FECHA,HORA,ENTIDAD,TIPO_INSTALACION,TAG,DESCRIPCION,TOTAL,FECHA_ACTUALIZACION)
        SELECT TIPO,FECHA,HORA,ENTIDAD,TIPO_INSTALACION,TAG,DESCRIPCION,TOTAL,@Actualizacion
        FROM #NUEVO;

        /* Retención de seguridad: dos años de resúmenes, nunca afecta FIXALARMS. */
        DELETE FROM dbo.CLEAR_ALARMAS_SEMANA_CACHE
        WHERE FECHA<DATEADD(day,-730,CONVERT(date,@Actualizacion));

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @Desde AS DESDE,
        DATEADD(day,-1,@Hasta) AS HASTA,
        COUNT_BIG(*) AS FILAS_CACHE,
        SUM(TOTAL) AS ALARMAS_RESUMIDAS,
        @Actualizacion AS FECHA_ACTUALIZACION
    FROM #NUEVO;
END;
GO

/* Carga inicial acotada: semana actual y anterior. Ejecutar preferentemente fuera de hora pico. */
EXEC dbo.SP_CLEAR_ACTUALIZAR_ALARMAS_SEMANA_CACHE @DiasRecarga=14;
GO

USE [msdb];
GO

IF EXISTS (SELECT 1 FROM dbo.sysjobs WHERE name=N'CLEAR - Alarmas semanal caché')
BEGIN
    EXEC dbo.sp_delete_job @job_name=N'CLEAR - Alarmas semanal caché', @delete_unused_schedule=1;
END;
GO

IF EXISTS (SELECT 1 FROM dbo.sysschedules WHERE name=N'CLEAR - Alarmas semanal cada 10 minutos')
BEGIN
    EXEC dbo.sp_delete_schedule @schedule_name=N'CLEAR - Alarmas semanal cada 10 minutos', @force_delete=1;
END;
GO

EXEC dbo.sp_add_job
    @job_name=N'CLEAR - Alarmas semanal caché',
    @enabled=1,
    @description=N'Resume únicamente hoy y ayer cada 10 minutos para las páginas Alarmas semanal de Pozos e Instalaciones.';
GO

EXEC dbo.sp_add_jobstep
    @job_name=N'CLEAR - Alarmas semanal caché',
    @step_name=N'Actualizar hoy y ayer',
    @subsystem=N'TSQL',
    @database_name=N'LC_MDB',
    @command=N'EXEC dbo.SP_CLEAR_ACTUALIZAR_ALARMAS_SEMANA_CACHE @DiasRecarga=2;',
    @retry_attempts=2,
    @retry_interval=2;
GO

EXEC dbo.sp_add_schedule
    @schedule_name=N'CLEAR - Alarmas semanal cada 10 minutos',
    @enabled=1,
    @freq_type=4,
    @freq_interval=1,
    @freq_subday_type=4,
    @freq_subday_interval=10,
    @active_start_time=000000;
GO

EXEC dbo.sp_attach_schedule
    @job_name=N'CLEAR - Alarmas semanal caché',
    @schedule_name=N'CLEAR - Alarmas semanal cada 10 minutos';
GO

EXEC dbo.sp_add_jobserver @job_name=N'CLEAR - Alarmas semanal caché';
GO

USE [LC_MDB];
GO

SELECT
    TIPO,
    COALESCE(TIPO_INSTALACION,N'SIN CLASIFICAR') AS TIPO_INSTALACION,
    MIN(FECHA) AS DESDE,
    MAX(FECHA) AS HASTA,
    COUNT_BIG(*) AS FILAS_RESUMEN,
    SUM(TOTAL) AS ALARMAS_RESUMIDAS,
    MAX(FECHA_ACTUALIZACION) AS ULTIMA_ACTUALIZACION
FROM dbo.CLEAR_ALARMAS_SEMANA_CACHE
GROUP BY TIPO,COALESCE(TIPO_INSTALACION,N'SIN CLASIFICAR')
ORDER BY TIPO,TIPO_INSTALACION;
GO

/*
   Carga histórica opcional (NO se ejecuta automáticamente):
   Ejecutar fuera de hora pico y ajustar la cantidad según necesidad.

   EXEC dbo.SP_CLEAR_ACTUALIZAR_ALARMAS_SEMANA_CACHE @DiasRecarga=90;
*/
