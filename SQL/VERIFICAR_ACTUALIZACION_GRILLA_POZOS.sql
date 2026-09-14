USE [LC_MDB];
GO

/*
  Verificación rápida de la caché de la Grilla general de pozos.
  No modifica dbo.FIXALARMS ni las tablas RTQP.
*/

SELECT
    MAX(FECHA_CACHE) AS ULTIMA_ACTUALIZACION_CACHE,
    DATEDIFF(MINUTE, MAX(FECHA_CACHE), SYSDATETIME()) AS MINUTOS_DESDE_ULTIMA_CARGA,
    COUNT_BIG(*) AS REGISTROS_CACHE
FROM dbo.TELEMETRIA_POZOS_GENERAL_CACHE;
GO

/*
  Si MINUTOS_DESDE_ULTIMA_CARGA es mayor a 20, revisar SQL Server Agent
  y el Job: CLEAR - Actualizar grilla general de pozos.

  Para probar manualmente la actualización, ejecutar:

  EXEC dbo.SP_ACTUALIZAR_TELEMETRIA_POZOS_GENERAL_CACHE;
*/
