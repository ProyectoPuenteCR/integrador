USE [LC_MDB];
GO
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/*
  CLEAR - DASHBOARD INSTALACIONES DE SUPERFICIE SIN POZOS
  =======================================================
  Objetivo:
  - Mantener intacta la cache global usada por las grillas generales.
  - Generar metricas exclusivas para Dashboard Inst Sup.
  - Reutilizar la misma regla de clasificacion que PHP:
      * PIALH3 se conserva como PIAS.
      * Si ALM_ALMEXTFLD2 comienza con YPF.SC (o el TAG ya viene YPF.SC),
        el registro corresponde a POZO.
      * El resto pertenece al universo de instalaciones de superficie.
  - PC10, PC12, PC14, SR5, SB7, etc. no se hardcodean: si el campo externo
    los identifica como YPF.SC quedan automaticamente fuera del dashboard.
*/

CREATE OR ALTER PROCEDURE dbo.SP_CLEAR_ACTUALIZAR_CACHE_INST_SUP
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    SET DEADLOCK_PRIORITY LOW;

    IF OBJECT_ID(N'dbo.CLEAR_CACHE_OPERATIVA', N'U') IS NULL RETURN;
    IF OBJECT_ID(N'dbo.CLEAR_F_FIXALARMS', N'V') IS NULL RETURN;

    DECLARE @ahora DATETIME2(0)=SYSDATETIME();
    DECLARE @lockResult INT;
    EXEC @lockResult=sys.sp_getapplock
        @Resource=N'CLEAR_CACHE_INST_SUP',
        @LockMode=N'Exclusive',
        @LockOwner=N'Session',
        @LockTimeout=0;
    IF @lockResult<0 RETURN;

    CREATE TABLE #S
    (
        METRICA NVARCHAR(50) NOT NULL,
        ORDEN INT NOT NULL,
        DIMENSION1 NVARCHAR(500) NULL,
        DIMENSION2 NVARCHAR(500) NULL,
        VALOR1 DECIMAL(38,4) NULL,
        VALOR2 DECIMAL(38,4) NULL,
        VALOR3 DECIMAL(38,4) NULL,
        TEXTO1 NVARCHAR(1000) NULL,
        FECHA_CACHE DATETIME2(0) NOT NULL
    );

    SELECT
        A.ALM_NATIVETIMEIN,
        LTRIM(RTRIM(CONVERT(nvarchar(500),A.ALM_TAGNAME))) TAG,
        UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(500),A.ALM_ALMEXTFLD2),N'')))) CAMPO_EXT,
        UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(100),A.ALM_ALMPRIORITY),N'')))) PRIORIDAD,
        UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(255),A.ALM_ALMSTATUS),N'')))) ESTADO,
        UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(500),A.ALM_VALUE),N'')))) VALOR,
        CASE
            WHEN LEFT(
                    UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(500),A.ALM_TAGNAME),N'')))),
                    CHARINDEX(N'_',UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(500),A.ALM_TAGNAME),N''))))+N'_')-1
                 )=N'PIALH3' THEN CONVERT(bit,0)
            WHEN UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(500),A.ALM_ALMEXTFLD2),N'')))) LIKE N'YPF.SC%'
              OR UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(500),A.ALM_TAGNAME),N'')))) LIKE N'YPF.SC%'
              THEN CONVERT(bit,1)
            ELSE CONVERT(bit,0)
        END ES_POZO
    INTO #BASE7
    FROM dbo.CLEAR_F_FIXALARMS A
    WHERE A.ALM_NATIVETIMEIN>=DATEADD(day,-6,CONVERT(date,@ahora));

    CREATE CLUSTERED INDEX IX_CLEAR_INST_BASE7_FECHA ON #BASE7(ALM_NATIVETIMEIN);

    SELECT ALM_NATIVETIMEIN,TAG,CAMPO_EXT,PRIORIDAD,ESTADO,VALOR
    INTO #BASE7_INST
    FROM #BASE7
    WHERE ES_POZO=0;

    CREATE CLUSTERED INDEX IX_CLEAR_INST_BASE7I_FECHA ON #BASE7_INST(ALM_NATIVETIMEIN);

    SELECT *
    INTO #BASE24_INST
    FROM #BASE7_INST
    WHERE ALM_NATIVETIMEIN>=DATEADD(hour,-24,@ahora);

    /* El TAG se acota a 255 caracteres para que la clave del índice quede
       muy por debajo del límite de 900 bytes de SQL Server. */
    SELECT DISTINCT CONVERT(nvarchar(255),UPPER(LTRIM(RTRIM(TAG)))) COLLATE DATABASE_DEFAULT AS TAG_NORM
    INTO #POZO_TAGS
    FROM #BASE7
    WHERE ES_POZO=1 AND NULLIF(LTRIM(RTRIM(TAG)),N'') IS NOT NULL;

    CREATE UNIQUE CLUSTERED INDEX IX_CLEAR_INST_POZO_TAGS ON #POZO_TAGS(TAG_NORM);

    DECLARE @instActivas BIGINT=0;
    DECLARE @instReconocidas BIGINT=0;
    DECLARE @instSuprimidas BIGINT=0;
    DECLARE @instComentadas BIGINT=0;
    DECLARE @sql NVARCHAR(MAX);

    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS_ONLY' AND COLUMN_NAME='ALM_ALMEXTFLD2'
    )
    BEGIN
        SET @sql=N'
          SELECT @n=COUNT_BIG(*)
          FROM dbo.CLEAR_F_FIXALARMS_ONLY A
          CROSS APPLY (SELECT UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(255),A.ALM_TAGNAME),N'''')))) TAG_NORM) T
          CROSS APPLY (SELECT LEFT(T.TAG_NORM,CHARINDEX(N''_'',T.TAG_NORM+N''_'')-1) INST) I
          WHERE I.INST=N''PIALH3''
             OR NOT (
                UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(500),A.ALM_ALMEXTFLD2),N'''')))) LIKE N''YPF.SC%''
                OR T.TAG_NORM LIKE N''YPF.SC%''
             );';
        EXEC sys.sp_executesql @sql,N'@n bigint OUTPUT',@n=@instActivas OUTPUT;
    END
    ELSE
    BEGIN
        SELECT @instActivas=COUNT_BIG(*)
        FROM dbo.CLEAR_F_FIXALARMS_ONLY A
        CROSS APPLY (SELECT UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(255),A.ALM_TAGNAME),N'')))) TAG_NORM) T
        CROSS APPLY (SELECT LEFT(T.TAG_NORM,CHARINDEX(N'_',T.TAG_NORM+N'_')-1) INST) I
        WHERE I.INST=N'PIALH3'
           OR (
              T.TAG_NORM NOT LIKE N'YPF.SC%'
              AND NOT EXISTS (
                  SELECT 1 FROM #POZO_TAGS P
                  WHERE P.TAG_NORM=T.TAG_NORM COLLATE DATABASE_DEFAULT
              )
           );
    END;

    IF OBJECT_ID(N'dbo.CLEAR_F_FIXALARMS_RECONOCIDAS',N'V') IS NOT NULL
    BEGIN
        IF EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS_RECONOCIDAS' AND COLUMN_NAME='ALM_ALMEXTFLD2'
        )
        BEGIN
            SET @sql=N'
              SELECT @n=COUNT_BIG(*)
              FROM dbo.CLEAR_F_FIXALARMS_RECONOCIDAS R
              CROSS APPLY (SELECT UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(255),R.TAG_FIX),N'''')))) TAG_NORM) T
              CROSS APPLY (SELECT LEFT(T.TAG_NORM,CHARINDEX(N''_'',T.TAG_NORM+N''_'')-1) INST) I
              WHERE I.INST=N''PIALH3''
                 OR NOT (
                    UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(500),R.ALM_ALMEXTFLD2),N'''')))) LIKE N''YPF.SC%''
                    OR T.TAG_NORM LIKE N''YPF.SC%''
                 );';
            EXEC sys.sp_executesql @sql,N'@n bigint OUTPUT',@n=@instReconocidas OUTPUT;
        END
        ELSE
        BEGIN
            SELECT @instReconocidas=COUNT_BIG(*)
            FROM dbo.CLEAR_F_FIXALARMS_RECONOCIDAS R
            CROSS APPLY (SELECT UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(255),R.TAG_FIX),N'')))) TAG_NORM) T
            CROSS APPLY (SELECT LEFT(T.TAG_NORM,CHARINDEX(N'_',T.TAG_NORM+N'_')-1) INST) I
            WHERE I.INST=N'PIALH3'
               OR (
                  T.TAG_NORM NOT LIKE N'YPF.SC%'
                  AND NOT EXISTS (
                      SELECT 1 FROM #POZO_TAGS P
                      WHERE P.TAG_NORM=T.TAG_NORM COLLATE DATABASE_DEFAULT
                  )
               );
        END;
    END;

    IF OBJECT_ID(N'dbo.CLEAR_F_FIXALARMS_SUPRIMIDAS24H',N'V') IS NOT NULL
    BEGIN
        IF EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS_SUPRIMIDAS24H' AND COLUMN_NAME='ALM_ALMEXTFLD2'
        )
        BEGIN
            SET @sql=N'
              SELECT @n=COUNT_BIG(*)
              FROM dbo.CLEAR_F_FIXALARMS_SUPRIMIDAS24H S
              CROSS APPLY (SELECT UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(255),S.TagID),N'''')))) TAG_NORM) T
              CROSS APPLY (SELECT LEFT(T.TAG_NORM,CHARINDEX(N''_'',T.TAG_NORM+N''_'')-1) INST) I
              WHERE I.INST=N''PIALH3''
                 OR NOT (
                    UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(500),S.ALM_ALMEXTFLD2),N'''')))) LIKE N''YPF.SC%''
                    OR T.TAG_NORM LIKE N''YPF.SC%''
                 );';
            EXEC sys.sp_executesql @sql,N'@n bigint OUTPUT',@n=@instSuprimidas OUTPUT;
        END
        ELSE
        BEGIN
            SELECT @instSuprimidas=COUNT_BIG(*)
            FROM dbo.CLEAR_F_FIXALARMS_SUPRIMIDAS24H S
            CROSS APPLY (SELECT UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(255),S.TagID),N'')))) TAG_NORM) T
            CROSS APPLY (SELECT LEFT(T.TAG_NORM,CHARINDEX(N'_',T.TAG_NORM+N'_')-1) INST) I
            WHERE I.INST=N'PIALH3'
               OR (
                  T.TAG_NORM NOT LIKE N'YPF.SC%'
                  AND NOT EXISTS (
                      SELECT 1 FROM #POZO_TAGS P
                      WHERE P.TAG_NORM=T.TAG_NORM COLLATE DATABASE_DEFAULT
                  )
               );
        END;
    END;

    IF OBJECT_ID(N'dbo.FIXALARMS_COMENTARIOS',N'U') IS NOT NULL
    BEGIN
        SELECT @instComentadas=COUNT_BIG(*)
        FROM dbo.FIXALARMS_COMENTARIOS C
        CROSS APPLY (SELECT UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(255),C.TAG_FIX),N'')))) TAG_NORM) T
        CROSS APPLY (SELECT LEFT(T.TAG_NORM,CHARINDEX(N'_',T.TAG_NORM+N'_')-1) INST) I
        WHERE C.ACTIVO=1
          AND C.COMENTARIO IS NOT NULL
          AND LTRIM(RTRIM(C.COMENTARIO))<>N''
          AND (
              I.INST=N'PIALH3'
              OR (
                  T.TAG_NORM NOT LIKE N'YPF.SC%'
                  AND NOT EXISTS (
                      SELECT 1 FROM #POZO_TAGS P
                      WHERE P.TAG_NORM=T.TAG_NORM COLLATE DATABASE_DEFAULT
                  )
              )
          );
    END;

    INSERT #S VALUES(N'KPI',101,N'INST_TOTAL24H',NULL,(SELECT COUNT_BIG(*) FROM #BASE24_INST),NULL,NULL,NULL,@ahora);
    INSERT #S VALUES(
        N'KPI',102,N'INST_TAGS_UNICOS24H',NULL,
        (SELECT COUNT_BIG(*) FROM (SELECT DISTINCT TAG FROM #BASE24_INST WHERE NULLIF(TAG,N'') IS NOT NULL) D),
        NULL,NULL,NULL,@ahora
    );
    INSERT #S VALUES(N'KPI',103,N'INST_CRITICAS',NULL,(SELECT COUNT_BIG(*) FROM #BASE24_INST WHERE PRIORIDAD IN (N'HIGH',N'HI',N'ALTA',N'CRITICAL',N'CRITICA',N'CRÍTICA')),NULL,NULL,NULL,@ahora);
    INSERT #S VALUES(N'KPI',104,N'INST_ACTIVAS',NULL,@instActivas,NULL,NULL,NULL,@ahora);
    INSERT #S VALUES(N'KPI',105,N'INST_RECONOCIDAS',NULL,@instReconocidas,NULL,NULL,NULL,@ahora);
    INSERT #S VALUES(N'KPI',106,N'INST_SUPRIMIDAS',NULL,@instSuprimidas,NULL,NULL,NULL,@ahora);
    INSERT #S VALUES(N'KPI',107,N'INST_COMENTADAS',NULL,@instComentadas,NULL,NULL,NULL,@ahora);
    INSERT #S VALUES(N'KPI',108,N'INST_ULTIMA_ALARMA',NULL,NULL,NULL,NULL,(SELECT TOP(1) CONVERT(nvarchar(19),ALM_NATIVETIMEIN,120) FROM #BASE7_INST ORDER BY ALM_NATIVETIMEIN DESC),@ahora);

    ;WITH T AS
    (
        SELECT TAG,COUNT_BIG(*) TOTAL
        FROM #BASE24_INST
        WHERE NULLIF(TAG,N'') IS NOT NULL
        GROUP BY TAG
    ),
    R AS
    (
        SELECT ROW_NUMBER() OVER(ORDER BY TOTAL DESC,TAG) ORDEN,TAG,TOTAL
        FROM T
    )
    INSERT #S
    SELECT N'TOP_ALARMAS_INST',ORDEN,TAG,NULL,TOTAL,NULL,NULL,NULL,@ahora
    FROM R WHERE ORDEN<=20;

    INSERT #S
    SELECT N'TENDENCIA_INST',
           ROW_NUMBER() OVER(ORDER BY CONVERT(date,ALM_NATIVETIMEIN)),
           CONVERT(nvarchar(10),CONVERT(date,ALM_NATIVETIMEIN),23),
           NULL,COUNT_BIG(*),NULL,NULL,NULL,@ahora
    FROM #BASE7_INST
    GROUP BY CONVERT(date,ALM_NATIVETIMEIN);

    ;WITH P AS
    (
        SELECT CASE
                 WHEN PRIORIDAD IN (N'HIGH',N'HI',N'ALTA',N'CRITICAL',N'CRITICA',N'CRÍTICA') THEN N'HIGH'
                 WHEN PRIORIDAD IN (N'MED',N'MEDIUM',N'MEDIA') THEN N'MEDIUM'
                 WHEN PRIORIDAD IN (N'LOW',N'LO',N'BAJA') THEN N'LOW'
                 ELSE N'INFO'
               END CLASE,
               COUNT_BIG(*) TOTAL
        FROM #BASE24_INST
        GROUP BY CASE
                   WHEN PRIORIDAD IN (N'HIGH',N'HI',N'ALTA',N'CRITICAL',N'CRITICA',N'CRÍTICA') THEN N'HIGH'
                   WHEN PRIORIDAD IN (N'MED',N'MEDIUM',N'MEDIA') THEN N'MEDIUM'
                   WHEN PRIORIDAD IN (N'LOW',N'LO',N'BAJA') THEN N'LOW'
                   ELSE N'INFO'
                 END
    )
    INSERT #S
    SELECT N'PRIORIDAD_INST',ROW_NUMBER() OVER(ORDER BY CLASE),CLASE,NULL,TOTAL,NULL,NULL,NULL,@ahora
    FROM P;

    INSERT #S
    SELECT N'HORARIA_INST',
           DATEPART(hour,ALM_NATIVETIMEIN)+1,
           CONVERT(nvarchar(2),DATEPART(hour,ALM_NATIVETIMEIN)),
           NULL,
           SUM(CASE WHEN PRIORIDAD IN (N'HIGH',N'HI',N'ALTA',N'CRITICAL',N'CRITICA',N'CRÍTICA') THEN 1 ELSE 0 END),
           SUM(CASE WHEN PRIORIDAD IN (N'MED',N'MEDIUM',N'MEDIA') THEN 1 ELSE 0 END),
           SUM(CASE WHEN PRIORIDAD NOT IN (N'HIGH',N'HI',N'ALTA',N'CRITICAL',N'CRITICA',N'CRÍTICA',N'MED',N'MEDIUM',N'MEDIA') THEN 1 ELSE 0 END),
           NULL,@ahora
    FROM #BASE24_INST
    GROUP BY DATEPART(hour,ALM_NATIVETIMEIN);

    INSERT #S
    SELECT N'FLUJO_INST',
           DATEPART(hour,ALM_NATIVETIMEIN)+1,
           CONVERT(nvarchar(2),DATEPART(hour,ALM_NATIVETIMEIN)),
           NULL,
           SUM(CASE WHEN VALOR NOT IN (N'NORMAL',N'OK',N'HABILITADO') THEN 1 ELSE 0 END),
           SUM(CASE WHEN VALOR IN (N'NORMAL',N'OK',N'HABILITADO') THEN 1 ELSE 0 END),
           NULL,NULL,@ahora
    FROM #BASE24_INST
    GROUP BY DATEPART(hour,ALM_NATIVETIMEIN);

    INSERT #S
    SELECT N'HEATMAP_INST',
           ROW_NUMBER() OVER(ORDER BY CONVERT(date,ALM_NATIVETIMEIN),DATEPART(hour,ALM_NATIVETIMEIN)),
           CONVERT(nvarchar(10),CONVERT(date,ALM_NATIVETIMEIN),23),
           CONVERT(nvarchar(2),DATEPART(hour,ALM_NATIVETIMEIN)),
           COUNT_BIG(*),NULL,NULL,NULL,@ahora
    FROM #BASE7_INST
    GROUP BY CONVERT(date,ALM_NATIVETIMEIN),DATEPART(hour,ALM_NATIVETIMEIN);

    ;WITH I AS
    (
        SELECT CASE WHEN CHARINDEX(N'_',TAG)>0 THEN LEFT(TAG,CHARINDEX(N'_',TAG)-1) ELSE TAG END INSTALACION,
               COUNT_BIG(*) TOTAL,
               SUM(CASE WHEN PRIORIDAD IN (N'HIGH',N'HI',N'ALTA',N'CRITICAL',N'CRITICA',N'CRÍTICA') THEN 1 ELSE 0 END) CRITICAS
        FROM #BASE24_INST
        WHERE NULLIF(TAG,N'') IS NOT NULL
        GROUP BY CASE WHEN CHARINDEX(N'_',TAG)>0 THEN LEFT(TAG,CHARINDEX(N'_',TAG)-1) ELSE TAG END
    ),
    R AS
    (
        SELECT ROW_NUMBER() OVER(ORDER BY TOTAL DESC,INSTALACION) ORDEN,*
        FROM I
    )
    INSERT #S
    SELECT N'INSTALACIONES_INST',ORDEN,INSTALACION,NULL,TOTAL,CRITICAS,NULL,NULL,@ahora
    FROM R WHERE ORDEN<=8;

    IF OBJECT_ID(N'dbo.CLEAR_F_FIXALARMS_RECONOCIDAS',N'V') IS NOT NULL
    BEGIN
        IF EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS_RECONOCIDAS' AND COLUMN_NAME='ALM_ALMEXTFLD2'
        )
        BEGIN
            SET @sql=N'
              ;WITH O AS
              (
                  SELECT R.OPERADOR,COUNT_BIG(*) TOTAL
                  FROM dbo.CLEAR_F_FIXALARMS_RECONOCIDAS R
                  CROSS APPLY (SELECT UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(255),R.TAG_FIX),N'''')))) TAG_NORM) T
                  CROSS APPLY (SELECT LEFT(T.TAG_NORM,CHARINDEX(N''_'',T.TAG_NORM+N''_'')-1) INST) I
                  WHERE R.OPERADOR IS NOT NULL
                    AND (
                        I.INST=N''PIALH3''
                        OR NOT (
                            UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(500),R.ALM_ALMEXTFLD2),N'''')))) LIKE N''YPF.SC%''
                            OR T.TAG_NORM LIKE N''YPF.SC%''
                        )
                    )
                  GROUP BY R.OPERADOR
              ),
              X AS
              (
                  SELECT ROW_NUMBER() OVER(ORDER BY TOTAL DESC,OPERADOR) ORDEN,OPERADOR,TOTAL
                  FROM O
              )
              INSERT #S
              SELECT N''OPERADORES_INST'',ORDEN,CONVERT(nvarchar(500),OPERADOR),NULL,TOTAL,NULL,NULL,NULL,@ahora
              FROM X WHERE ORDEN<=3;';
            EXEC sys.sp_executesql @sql,N'@ahora datetime2(0)',@ahora=@ahora;
        END
        ELSE
        BEGIN
            ;WITH O AS
            (
                SELECT R.OPERADOR,COUNT_BIG(*) TOTAL
                FROM dbo.CLEAR_F_FIXALARMS_RECONOCIDAS R
                CROSS APPLY (SELECT UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(255),R.TAG_FIX),N'')))) TAG_NORM) T
                CROSS APPLY (SELECT LEFT(T.TAG_NORM,CHARINDEX(N'_',T.TAG_NORM+N'_')-1) INST) I
                WHERE R.OPERADOR IS NOT NULL
                  AND (
                      I.INST=N'PIALH3'
                      OR (
                          T.TAG_NORM NOT LIKE N'YPF.SC%'
                          AND NOT EXISTS (
                              SELECT 1 FROM #POZO_TAGS P
                              WHERE P.TAG_NORM=T.TAG_NORM COLLATE DATABASE_DEFAULT
                          )
                      )
                  )
                GROUP BY R.OPERADOR
            ),
            X AS
            (
                SELECT ROW_NUMBER() OVER(ORDER BY TOTAL DESC,OPERADOR) ORDEN,OPERADOR,TOTAL
                FROM O
            )
            INSERT #S
            SELECT N'OPERADORES_INST',ORDEN,CONVERT(nvarchar(500),OPERADOR),NULL,TOTAL,NULL,NULL,NULL,@ahora
            FROM X WHERE ORDEN<=3;
        END;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        DELETE FROM dbo.CLEAR_CACHE_OPERATIVA
        WHERE
            (METRICA=N'KPI' AND DIMENSION1 IN
                (N'INST_TOTAL24H',N'INST_TAGS_UNICOS24H',N'INST_CRITICAS',N'INST_ACTIVAS',
                 N'INST_RECONOCIDAS',N'INST_SUPRIMIDAS',N'INST_COMENTADAS',N'INST_ULTIMA_ALARMA'))
            OR METRICA IN
                (N'TOP_ALARMAS_INST',N'TENDENCIA_INST',N'PRIORIDAD_INST',
                 N'HORARIA_INST',N'FLUJO_INST',N'HEATMAP_INST',N'OPERADORES_INST',N'INSTALACIONES_INST');

        INSERT dbo.CLEAR_CACHE_OPERATIVA
            (METRICA,ORDEN,DIMENSION1,DIMENSION2,VALOR1,VALOR2,VALOR3,TEXTO1,FECHA_CACHE)
        SELECT METRICA,ORDEN,DIMENSION1,DIMENSION2,VALOR1,VALOR2,VALOR3,TEXTO1,FECHA_CACHE
        FROM #S;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;
        EXEC sys.sp_releaseapplock @Resource=N'CLEAR_CACHE_INST_SUP',@LockOwner=N'Session';
        THROW;
    END CATCH;

    EXEC sys.sp_releaseapplock @Resource=N'CLEAR_CACHE_INST_SUP',@LockOwner=N'Session';
