USE [LC_MDB];
GO

/*
    CLEAR - Maestro de supervisores por instalación/batería

    Objetivo:
      - Una asignación única por batería normalizada.
      - Relacionar el maestro con las fuentes locales de baterías sin consultar
        tablas grandes desde cada pantalla web.
      - Dejar preparado el vínculo para futuras grillas de alarmas y pozos,
        sin mostrar todavía Supervisor ni Jefe de zona en esas pantallas.

    La clave elimina espacios, guiones, guiones bajos, puntos y barras, de modo
    que valores como "CG 05", "CG05" y "CG-05" puedan vincularse entre sí.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.CLEAR_SUPERVISORES_INSTALACIONES',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_SUPERVISORES_INSTALACIONES
    (
        ID                      int IDENTITY(1,1) NOT NULL,
        BATERIA                 nvarchar(255) NOT NULL,
        BATERIA_CLAVE AS
            UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA))),
                N' ',N''),N'-',N''),N'_',N''),N'.',N''),N'/',N'')) PERSISTED,
        ZONA                    nvarchar(150) NOT NULL,
        SUPERVISOR              nvarchar(200) NOT NULL,
        JEFE_ZONA               nvarchar(200) NOT NULL,
        ACTIVO                  bit NOT NULL CONSTRAINT DF_CLEAR_SUP_INST_ACTIVO DEFAULT (1),
        FECHA_ALTA              datetime2(0) NOT NULL CONSTRAINT DF_CLEAR_SUP_INST_ALTA DEFAULT (SYSDATETIME()),
        USUARIO_ALTA            nvarchar(128) NULL,
        FECHA_MODIFICACION      datetime2(0) NOT NULL CONSTRAINT DF_CLEAR_SUP_INST_MOD DEFAULT (SYSDATETIME()),
        USUARIO_MODIFICACION    nvarchar(128) NULL,
        CONSTRAINT PK_CLEAR_SUPERVISORES_INSTALACIONES PRIMARY KEY CLUSTERED (ID)
    );
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE object_id=OBJECT_ID(N'dbo.CLEAR_SUPERVISORES_INSTALACIONES')
      AND name=N'UX_CLEAR_SUPERVISORES_INST_BATERIA_CLAVE'
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_CLEAR_SUPERVISORES_INST_BATERIA_CLAVE
        ON dbo.CLEAR_SUPERVISORES_INSTALACIONES (BATERIA_CLAVE)
        INCLUDE (BATERIA,ZONA,SUPERVISOR,JEFE_ZONA,ACTIVO);
END;
GO

/*
   Carga inicial tomada de Usuarios_supervisores.xlsx.
   Solo inserta baterías faltantes: si el script se vuelve a ejecutar, conserva
   cualquier modificación posterior realizada desde el ABM.
*/
INSERT INTO dbo.CLEAR_SUPERVISORES_INSTALACIONES
    (BATERIA,ZONA,SUPERVISOR,JEFE_ZONA,ACTIVO,USUARIO_ALTA,USUARIO_MODIFICACION)
