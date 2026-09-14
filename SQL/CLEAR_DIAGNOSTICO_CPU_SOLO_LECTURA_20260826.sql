USE [LC_MDB];
GO

/*
  DIAGNOSTICO SEGURO Y DE SOLO LECTURA.
  No crea índices, no borra datos, no limpia caché y no finaliza sesiones.
*/
SET NOCOUNT ON;

SELECT TOP (20)
    R.session_id AS SPID,
    R.status,
    R.cpu_time AS CPU_MS,
    R.total_elapsed_time AS DURACION_MS,
    R.logical_reads AS LECTURAS_LOGICAS,
    R.reads AS LECTURAS,
    R.writes AS ESCRITURAS,
    R.wait_type,
    R.blocking_session_id AS BLOQUEADO_POR,
    DB_NAME(R.database_id) AS BASE,
    S.host_name AS EQUIPO,
    S.program_name AS PROGRAMA,
    S.login_name AS LOGIN_SQL,
    SUBSTRING(T.text,
              (R.statement_start_offset/2)+1,
              ((CASE R.statement_end_offset WHEN -1 THEN DATALENGTH(T.text)
                 ELSE R.statement_end_offset END-R.statement_start_offset)/2)+1) AS SENTENCIA_ACTIVA
FROM sys.dm_exec_requests AS R
JOIN sys.dm_exec_sessions AS S ON S.session_id=R.session_id
CROSS APPLY sys.dm_exec_sql_text(R.sql_handle) AS T
WHERE R.session_id<>@@SPID
ORDER BY R.cpu_time DESC, R.logical_reads DESC;
GO

SELECT TOP (25)
    QS.execution_count AS EJECUCIONES,
    CONVERT(decimal(18,2),QS.total_worker_time/1000.0) AS CPU_TOTAL_MS,
    CONVERT(decimal(18,2),(QS.total_worker_time/NULLIF(QS.execution_count,0))/1000.0) AS CPU_PROMEDIO_MS,
    QS.total_logical_reads AS LECTURAS_LOGICAS_TOTAL,
    QS.last_execution_time AS ULTIMA_EJECUCION,
    DB_NAME(T.dbid) AS BASE,
    SUBSTRING(T.text,
              (QS.statement_start_offset/2)+1,
              ((CASE QS.statement_end_offset WHEN -1 THEN DATALENGTH(T.text)
                 ELSE QS.statement_end_offset END-QS.statement_start_offset)/2)+1) AS SENTENCIA
FROM sys.dm_exec_query_stats AS QS
CROSS APPLY sys.dm_exec_sql_text(QS.sql_handle) AS T
WHERE T.text LIKE N'%FIXALARMS%'
   OR T.text LIKE N'%TELEMETRIA_POZOS_GENERAL_CACHE%'
   OR T.text LIKE N'%CLEAR_REPORT%'
   OR T.text LIKE N'%CLEAR_USER_%'
ORDER BY QS.total_worker_time DESC;
GO

SELECT
    physical_memory_in_use_kb/1024 AS SQL_MEMORIA_FISICA_MB,
    large_page_allocations_kb/1024 AS PAGINAS_GRANDES_MB,
    locked_page_allocations_kb/1024 AS PAGINAS_BLOQUEADAS_MB,
    memory_utilization_percentage AS MEMORIA_UTILIZADA_PCT,
    process_physical_memory_low AS MEMORIA_FISICA_BAJA,
    process_virtual_memory_low AS MEMORIA_VIRTUAL_BAJA
FROM sys.dm_os_process_memory;

SELECT name,value_in_use
FROM sys.configurations
WHERE name IN (N'max server memory (MB)',N'min server memory (MB)',N'max degree of parallelism',N'cost threshold for parallelism')
ORDER BY name;
GO
