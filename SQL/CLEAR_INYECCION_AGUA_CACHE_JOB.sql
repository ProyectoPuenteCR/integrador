USE [LC_MDB];
GO

/*
    CLEAR - Inyección de Agua (corrección)
    ============================================================
    Origen de SOLO LECTURA:
        dbo.INY_RTQP

    Destino consultado por la pantalla web:
        dbo.CLEAR_INYECCION_AGUA_CACHE

    Frecuencia:
        Job de SQL Server Agent cada 20 minutos.

    IMPORTANTE:
    - No modifica dbo.INY_RTQP.
    - Detecta los nombres reales de las columnas del origen, incluso si
      contienen saltos de línea, espacios adicionales o textos repetidos.
*/

IF OBJECT_ID(N'dbo.CLEAR_INYECCION_AGUA_CACHE', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_INYECCION_AGUA_CACHE
    (
        ID                    bigint IDENTITY(1,1) NOT NULL,
        PLANTA                nvarchar(150) NULL,
        SATELITE              nvarchar(150) NULL,
        ZONA                  nvarchar(200) NULL,
        POZO                  nvarchar(200) NULL,
        PANTALLA              nvarchar(2048) NULL,
        [Presión Inyeccion]   decimal(28,12) NULL,
        [Caudal Inst]         decimal(28,12) NULL,
        [Acumulado Hoy]       decimal(28,12) NULL,
        Proyectado            decimal(28,12) NULL,
        Cierre                decimal(28,12) NULL,
        FEHA                  nvarchar(100) NULL,
        [Promedio dia]        decimal(28,12) NULL,
        [Promedio Ayer]       decimal(28,12) NULL,
        ACTUALIZADO_EN        datetime2(0) NOT NULL
            CONSTRAINT DF_CLEAR_INYECCION_AGUA_ACTUALIZADO DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_CLEAR_INYECCION_AGUA_CACHE PRIMARY KEY CLUSTERED (ID)
    );
END;
GO

IF NOT EXISTS
(
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.CLEAR_INYECCION_AGUA_CACHE')
      AND name = N'IX_CLEAR_INYECCION_AGUA_FILTROS'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_CLEAR_INYECCION_AGUA_FILTROS
        ON dbo.CLEAR_INYECCION_AGUA_CACHE (PLANTA, SATELITE, ZONA, POZO)
        INCLUDE ([Presión Inyeccion], [Caudal Inst], [Acumulado Hoy], Cierre, ACTUALIZADO_EN);
END;
GO

CREATE OR ALTER PROCEDURE dbo.SP_CLEAR_ACTUALIZAR_INYECCION_AGUA_CACHE
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @OrigenId int = OBJECT_ID(N'dbo.INY_RTQP');

    IF @OrigenId IS NULL
    BEGIN
        THROW 51001, 'No existe dbo.INY_RTQP. No se actualizó la caché de Inyección de Agua.', 1;
    END;

    DECLARE
        @ColPlanta       sysname,
        @ColSatelite     sysname,
        @ColZona         sysname,
        @ColPozo         sysname,
        @ColPantalla     sysname,
        @ColPresion      sysname,
        @ColCaudal       sysname,
        @ColAcumulado    sysname,
        @ColProyectado   sysname,
        @ColCierre       sysname,
        @ColFecha        sysname,
        @ColPromedioDia  sysname,
        @ColPromedioAyer sysname;

    /*
       Se normaliza el nombre quitando espacios, tabulaciones y saltos de línea.
       Esto corrige casos como una columna cuyo nombre real contiene varias líneas
       con el texto "Caudal Inst" repetido.
    */
    ;WITH Columnas AS
    (
        SELECT
            c.column_id,
            c.name,
            UPPER(
                REPLACE(REPLACE(REPLACE(REPLACE(
                    c.name,
                    N' ', N''),
                    NCHAR(9), N''),
                    NCHAR(13), N''),
                    NCHAR(10), N'')
            ) AS NombreNormalizado
        FROM sys.columns AS c
        WHERE c.object_id = @OrigenId
    )
    SELECT
        @ColPlanta =
            (SELECT TOP (1) name FROM Columnas
             WHERE NombreNormalizado = N'PLANTA'
             ORDER BY column_id),
        @ColSatelite =
            (SELECT TOP (1) name FROM Columnas
             WHERE NombreNormalizado = N'SATELITE'
                OR NombreNormalizado LIKE N'SAT%LITE'
             ORDER BY CASE WHEN NombreNormalizado = N'SATELITE' THEN 0 ELSE 1 END, column_id),
        @ColZona =
            (SELECT TOP (1) name FROM Columnas
             WHERE NombreNormalizado = N'ZONA'
             ORDER BY column_id),
        @ColPozo =
            (SELECT TOP (1) name FROM Columnas
             WHERE NombreNormalizado = N'POZO'
             ORDER BY column_id),
        @ColPantalla =
            (SELECT TOP (1) name FROM Columnas
             WHERE NombreNormalizado = N'PANTALLA'
             ORDER BY column_id),
        @ColPresion =
            (SELECT TOP (1) name FROM Columnas
             WHERE NombreNormalizado LIKE N'%PRES%INYECCION%'
             ORDER BY CASE WHEN NombreNormalizado IN (N'PRESIONINYECCION', N'PRESIÓNINYECCION') THEN 0 ELSE 1 END, column_id),
        @ColCaudal =
            (SELECT TOP (1) name FROM Columnas
             WHERE NombreNormalizado LIKE N'%CAUDAL%INST%'
             ORDER BY CASE WHEN NombreNormalizado = N'CAUDALINST' THEN 0 ELSE 1 END, column_id),
        @ColAcumulado =
            (SELECT TOP (1) name FROM Columnas
             WHERE NombreNormalizado LIKE N'%ACUMULADO%HOY%'
             ORDER BY CASE WHEN NombreNormalizado = N'ACUMULADOHOY' THEN 0 ELSE 1 END, column_id),
        @ColProyectado =
            (SELECT TOP (1) name FROM Columnas
             WHERE NombreNormalizado LIKE N'%PROYECTADO%'
             ORDER BY CASE WHEN NombreNormalizado = N'PROYECTADO' THEN 0 ELSE 1 END, column_id),
        @ColCierre =
            (SELECT TOP (1) name FROM Columnas
             WHERE NombreNormalizado LIKE N'%CIERRE%'
             ORDER BY CASE WHEN NombreNormalizado = N'CIERRE' THEN 0 ELSE 1 END, column_id),
        @ColFecha =
            (SELECT TOP (1) name FROM Columnas
             WHERE NombreNormalizado IN (N'FEHA', N'FECHA')
                OR NombreNormalizado LIKE N'%FEHA%'
                OR NombreNormalizado LIKE N'%FECHA%'
             ORDER BY CASE WHEN NombreNormalizado = N'FEHA' THEN 0 WHEN NombreNormalizado = N'FECHA' THEN 1 ELSE 2 END, column_id),
        @ColPromedioDia =
            (SELECT TOP (1) name FROM Columnas
             WHERE NombreNormalizado LIKE N'%PROMEDIO%DIA%'
             ORDER BY CASE WHEN NombreNormalizado = N'PROMEDIODIA' THEN 0 ELSE 1 END, column_id),
        @ColPromedioAyer =
            (SELECT TOP (1) name FROM Columnas
             WHERE NombreNormalizado LIKE N'%PROMEDIO%AYER%'
             ORDER BY CASE WHEN NombreNormalizado = N'PROMEDIOAYER' THEN 0 ELSE 1 END, column_id);

    DECLARE @Faltantes nvarchar(1800) = N'';

    IF @ColPlanta IS NULL    SET @Faltantes += N' PLANTA,';
    IF @ColSatelite IS NULL  SET @Faltantes += N' SATELITE,';
    IF @ColZona IS NULL      SET @Faltantes += N' ZONA,';
    IF @ColPozo IS NULL      SET @Faltantes += N' POZO,';
    IF @ColPantalla IS NULL  SET @Faltantes += N' PANTALLA,';
    IF @ColPresion IS NULL   SET @Faltantes += N' Presión Inyeccion,';
    IF @ColCaudal IS NULL    SET @Faltantes += N' Caudal Inst,';
    IF @ColAcumulado IS NULL SET @Faltantes += N' Acumulado Hoy,';
    IF @ColCierre IS NULL    SET @Faltantes += N' Cierre,';

    IF @Faltantes <> N''
    BEGIN
        DECLARE @Mensaje nvarchar(2048) =
            N'No se pudieron identificar estas columnas requeridas de dbo.INY_RTQP:'
            + LEFT(@Faltantes, LEN(@Faltantes) - 1)
            + N'. Revise los nombres reales en sys.columns.';

        THROW 51003, @Mensaje, 1;
    END;

    DECLARE @Ahora datetime2(0) = SYSDATETIME();

    CREATE TABLE #NUEVOS_DATOS
    (
        PLANTA                nvarchar(150) NULL,
        SATELITE              nvarchar(150) NULL,
        ZONA                  nvarchar(200) NULL,
        POZO                  nvarchar(200) NULL,
        PANTALLA              nvarchar(2048) NULL,
        [Presión Inyeccion]   decimal(28,12) NULL,
        [Caudal Inst]         decimal(28,12) NULL,
        [Acumulado Hoy]       decimal(28,12) NULL,
        Proyectado            decimal(28,12) NULL,
        Cierre                decimal(28,12) NULL,
        FEHA                  nvarchar(100) NULL,
        [Promedio dia]        decimal(28,12) NULL,
        [Promedio Ayer]       decimal(28,12) NULL
    );

    DECLARE
        @ExprPlanta       nvarchar(max),
        @ExprSatelite     nvarchar(max),
        @ExprZona         nvarchar(max),
        @ExprPozo         nvarchar(max),
        @ExprPantalla     nvarchar(max),
        @ExprPresion      nvarchar(max),
        @ExprCaudal       nvarchar(max),
        @ExprAcumulado    nvarchar(max),
        @ExprProyectado   nvarchar(max),
        @ExprCierre       nvarchar(max),
        @ExprFecha        nvarchar(max),
        @ExprPromedioDia  nvarchar(max),
        @ExprPromedioAyer nvarchar(max),
        @SQL              nvarchar(max);

    SET @ExprPlanta =
        N'NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(150), src.' + QUOTENAME(@ColPlanta) + N'))), N'''')';
    SET @ExprSatelite =
        N'NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(150), src.' + QUOTENAME(@ColSatelite) + N'))), N'''')';
    SET @ExprZona =
        N'NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(200), src.' + QUOTENAME(@ColZona) + N'))), N'''')';
    SET @ExprPozo =
        N'NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(200), src.' + QUOTENAME(@ColPozo) + N'))), N'''')';
    SET @ExprPantalla =
        N'NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(2048), src.' + QUOTENAME(@ColPantalla) + N'))), N'''')';

    SET @ExprPresion =
        N'COALESCE(TRY_CONVERT(decimal(28,12), src.' + QUOTENAME(@ColPresion) + N'), '
        + N'TRY_CONVERT(decimal(28,12), REPLACE(LTRIM(RTRIM(CONVERT(nvarchar(100), src.' + QUOTENAME(@ColPresion) + N'))), N'','', N''.'')))';
    SET @ExprCaudal =
        N'COALESCE(TRY_CONVERT(decimal(28,12), src.' + QUOTENAME(@ColCaudal) + N'), '
        + N'TRY_CONVERT(decimal(28,12), REPLACE(LTRIM(RTRIM(CONVERT(nvarchar(100), src.' + QUOTENAME(@ColCaudal) + N'))), N'','', N''.'')))';
    SET @ExprAcumulado =
        N'COALESCE(TRY_CONVERT(decimal(28,12), src.' + QUOTENAME(@ColAcumulado) + N'), '
        + N'TRY_CONVERT(decimal(28,12), REPLACE(LTRIM(RTRIM(CONVERT(nvarchar(100), src.' + QUOTENAME(@ColAcumulado) + N'))), N'','', N''.'')))';
    SET @ExprCierre =
        N'COALESCE(TRY_CONVERT(decimal(28,12), src.' + QUOTENAME(@ColCierre) + N'), '
        + N'TRY_CONVERT(decimal(28,12), REPLACE(LTRIM(RTRIM(CONVERT(nvarchar(100), src.' + QUOTENAME(@ColCierre) + N'))), N'','', N''.'')))';

    SET @ExprProyectado = CASE
        WHEN @ColProyectado IS NULL THEN N'CAST(NULL AS decimal(28,12))'
        ELSE N'COALESCE(TRY_CONVERT(decimal(28,12), src.' + QUOTENAME(@ColProyectado) + N'), '
             + N'TRY_CONVERT(decimal(28,12), REPLACE(LTRIM(RTRIM(CONVERT(nvarchar(100), src.' + QUOTENAME(@ColProyectado) + N'))), N'','', N''.'')))'
    END;

    SET @ExprFecha = CASE
        WHEN @ColFecha IS NULL THEN N'CAST(NULL AS nvarchar(100))'
        ELSE N'COALESCE(CONVERT(nvarchar(19), TRY_CONVERT(datetime2(0), src.' + QUOTENAME(@ColFecha) + N'), 120), '
             + N'NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(100), src.' + QUOTENAME(@ColFecha) + N'))), N''''))'
    END;

    SET @ExprPromedioDia = CASE
        WHEN @ColPromedioDia IS NULL THEN N'CAST(NULL AS decimal(28,12))'
        ELSE N'COALESCE(TRY_CONVERT(decimal(28,12), src.' + QUOTENAME(@ColPromedioDia) + N'), '
             + N'TRY_CONVERT(decimal(28,12), REPLACE(LTRIM(RTRIM(CONVERT(nvarchar(100), src.' + QUOTENAME(@ColPromedioDia) + N'))), N'','', N''.'')))'
    END;

    SET @ExprPromedioAyer = CASE
        WHEN @ColPromedioAyer IS NULL THEN N'CAST(NULL AS decimal(28,12))'
        ELSE N'COALESCE(TRY_CONVERT(decimal(28,12), src.' + QUOTENAME(@ColPromedioAyer) + N'), '
             + N'TRY_CONVERT(decimal(28,12), REPLACE(LTRIM(RTRIM(CONVERT(nvarchar(100), src.' + QUOTENAME(@ColPromedioAyer) + N'))), N'','', N''.'')))'
    END;

    SET @SQL = N'
        INSERT INTO #NUEVOS_DATOS
        (
            PLANTA,
            SATELITE,
            ZONA,
            POZO,
            PANTALLA,
            [Presión Inyeccion],
            [Caudal Inst],
            [Acumulado Hoy],
            Proyectado,
            Cierre,
            FEHA,
            [Promedio dia],
            [Promedio Ayer]
        )
        SELECT
            ' + @ExprPlanta + N',
            ' + @ExprSatelite + N',
            ' + @ExprZona + N',
            ' + @ExprPozo + N',
            ' + @ExprPantalla + N',
            ' + @ExprPresion + N',
            ' + @ExprCaudal + N',
            ' + @ExprAcumulado + N',
            ' + @ExprProyectado + N',
            ' + @ExprCierre + N',
            ' + @ExprFecha + N',
            ' + @ExprPromedioDia + N',
            ' + @ExprPromedioAyer + N'
        FROM dbo.INY_RTQP AS src;';

    EXEC sys.sp_executesql @SQL;

    /* Ante una lectura transitoria vacía se conserva la última caché válida. */
    IF NOT EXISTS (SELECT 1 FROM #NUEVOS_DATOS)
       AND EXISTS (SELECT 1 FROM dbo.CLEAR_INYECCION_AGUA_CACHE)
    BEGIN
        THROW 51002, 'dbo.INY_RTQP devolvió cero filas. Se conserva la última caché válida.', 1;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        /* TRUNCATE solamente sobre la tabla caché de CLEAR. */
        TRUNCATE TABLE dbo.CLEAR_INYECCION_AGUA_CACHE;

        INSERT INTO dbo.CLEAR_INYECCION_AGUA_CACHE
        (
            PLANTA,
            SATELITE,
            ZONA,
            POZO,
            PANTALLA,
            [Presión Inyeccion],
            [Caudal Inst],
            [Acumulado Hoy],
            Proyectado,
            Cierre,
            FEHA,
            [Promedio dia],
            [Promedio Ayer],
            ACTUALIZADO_EN
        )
        SELECT
            PLANTA,
            SATELITE,
            ZONA,
            POZO,
            PANTALLA,
            [Presión Inyeccion],
            [Caudal Inst],
            [Acumulado Hoy],
            Proyectado,
            Cierre,
            FEHA,
            [Promedio dia],
            [Promedio Ayer],
            @Ahora
        FROM #NUEVOS_DATOS;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT COUNT_BIG(*) AS REGISTROS_CARGADOS, @Ahora AS ACTUALIZADO_EN
    FROM dbo.CLEAR_INYECCION_AGUA_CACHE;
END;
GO

/* Permiso de lectura para el usuario de la aplicación, si existe en la base. */
IF USER_ID(N'fix') IS NOT NULL
    GRANT SELECT ON dbo.CLEAR_INYECCION_AGUA_CACHE TO [fix];
GO

/* Primera carga inmediata. */
EXEC dbo.SP_CLEAR_ACTUALIZAR_INYECCION_AGUA_CACHE;
GO

/* Crear o recrear el Job. Requiere SQL Server Agent iniciado. */
USE [msdb];
GO

IF EXISTS
(
    SELECT 1
    FROM dbo.sysjobs
    WHERE name = N'CLEAR - Actualizar Inyección de Agua'
)
BEGIN
    EXEC dbo.sp_delete_job
        @job_name = N'CLEAR - Actualizar Inyección de Agua',
        @delete_unused_schedule = 1;
END;
GO

/* Eliminar un schedule huérfano de una ejecución incompleta anterior. */
IF EXISTS
(
    SELECT 1
    FROM dbo.sysschedules AS s
    WHERE s.name = N'CLEAR - Inyección de Agua cada 20 minutos'
      AND NOT EXISTS
      (
          SELECT 1
          FROM dbo.sysjobschedules AS js
          WHERE js.schedule_id = s.schedule_id
      )
)
BEGIN
    EXEC dbo.sp_delete_schedule
        @schedule_name = N'CLEAR - Inyección de Agua cada 20 minutos';
END;
GO

DECLARE @JobId uniqueidentifier;
DECLARE @FechaInicio int = CONVERT(int, CONVERT(char(8), GETDATE(), 112));
DECLARE @Propietario sysname = SUSER_SNAME();

EXEC dbo.sp_add_job
    @job_name = N'CLEAR - Actualizar Inyección de Agua',
    @enabled = 1,
    @description = N'Actualiza cada 20 minutos dbo.CLEAR_INYECCION_AGUA_CACHE leyendo dbo.INY_RTQP sin modificarla.',
    @owner_login_name = @Propietario,
    @job_id = @JobId OUTPUT;

EXEC dbo.sp_add_jobstep
    @job_id = @JobId,
    @step_name = N'Actualizar caché de Inyección de Agua',
    @subsystem = N'TSQL',
    @database_name = N'LC_MDB',
    @command = N'EXEC dbo.SP_CLEAR_ACTUALIZAR_INYECCION_AGUA_CACHE;',
    @retry_attempts = 2,
    @retry_interval = 2,
    @on_success_action = 1,
    @on_fail_action = 2;

EXEC dbo.sp_add_schedule
    @schedule_name = N'CLEAR - Inyección de Agua cada 20 minutos',
    @enabled = 1,
    @freq_type = 4,
    @freq_interval = 1,
    @freq_subday_type = 4,
    @freq_subday_interval = 20,
    @active_start_date = @FechaInicio,
    @active_start_time = 0;

EXEC dbo.sp_attach_schedule
    @job_id = @JobId,
    @schedule_name = N'CLEAR - Inyección de Agua cada 20 minutos';

EXEC dbo.sp_add_jobserver
    @job_id = @JobId,
    @server_name = N'(LOCAL)';
GO

USE [LC_MDB];
GO

/* Verificación final. */
SELECT TOP (100)
    PLANTA,
    SATELITE,
    ZONA,
    POZO,
    PANTALLA,
    [Presión Inyeccion],
    [Caudal Inst],
    [Acumulado Hoy],
    Proyectado,
    Cierre,
    FEHA,
    [Promedio dia],
    [Promedio Ayer],
    ACTUALIZADO_EN
FROM dbo.CLEAR_INYECCION_AGUA_CACHE
ORDER BY PLANTA, SATELITE, ZONA, POZO;
GO