END;
GO

/* Al reinstalar, primero regeneramos la cache global para revertir cualquier
   ejecucion parcial anterior y luego agregamos la cache exclusiva de superficie. */
IF OBJECT_ID(N'dbo.SP_CLEAR_ACTUALIZAR_CACHE_OPERATIVA',N'P') IS NOT NULL
    EXEC dbo.SP_CLEAR_ACTUALIZAR_CACHE_OPERATIVA;
EXEC dbo.SP_CLEAR_ACTUALIZAR_CACHE_INST_SUP;
GO

USE [msdb];
GO
DECLARE @jobId UNIQUEIDENTIFIER;
DECLARE @stepId INT;

SELECT @jobId=J.job_id
FROM dbo.sysjobs J
WHERE J.name=N'CLEAR - Caché operativa cada 5 minutos';

IF @jobId IS NOT NULL
BEGIN
    SELECT @stepId=S.step_id
    FROM dbo.sysjobsteps S
    WHERE S.job_id=@jobId
      AND S.step_name=N'Actualizar caché';

    IF @stepId IS NOT NULL
    BEGIN
        EXEC dbo.sp_update_jobstep
            @job_name=N'CLEAR - Caché operativa cada 5 minutos',
            @step_id=@stepId,
            @command=N'EXEC dbo.SP_CLEAR_ACTUALIZAR_CACHE_OPERATIVA; EXEC dbo.SP_CLEAR_ACTUALIZAR_CACHE_INST_SUP;';
    END
    ELSE
    BEGIN
        RAISERROR(N'No se encontró el paso "Actualizar caché" del Job CLEAR - Caché operativa cada 5 minutos.',10,1);
    END;
END
ELSE
BEGIN
    RAISERROR(N'No se encontró el Job CLEAR - Caché operativa cada 5 minutos.',10,1);
END;
GO

USE [LC_MDB];
GO
SELECT METRICA,DIMENSION1,VALOR1,VALOR2,FECHA_CACHE
FROM dbo.CLEAR_CACHE_OPERATIVA
WHERE METRICA IN
      (N'TOP_ALARMAS_INST',N'TENDENCIA_INST',N'PRIORIDAD_INST',
       N'HORARIA_INST',N'FLUJO_INST',N'HEATMAP_INST',N'OPERADORES_INST',N'INSTALACIONES_INST')
   OR (METRICA=N'KPI' AND DIMENSION1 LIKE N'INST_%')
ORDER BY METRICA,ORDEN;
GO