SELECT V.BATERIA,V.ZONA,V.SUPERVISOR,V.JEFE_ZONA,1,N'CARGA_INICIAL_EXCEL',N'CARGA_INICIAL_EXCEL'
FROM
(
    VALUES
        (N'BB 02',N'CED Zona II',N'Millanahuel J',N'Gustavo Arce'),
        (N'BB 03',N'CED Zona II',N'Millanahuel J',N'Gustavo Arce'),
        (N'CE 10',N'CED Zona II',N'Soria Gabriel',N'Gustavo Arce'),
        (N'CE 11',N'CED Zona II',N'Soria Gabriel',N'Gustavo Arce'),
        (N'CE 11 (Col Aux CE11)',N'CED Zona II',N'Soria Gabriel',N'Gustavo Arce'),
        (N'CE 12',N'CED Zona II',N'Fernandez Bruno',N'Gustavo Arce'),
        (N'CE 13',N'CED Zona II',N'Reyna J',N'Gustavo Arce'),
        (N'CE 15',N'CED Zona II',N'Millanahuel J',N'Gustavo Arce'),
        (N'CE 17',N'CED Zona II',N'Fernandez Bruno',N'Gustavo Arce'),
        (N'CE 18',N'CED Zona II',N'Millanahuel J',N'Gustavo Arce'),
        (N'CE 19',N'CED Zona II',N'Reyna J',N'Gustavo Arce'),
        (N'CE 20',N'CED Zona II',N'Soria Gabriel',N'Gustavo Arce'),
        (N'CE 21',N'CED Zona II',N'Reyna J',N'Gustavo Arce'),
        (N'CE 22',N'CED Zona II',N'Fernandez Bruno',N'Gustavo Arce'),
        (N'CE 23',N'CED Zona II',N'Reyna J',N'Gustavo Arce'),
        (N'CE 25',N'CED Zona II',N'Soria Gabriel',N'Gustavo Arce'),
        (N'PIA CE10',N'CED Zona II',N'Supervisores Plantas',N'Martin Acuña'),
        (N'PIA CE2',N'CED Zona II',N'Supervisores Plantas',N'Martin Acuña'),
        (N'PIA CE20',N'CED Zona II',N'Supervisores Plantas',N'Martin Acuña'),
        (N'PIA CE21',N'CED Zona II',N'Supervisores Plantas',N'Martin Acuña'),
        (N'PIA LH08',N'LH Zona LHCG',N'Supervisores Plantas',N'Martin Acuña'),
        (N'PLANTA LC05',N'ROCH',N'Supervisores Plantas',N'Martin Acuña'),
        (N'CE 01',N'CED Zona I',N'Segura, eduardo',N'Oyarzo, Hector'),
        (N'CE 02',N'CED Zona I',N'Momberg Joan',N'Oyarzo, Hector'),
        (N'CE 03',N'CED Zona I',N'Momberg Joan',N'Oyarzo, Hector'),
        (N'CE 04',N'CED Zona I',N'Quiroga, guillermo',N'Oyarzo, Hector'),
        (N'CE 06',N'CED Zona I',N'Quiroga, guillermo',N'Oyarzo, Hector'),
        (N'CE 07',N'CED Zona I',N'Momberg Joan',N'Oyarzo, Hector'),
        (N'CE 08',N'CED Zona I',N'Quiroga, guillermo',N'Oyarzo, Hector'),
        (N'CE 09',N'CED Zona I',N'Momberg Joan',N'Oyarzo, Hector'),
        (N'CE 26',N'CED Zona I',N'Segura, eduardo',N'Oyarzo, Hector'),
        (N'CE 27',N'CED Zona I',N'Segura, eduardo',N'Oyarzo, Hector'),
        (N'CG 02',N'CED Zona I',N'Perriere Gaston',N'Oyarzo, Hector'),
        (N'CG 03',N'CED Zona I',N'Perriere Gaston',N'Oyarzo, Hector'),
        (N'CG 04',N'CED Zona I',N'Momberg Joan',N'Oyarzo, Hector'),
        (N'CG 17',N'CED Zona I',N'Perriere Gaston',N'Oyarzo, Hector'),
        (N'CG 18',N'CED Zona I',N'Perriere Gaston',N'Oyarzo, Hector'),
        (N'CG 01',N'LH Zona LHCG',N'Zuñiga Ricardo',N'Taboada, Christian'),
        (N'CG05',N'LH Zona LHCG',N'Barros Claudio',N'Taboada, Christian'),
        (N'CG 05 NUEVA',N'LH Zona LHCG',N'Barros Claudio',N'Taboada, Christian'),
        (N'CG 06',N'LH Zona LHCG',N'Michunovich Alejo',N'Taboada, Christian'),
        (N'CG 08',N'LH Zona LHCG',N'Michunovich Alejo',N'Taboada, Christian'),
        (N'CG 10',N'LH Zona LHCG',N'Michunovich Alejo',N'Taboada, Christian'),
        (N'CG 11',N'LH Zona LHCG',N'Barros Claudio',N'Taboada, Christian'),
        (N'CG 12',N'LH Zona LHCG',N'Moreno Javier',N'Taboada, Christian'),
        (N'CG 19',N'LH Zona LHCG',N'Zuñiga Ricardo',N'Taboada, Christian'),
        (N'CG 20',N'LH Zona LHCG',N'Vera, Enzo',N'Taboada, Christian'),
        (N'CG 22',N'LH Zona LHCG',N'Moreno Javier',N'Taboada, Christian'),
        (N'CG 23',N'LH Zona LHCG',N'Cristian Martinez',N'Taboada, Christian'),
        (N'LH 01',N'LH Zona LHCG',N'Vera, Enzo',N'Taboada, Christian'),
        (N'LH 02',N'LH Zona LHCG',N'Vera, Enzo',N'Taboada, Christian'),
        (N'LH 04',N'LH Zona LHCG',N'Orellana Ramiro',N'Taboada, Christian'),
        (N'LH 08',N'LH Zona LHCG',N'Alessandrini Eliana',N'Taboada, Christian'),
        (N'LH 14',N'LH Zona LHCG',N'Alessandrini Eliana',N'Taboada, Christian'),
        (N'LH 15',N'LH Zona LHCG',N'Muñoz, Hector',N'Taboada, Christian'),
        (N'LH 23',N'LH Zona LHCG',N'Muñoz, Hector',N'Taboada, Christian'),
        (N'LH 24',N'LH Zona LHCG',N'Geraldine Lafeuillade',N'Taboada, Christian'),
        (N'LH 25',N'LH Zona LHCG',N'Geraldine Lafeuillade',N'Taboada, Christian'),
        (N'LH 26',N'LH Zona LHCG',N'Cristian Martinez',N'Taboada, Christian'),
        (N'RCG07',N'LH Zona LHCG',N'Cristian Martinez',N'Taboada, Christian')
) V(BATERIA,ZONA,SUPERVISOR,JEFE_ZONA)
WHERE NOT EXISTS
(
    SELECT 1
    FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES S
    WHERE S.BATERIA_CLAVE=
        UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
            LTRIM(RTRIM(CONVERT(nvarchar(255),V.BATERIA))),
            N' ',N''),N'-',N''),N'_',N''),N'.',N''),N'/',N''))
);
GO

