USE [LC_MDB];
GO

/*
  CLEAR - Analisis de Vibraciones TECSS
  Objetos locales consumidos por la tarea Python y por la pantalla PHP.
  El sitio web solo realiza SELECT sobre estas tablas; nunca ejecuta Python.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_DATO', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_TECSS_VIBRACIONES_DATO
    (
        POZO        nvarchar(255) NOT NULL,
        FECHA       datetime2(3)  NOT NULL,
        VIBR        float         NULL,
        GPM         float         NULL,
        FECHA_CARGA datetime2(0)  NOT NULL
            CONSTRAINT DF_CLEAR_TECSS_VIB_DATO_CARGA DEFAULT SYSDATETIME(),
        CONSTRAINT PK_CLEAR_TECSS_VIB_DATO PRIMARY KEY CLUSTERED (POZO, FECHA)
    );
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_DATO')
      AND name = N'IX_CLEAR_TECSS_VIB_DATO_FECHA'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_CLEAR_TECSS_VIB_DATO_FECHA
        ON dbo.CLEAR_TECSS_VIBRACIONES_DATO (FECHA DESC)
        INCLUDE (POZO, VIBR, GPM)
        WITH (MAXDOP = 1);
END;
GO

IF OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_ESTADO', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_TECSS_VIBRACIONES_ESTADO
    (
        POZO                    nvarchar(255) NOT NULL,
        NOMBRE                  nvarchar(255) NULL,
        BATERIA                 nvarchar(255) NULL,
        ESTADO                  varchar(20)   NOT NULL,
        ESTADO_ETIQUETA         nvarchar(50)  NOT NULL,
        FECHA_ULTIMO_DATO       datetime2(3)  NULL,
        VIBR_ACTUAL             float         NULL,
        GPM_ACTUAL              float         NULL,
        VIBR_MEDIANA            float         NULL,
        VIBR_P25                float         NULL,
        VIBR_P75                float         NULL,
        UMBRAL_ALERTA           float         NULL,
        UMBRAL_CRITICO          float         NULL,
        FRECUENCIA_MEDIANA_MIN  float         NULL,
        N_REGISTROS             int           NOT NULL
            CONSTRAINT DF_CLEAR_TECSS_VIB_EST_REG DEFAULT (0),
        ALERTAS_48H             int           NOT NULL
            CONSTRAINT DF_CLEAR_TECSS_VIB_EST_ALERTAS DEFAULT (0),
        EXCESOS_48H             int           NOT NULL
            CONSTRAINT DF_CLEAR_TECSS_VIB_EST_EXCESOS DEFAULT (0),
        SCORE_SEVERIDAD         float         NOT NULL
            CONSTRAINT DF_CLEAR_TECSS_VIB_EST_SCORE DEFAULT (0),
        EXCESO_PROMEDIO_PCT     float         NOT NULL
            CONSTRAINT DF_CLEAR_TECSS_VIB_EST_PROM DEFAULT (0),
        EXCESO_MAXIMO_PCT       float         NOT NULL
            CONSTRAINT DF_CLEAR_TECSS_VIB_EST_MAX DEFAULT (0),
        ULTIMA_ALERTA           datetime2(3)  NULL,
        ULTIMA_CRITICA          datetime2(3)  NULL,
        HORAS_ULTIMA_ALERTA     float         NULL,
        FECHA_ANALISIS          datetime2(0)  NOT NULL,
        CONSTRAINT PK_CLEAR_TECSS_VIB_ESTADO PRIMARY KEY CLUSTERED (POZO),
        CONSTRAINT CK_CLEAR_TECSS_VIB_ESTADO
            CHECK (ESTADO IN ('normal','frecuente','alerta','critico','parado'))
    );
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_ESTADO')
      AND name = N'IX_CLEAR_TECSS_VIB_ESTADO_PRIORIDAD'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_CLEAR_TECSS_VIB_ESTADO_PRIORIDAD
        ON dbo.CLEAR_TECSS_VIBRACIONES_ESTADO (ESTADO, SCORE_SEVERIDAD DESC)
        INCLUDE (POZO, BATERIA, FECHA_ULTIMO_DATO, VIBR_ACTUAL, UMBRAL_ALERTA, UMBRAL_CRITICO);
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_ESTADO')
      AND name = N'IX_CLEAR_TECSS_VIB_ESTADO_BATERIA'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_CLEAR_TECSS_VIB_ESTADO_BATERIA
        ON dbo.CLEAR_TECSS_VIBRACIONES_ESTADO (BATERIA, POZO)
        INCLUDE (ESTADO, SCORE_SEVERIDAD, FECHA_ANALISIS);
END;
GO

IF OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_EVENTO', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_TECSS_VIBRACIONES_EVENTO
    (
        ID             bigint        IDENTITY(1,1) NOT NULL,
        POZO           nvarchar(255) NOT NULL,
        FECHA_EVENTO   datetime2(3)  NOT NULL,
        NIVEL          varchar(20)   NOT NULL,
        TIPO           nvarchar(80)  NOT NULL,
        VIBR_VALOR     float         NULL,
        VIBR_UMBRAL    float         NULL,
        ES_PICO        bit           NOT NULL
            CONSTRAINT DF_CLEAR_TECSS_VIB_EVENTO_PICO DEFAULT (0),
        N_LECTURAS     int           NULL,
        FECHA_ANALISIS datetime2(0)  NOT NULL,
        CONSTRAINT PK_CLEAR_TECSS_VIB_EVENTO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT UQ_CLEAR_TECSS_VIB_EVENTO UNIQUE (POZO, FECHA_EVENTO, TIPO, ES_PICO)
    );
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_EVENTO')
      AND name = N'IX_CLEAR_TECSS_VIB_EVENTO_POZO_FECHA'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_CLEAR_TECSS_VIB_EVENTO_POZO_FECHA
        ON dbo.CLEAR_TECSS_VIBRACIONES_EVENTO (POZO, FECHA_EVENTO DESC)
        INCLUDE (NIVEL, TIPO, VIBR_VALOR, VIBR_UMBRAL, ES_PICO, N_LECTURAS);
END;
GO

IF OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_EJECUCION', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_TECSS_VIBRACIONES_EJECUCION
    (
        ID                  bigint        IDENTITY(1,1) NOT NULL,
        FECHA_INICIO        datetime2(0)  NOT NULL,
        FECHA_FIN           datetime2(0)  NULL,
        ESTADO              varchar(20)   NOT NULL,
        FILAS_ORIGEN        int           NULL,
        FILAS_CACHE         int           NULL,
        POZOS_ANALIZADOS    int           NULL,
        EVENTOS_GENERADOS   int           NULL,
        MENSAJE             nvarchar(2000) NULL,
        PARAMETROS_JSON     nvarchar(max) NULL,
        CONSTRAINT PK_CLEAR_TECSS_VIB_EJECUCION PRIMARY KEY CLUSTERED (ID)
    );
END;
GO

IF NOT EXISTS
(
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_EJECUCION')
      AND name = N'IX_CLEAR_TECSS_VIB_EJEC_FECHA'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_CLEAR_TECSS_VIB_EJEC_FECHA
        ON dbo.CLEAR_TECSS_VIBRACIONES_EJECUCION (FECHA_INICIO DESC)
        INCLUDE (FECHA_FIN, ESTADO, POZOS_ANALIZADOS, MENSAJE);
END;
GO

PRINT N'Objetos de Analisis de Vibraciones TECSS instalados correctamente.';
GO
