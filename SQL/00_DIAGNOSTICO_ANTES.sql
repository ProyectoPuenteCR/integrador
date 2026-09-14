USE [LC_MDB];
GO
SET NOCOUNT ON;

PRINT '=== CLEAR - DIAGNOSTICO ANTES ===';
SELECT GETDATE() AS FechaDiagnostico, @@SERVERNAME AS Servidor, DB_NAME() AS BaseDatos, @@VERSION AS VersionSQL;

PRINT '=== TAMANO Y FILAS ===';
SELECT
    s.name AS Esquema,
    o.name AS Objeto,
    o.type_desc AS Tipo,
    SUM(CASE WHEN p.index_id IN (0,1) THEN p.rows ELSE 0 END) AS FilasAproximadas,
    CAST(SUM(a.total_pages) * 8.0 / 1024 AS decimal(18,2)) AS MB_Reservados,
    CAST(SUM(a.used_pages) * 8.0 / 1024 AS decimal(18,2)) AS MB_Usados
FROM sys.objects o
JOIN sys.schemas s ON s.schema_id=o.schema_id
LEFT JOIN sys.indexes i ON i.object_id=o.object_id
LEFT JOIN sys.partitions p ON p.object_id=i.object_id AND p.index_id=i.index_id
LEFT JOIN sys.allocation_units a ON a.container_id=p.partition_id
WHERE s.name='dbo' AND o.name IN ('FIXALARMS','BM_RTQP')
GROUP BY s.name,o.name,o.type_desc
ORDER BY o.name;

PRINT '=== INDICES EXISTENTES ===';
SELECT
    OBJECT_SCHEMA_NAME(i.object_id) AS Esquema,
    OBJECT_NAME(i.object_id) AS Tabla,
    i.name AS Indice,
    i.type_desc,
    i.is_unique,
    i.is_disabled,
    STUFF((
        SELECT ', ' + QUOTENAME(c.name) + CASE WHEN ic.is_descending_key=1 THEN ' DESC' ELSE ' ASC' END
        FROM sys.index_columns ic
        JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
        WHERE ic.object_id=i.object_id AND ic.index_id=i.index_id AND ic.key_ordinal>0
        ORDER BY ic.key_ordinal
        FOR XML PATH(''),TYPE).value('.','nvarchar(max)'),1,2,'') AS ColumnasClave,
    STUFF((
        SELECT ', ' + QUOTENAME(c.name)
        FROM sys.index_columns ic
        JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
        WHERE ic.object_id=i.object_id AND ic.index_id=i.index_id AND ic.is_included_column=1
        ORDER BY ic.index_column_id
        FOR XML PATH(''),TYPE).value('.','nvarchar(max)'),1,2,'') AS ColumnasIncluidas
FROM sys.indexes i
WHERE i.object_id IN (OBJECT_ID('dbo.FIXALARMS'),OBJECT_ID('dbo.BM_RTQP'))
  AND i.index_id>0
ORDER BY Tabla,Indice;

PRINT '=== USO DE INDICES DESDE EL ULTIMO REINICIO ===';
SELECT
    OBJECT_NAME(i.object_id) AS Tabla,
    i.name AS Indice,
    COALESCE(u.user_seeks,0) AS Busquedas,
    COALESCE(u.user_scans,0) AS Escaneos,
    COALESCE(u.user_lookups,0) AS Lookups,
    COALESCE(u.user_updates,0) AS Actualizaciones,
    u.last_user_seek,
    u.last_user_scan
FROM sys.indexes i
LEFT JOIN sys.dm_db_index_usage_stats u
  ON u.database_id=DB_ID() AND u.object_id=i.object_id AND u.index_id=i.index_id
WHERE i.object_id IN (OBJECT_ID('dbo.FIXALARMS'),OBJECT_ID('dbo.BM_RTQP'))
ORDER BY Tabla,Indice;

PRINT '=== FRAGMENTACION (OBJETOS MAYORES A 1000 PAGINAS) ===';
SELECT
    OBJECT_NAME(ps.object_id) AS Tabla,
    i.name AS Indice,
    ps.page_count,
    CAST(ps.avg_fragmentation_in_percent AS decimal(10,2)) AS FragmentacionPct
FROM sys.dm_db_index_physical_stats(DB_ID(),NULL,NULL,NULL,'LIMITED') ps
JOIN sys.indexes i ON i.object_id=ps.object_id AND i.index_id=ps.index_id
WHERE ps.object_id IN (OBJECT_ID('dbo.FIXALARMS'),OBJECT_ID('dbo.BM_RTQP'))
  AND ps.page_count>=1000
ORDER BY FragmentacionPct DESC;

PRINT '=== CONSULTAS RECIENTES RELACIONADAS (si permanecen en cache) ===';
SELECT TOP (30)
    qs.execution_count,
    CAST(qs.total_elapsed_time/1000.0 AS decimal(18,2)) AS TiempoTotalMs,
    CAST((qs.total_elapsed_time/NULLIF(qs.execution_count,0))/1000.0 AS decimal(18,2)) AS TiempoPromedioMs,
    qs.total_logical_reads,
    CAST(qs.total_logical_reads/NULLIF(qs.execution_count,0) AS decimal(18,2)) AS LecturasPromedio,
    qs.last_execution_time,
    LEFT(REPLACE(REPLACE(st.text,CHAR(13),' '),CHAR(10),' '),1500) AS Consulta
FROM sys.dm_exec_query_stats qs
CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) st
WHERE st.dbid=DB_ID()
  AND (st.text LIKE '%FIXALARMS%' OR st.text LIKE '%BM_RTQP%')
ORDER BY qs.total_elapsed_time DESC;
