USE [LC_MDB];
GO
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO
/* Instalación opcional del histórico de la nueva pantalla.
   No carga RTQP, no ejecuta ni modifica Jobs y no toca históricos existentes.
   Una fila por pozo/semana. Las capturas se guardan explícitamente desde web.
*/
IF OBJECT_ID(N'dbo.CLEAR_PUMPOFF_SEMANAL',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.CLEAR_PUMPOFF_SEMANAL (
        SEMANA_DESDE date NOT NULL,
        POZO nvarchar(180) NOT NULL,
        ZONA nvarchar(150) NOT NULL,
        SUPERVISOR nvarchar(200) NOT NULL,
        JEFE_PRODUCCION nvarchar(200) NOT NULL,
        BATERIA nvarchar(255) NOT NULL,
        TAG nvarchar(255) NOT NULL,
        ESTADO varchar(20) NOT NULL,
        FECHA_FUENTE nvarchar(4000) NOT NULL,
        CAPTURADO_EN datetime2(0) NOT NULL,
        USUARIO nvarchar(128) NOT NULL,
        CONSTRAINT PK_CLEAR_PUMPOFF_SEMANAL PRIMARY KEY CLUSTERED(SEMANA_DESDE,POZO),
        CONSTRAINT CK_CLEAR_PUMPOFF_ESTADO CHECK(ESTADO IN ('MANUAL','AUTOMATICO','HOA','SIN CLASIFICAR')),
        CONSTRAINT CK_CLEAR_PUMPOFF_MIERCOLES CHECK(DATEDIFF(day,CONVERT(date,'19000103',112),SEMANA_DESDE)%7=0)
    );
END;
GO
SELECT N'Histórico disponible. Todavía no se cargaron lecturas desde este script.' AS Resultado;
GO
