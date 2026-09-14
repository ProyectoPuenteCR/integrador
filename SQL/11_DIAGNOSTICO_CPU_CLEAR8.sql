USE [LC_MDB];
GO
SET NOCOUNT ON;

/* Diagnóstico de solo lectura. Ejecutar durante un pico de CPU. */
SELECT GETDATE() Fecha,@@SERVERNAME Servidor,DB_NAME() BaseDatos;

SELECT
  r.session_id,r.status,r.cpu_time,r.total_elapsed_time,r.logical_reads,r.reads,r.writes,
  r.wait_type,r.blocking_session_id,
  SUBSTRING(t.text,(r.statement_start_offset/2)+1,
    ((CASE r.statement_end_offset WHEN -1 THEN DATALENGTH(t.text) ELSE r.statement_end_offset END-r.statement_start_offset)/2)+1) ConsultaActiva
FROM sys.dm_exec_requests r
CROSS APPLY sys.dm_exec_sql_text(r.sql_handle) t
WHERE r.database_id=DB_ID() AND r.session_id<>@@SPID
ORDER BY r.cpu_time DESC,r.logical_reads DESC;

SELECT TOP(30)
  qs.execution_count,
  CAST(qs.total_worker_time/1000.0 AS decimal(18,2)) CPU_Total_ms,
  CAST((qs.total_worker_time/NULLIF(qs.execution_count,0))/1000.0 AS decimal(18,2)) CPU_Promedio_ms,
  qs.total_logical_reads,
  CAST(qs.total_logical_reads/NULLIF(qs.execution_count,0) AS decimal(18,2)) Lecturas_Promedio,
  qs.last_execution_time,
  LEFT(REPLACE(REPLACE(st.text,CHAR(13),N' '),CHAR(10),N' '),2000) Consulta
FROM sys.dm_exec_query_stats qs
CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) st
WHERE st.dbid=DB_ID() AND (st.text LIKE N'%FIXALARMS%' OR st.text LIKE N'%CLEAR_CACHE%')
ORDER BY qs.total_worker_time DESC;

SELECT
  OBJECT_NAME(i.object_id) Tabla,i.name Indice,
  COALESCE(u.user_seeks,0) Seeks,COALESCE(u.user_scans,0) Scans,
  COALESCE(u.user_lookups,0) Lookups,COALESCE(u.user_updates,0) Actualizaciones,
  u.last_user_seek,u.last_user_scan
FROM sys.indexes i
LEFT JOIN sys.dm_db_index_usage_stats u
  ON u.database_id=DB_ID() AND u.object_id=i.object_id AND u.index_id=i.index_id
WHERE i.object_id IN (OBJECT_ID(N'dbo.FIXALARMS'),OBJECT_ID(N'dbo.CLEAR_CACHE_OPERATIVA'))
ORDER BY Tabla,Indice;
GO