/*
   Catálogo desacoplado para que el ABM no ejecute DISTINCT sobre BM/PCP/BES/
   TECCS en cada apertura. Se actualiza bajo demanda desde Administración.
*/
IF OBJECT_ID(N'dbo.CLEAR_BATERIAS_CATALOGO',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_BATERIAS_CATALOGO
    (
        ID                  bigint IDENTITY(1,1) NOT NULL,
        BATERIA             nvarchar(255) NOT NULL,
        BATERIA_CLAVE AS
            UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA))),
                N' ',N''),N'-',N''),N'_',N''),N'.',N''),N'/',N'')) PERSISTED,
        ORIGEN              nvarchar(100) NOT NULL,
        FECHA_ACTUALIZACION datetime2(0) NOT NULL CONSTRAINT DF_CLEAR_BAT_CAT_FECHA DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_CLEAR_BATERIAS_CATALOGO PRIMARY KEY CLUSTERED (ID)
    );
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE object_id=OBJECT_ID(N'dbo.CLEAR_BATERIAS_CATALOGO')
      AND name=N'IX_CLEAR_BATERIAS_CATALOGO_CLAVE'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_CLEAR_BATERIAS_CATALOGO_CLAVE
        ON dbo.CLEAR_BATERIAS_CATALOGO (BATERIA_CLAVE)
        INCLUDE (BATERIA,ORIGEN,FECHA_ACTUALIZACION);
END;
GO

IF OBJECT_ID(N'dbo.SP_CLEAR_ACTUALIZAR_BATERIAS_CATALOGO',N'P') IS NULL
    EXEC(N'CREATE PROCEDURE dbo.SP_CLEAR_ACTUALIZAR_BATERIAS_CATALOGO AS RETURN 0;');
GO

ALTER PROCEDURE dbo.SP_CLEAR_ACTUALIZAR_BATERIAS_CATALOGO
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    CREATE TABLE #BATERIAS
    (
        BATERIA nvarchar(255) NOT NULL,
        ORIGEN  nvarchar(100) NOT NULL
    );

    /* Maestro de instalaciones y batería de pozos. */
    IF OBJECT_ID(N'[CLEAR].[ZONAS]') IS NOT NULL
       AND COL_LENGTH(N'CLEAR.ZONAS',N'BATERIA') IS NOT NULL
        EXEC(N'INSERT INTO #BATERIAS(BATERIA,ORIGEN)
               SELECT DISTINCT LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA))),N''CLEAR.ZONAS.BATERIA''
               FROM [CLEAR].[ZONAS]
               WHERE BATERIA IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA)))<>N'''';');

    IF OBJECT_ID(N'[CLEAR].[ZONAS]') IS NOT NULL
       AND COL_LENGTH(N'CLEAR.ZONAS',N'BATERIA_POZOS') IS NOT NULL
        EXEC(N'INSERT INTO #BATERIAS(BATERIA,ORIGEN)
               SELECT DISTINCT LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA_POZOS))),N''CLEAR.ZONAS.BATERIA_POZOS''
               FROM [CLEAR].[ZONAS]
               WHERE BATERIA_POZOS IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA_POZOS)))<>N'''';');

    /* Cachés y tablas locales de telemetría de pozos. */
    IF OBJECT_ID(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE') IS NOT NULL
       AND COL_LENGTH(N'dbo.TELEMETRIA_POZOS_GENERAL_CACHE',N'BATERIA') IS NOT NULL
        EXEC(N'INSERT INTO #BATERIAS(BATERIA,ORIGEN)
               SELECT DISTINCT LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA))),N''TELEMETRIA_POZOS_GENERAL_CACHE''
               FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE
               WHERE BATERIA IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA)))<>N'''';');

    IF OBJECT_ID(N'dbo.POZOS_POR_BATERIA_CACHE') IS NOT NULL
       AND COL_LENGTH(N'dbo.POZOS_POR_BATERIA_CACHE',N'BATERIA') IS NOT NULL
        EXEC(N'INSERT INTO #BATERIAS(BATERIA,ORIGEN)
               SELECT DISTINCT LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA))),N''POZOS_POR_BATERIA_CACHE''
               FROM dbo.POZOS_POR_BATERIA_CACHE
               WHERE BATERIA IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA)))<>N'''';');

    IF OBJECT_ID(N'dbo.BM_RTQP') IS NOT NULL AND COL_LENGTH(N'dbo.BM_RTQP',N'BATERIA') IS NOT NULL
        EXEC(N'INSERT INTO #BATERIAS(BATERIA,ORIGEN) SELECT DISTINCT LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA))),N''BM_RTQP'' FROM dbo.BM_RTQP WHERE BATERIA IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA)))<>N'''';');

    IF OBJECT_ID(N'dbo.PCP_RTQP') IS NOT NULL AND COL_LENGTH(N'dbo.PCP_RTQP',N'BATERIA') IS NOT NULL
        EXEC(N'INSERT INTO #BATERIAS(BATERIA,ORIGEN) SELECT DISTINCT LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA))),N''PCP_RTQP'' FROM dbo.PCP_RTQP WHERE BATERIA IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA)))<>N'''';');

    IF OBJECT_ID(N'dbo.BES_RTQP') IS NOT NULL AND COL_LENGTH(N'dbo.BES_RTQP',N'BATERIA') IS NOT NULL
        EXEC(N'INSERT INTO #BATERIAS(BATERIA,ORIGEN) SELECT DISTINCT LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA))),N''BES_RTQP'' FROM dbo.BES_RTQP WHERE BATERIA IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA)))<>N'''';');

    IF OBJECT_ID(N'dbo.TECSS_RTQP') IS NOT NULL AND COL_LENGTH(N'dbo.TECSS_RTQP',N'BATERIA') IS NOT NULL
        EXEC(N'INSERT INTO #BATERIAS(BATERIA,ORIGEN) SELECT DISTINCT LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA))),N''TECSS_RTQP'' FROM dbo.TECSS_RTQP WHERE BATERIA IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(255),BATERIA)))<>N'''';');

    IF OBJECT_ID(N'dbo.CLEAR_PARO_REMOTO') IS NOT NULL
       AND COL_LENGTH(N'dbo.CLEAR_PARO_REMOTO',N'SQL-BATERIA') IS NOT NULL
        EXEC(N'INSERT INTO #BATERIAS(BATERIA,ORIGEN)
               SELECT DISTINCT LTRIM(RTRIM(CONVERT(nvarchar(255),[SQL-BATERIA]))),N''CLEAR_PARO_REMOTO''
               FROM dbo.CLEAR_PARO_REMOTO
               WHERE [SQL-BATERIA] IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(255),[SQL-BATERIA])))<>N'''';');

    /* Mantiene también baterías administrativas todavía no presentes en telemetría. */
    INSERT INTO #BATERIAS(BATERIA,ORIGEN)
    SELECT BATERIA,N'CLEAR_SUPERVISORES_INSTALACIONES'
    FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES
    WHERE BATERIA IS NOT NULL AND LTRIM(RTRIM(BATERIA))<>N'';

    BEGIN TRANSACTION;
        DELETE FROM dbo.CLEAR_BATERIAS_CATALOGO;

        INSERT INTO dbo.CLEAR_BATERIAS_CATALOGO(BATERIA,ORIGEN,FECHA_ACTUALIZACION)
        SELECT BATERIA,ORIGEN,SYSDATETIME()
        FROM #BATERIAS
        GROUP BY BATERIA,ORIGEN;
    COMMIT TRANSACTION;

    SELECT COUNT_BIG(*) AS REGISTROS_CATALOGO,
           COUNT(DISTINCT BATERIA_CLAVE) AS BATERIAS_UNICAS,
           MAX(FECHA_ACTUALIZACION) AS FECHA_ACTUALIZACION
    FROM dbo.CLEAR_BATERIAS_CATALOGO;
END;
GO

IF OBJECT_ID(N'dbo.CLEAR_VW_SUPERVISORES_INSTALACIONES',N'V') IS NULL
    EXEC(N'CREATE VIEW dbo.CLEAR_VW_SUPERVISORES_INSTALACIONES AS SELECT 1 AS placeholder;');
GO

ALTER VIEW dbo.CLEAR_VW_SUPERVISORES_INSTALACIONES
AS
SELECT
    S.ID,
    S.BATERIA,
    S.BATERIA_CLAVE,
    S.ZONA,
    S.SUPERVISOR,
    S.JEFE_ZONA,
    S.ACTIVO,
    S.FECHA_ALTA,
    S.USUARIO_ALTA,
    S.FECHA_MODIFICACION,
    S.USUARIO_MODIFICACION,
    CONVERT(bit,CASE WHEN EXISTS
    (
        SELECT 1
        FROM dbo.CLEAR_BATERIAS_CATALOGO C
        WHERE C.BATERIA_CLAVE=S.BATERIA_CLAVE
          AND C.ORIGEN<>N'CLEAR_SUPERVISORES_INSTALACIONES'
    ) THEN 1 ELSE 0 END) AS EN_CATALOGO,
    STUFF
    (
        (
            SELECT DISTINCT N', '+C2.ORIGEN
            FROM dbo.CLEAR_BATERIAS_CATALOGO C2
            WHERE C2.BATERIA_CLAVE=S.BATERIA_CLAVE
              AND C2.ORIGEN<>N'CLEAR_SUPERVISORES_INSTALACIONES'
            FOR XML PATH(N''),TYPE
        ).value(N'.',N'nvarchar(max)'),1,2,N''
    ) AS ORIGENES
FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES S;
GO

/* Permisos mínimos para el usuario web configurado en CLEAR. */
IF DATABASE_PRINCIPAL_ID(N'fix') IS NOT NULL
BEGIN
    GRANT SELECT,INSERT,UPDATE,DELETE ON dbo.CLEAR_SUPERVISORES_INSTALACIONES TO [fix];
    GRANT SELECT ON dbo.CLEAR_BATERIAS_CATALOGO TO [fix];
    GRANT SELECT ON dbo.CLEAR_VW_SUPERVISORES_INSTALACIONES TO [fix];
    GRANT EXECUTE ON dbo.SP_CLEAR_ACTUALIZAR_BATERIAS_CATALOGO TO [fix];
END;
GO

EXEC dbo.SP_CLEAR_ACTUALIZAR_BATERIAS_CATALOGO;
GO

SELECT TOP (100)
    BATERIA,ZONA,SUPERVISOR,JEFE_ZONA,EN_CATALOGO,ORIGENES
FROM dbo.CLEAR_VW_SUPERVISORES_INSTALACIONES
ORDER BY ZONA,BATERIA;
GO
