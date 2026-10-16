<?php
/* CLEAR: almacenamiento de solo lectura. PHP 7.4 / SQL Server 2016+.
 * Nunca consulta filas de las tablas de negocio ni modifica objetos SQL.
 * Las consultas se ejecutan en el contexto de la base validada.
 */
function sqlstorage_query($db, $database, $sql)
{
    $ident = '[' . str_replace(']', ']]', $database) . ']';
    // Un solo recordset; compatible con ADODB/COM, sqlsrv y PDO.
    // Los SET quedan limitados al lote sp_executesql y se restauran al salir.
    $body = "SET NOCOUNT ON;
SET LOCK_TIMEOUT 1500;
BEGIN TRY
    EXEC sys.sp_executesql N'" . str_replace("'", "''", $sql) . "';
END TRY
BEGIN CATCH
    SELECT ERROR_NUMBER() AS STORAGE_ERROR;
END CATCH;";
    return $db->all("EXEC " . $ident . ".sys.sp_executesql N'" . str_replace("'", "''", $body) . "';");
}

function sqlstorage_number($value)
{
    return $value === null || $value === '' || !is_numeric($value) ? null : (float)$value;
}

function sqlstorage_failed($rows)
{
    return !$rows || array_key_exists('STORAGE_ERROR', $rows[0]);
}

function sqlstorage_collect($db, array $cfg, $requested)
{
    $started = microtime(true);
    if (!$db->ok()) return ['ok'=>false, 'error'=>'Sin conexión SQL. No se pudo medir el almacenamiento.'];
    $list = $db->all("SELECT name AS DB_NAME FROM sys.databases
        WHERE state=0 AND source_database_id IS NULL AND HAS_DBACCESS(name)=1
          AND (database_id>4 OR database_id=DB_ID()) ORDER BY name;");
    $names = array_values(array_map(static function ($r) { return (string)$r['DB_NAME']; }, $list));
    if (!in_array($requested, $names, true)) {
        return ['ok'=>false, 'error'=>'La base solicitada no está disponible o no es visible para el usuario SQL.'];
    }

    $r = ['ok'=>true, 'database'=>$requested, 'databases'=>$names, 'warnings'=>[],
          'captured_at'=>date('c'), 'meta'=>null];
    $meta = sqlstorage_query($db, $requested, "SELECT
        CONVERT(varchar(19),GETDATE(),120) AS SERVER_TIME,
        recovery_model_desc AS RECOVERY, log_reuse_wait_desc AS REUSE_WAIT
        FROM sys.databases WHERE database_id=DB_ID();");
    if (!sqlstorage_failed($meta)) $r['meta']=$meta[0];

    $files = sqlstorage_query($db, $requested, "SELECT
        file_id AS FILE_ID, name AS FILE_NAME, type AS FILE_TYPE, type_desc AS FILE_TYPE_DESC,
        state_desc AS FILE_STATE, CONVERT(float,size)/128.0 AS SIZE_MB,
        CASE WHEN type=0 THEN CONVERT(float,FILEPROPERTY(name,'SpaceUsed'))/128.0 END AS USED_MB,
        growth AS GROWTH_PAGES, is_percent_growth AS GROWTH_PERCENT, max_size AS MAX_PAGES
        FROM sys.database_files ORDER BY file_id;");
    $r['files']=['ok'=>!sqlstorage_failed($files), 'rows'=>[], 'data_mb'=>null,
        'data_used_mb'=>null, 'data_free_mb'=>null, 'log_file_mb'=>null,
        'error'=>'No se pudieron consultar los archivos de la base. Revisá acceso, conexión o bloqueos.'];
    if ($r['files']['ok']) {
        $data=0.0; $used=0.0; $log=0.0; $hasData=false; $hasLog=false; $complete=true;
        foreach ($files as $f) {
            foreach (['SIZE_MB','USED_MB','GROWTH_PAGES','GROWTH_PERCENT','MAX_PAGES','FILE_TYPE'] as $key) {
                $f[$key]=sqlstorage_number($f[$key]??null);
            }
            $r['files']['rows'][]=$f;
            if ($f['FILE_TYPE']===0.0) {
                $hasData=true; $data+=$f['SIZE_MB']??0;
                if ($f['USED_MB']===null || $f['FILE_STATE']!=='ONLINE') $complete=false;
                else $used+=$f['USED_MB'];
            } elseif ($f['FILE_TYPE']===1.0) {
                $hasLog=true; $log+=$f['SIZE_MB']??0;
            } else {
                $r['warnings'][]='Hay archivos especiales (por ejemplo FILESTREAM). No se incluyen en los totales de datos/log ni en el tamaño por tabla.';
            }
        }
        $r['files']['data_mb']=$hasData?$data:null;
        $r['files']['data_used_mb']=$hasData&&$complete?$used:null;
        $r['files']['data_free_mb']=$hasData&&$complete?max(0,$data-$used):null;
        $r['files']['log_file_mb']=$hasLog?$log:null;
    }

    $tables = sqlstorage_query($db, $requested, ";WITH P AS (
        SELECT object_id,
            SUM(CASE WHEN index_id IN (0,1) THEN row_count ELSE CONVERT(bigint,0) END) AS ROWS_APPROX,
            SUM(reserved_page_count) AS RESERVED_PAGES, SUM(used_page_count) AS USED_PAGES,
            SUM(CASE WHEN index_id IN (0,1)
                THEN in_row_data_page_count+lob_used_page_count+row_overflow_used_page_count
                ELSE CONVERT(bigint,0) END) AS DATA_PAGES
        FROM sys.dm_db_partition_stats GROUP BY object_id
    )
    SELECT 0 AS SENTINEL, s.name AS SCHEMA_NAME, t.name AS TABLE_NAME,
        t.is_memory_optimized AS IN_MEMORY, P.ROWS_APPROX,
        CONVERT(float,P.RESERVED_PAGES)/128.0 AS RESERVED_MB,
        CONVERT(float,P.USED_PAGES)/128.0 AS USED_MB,
        CONVERT(float,P.DATA_PAGES)/128.0 AS DATA_MB,
        CONVERT(float,P.USED_PAGES-P.DATA_PAGES)/128.0 AS INDEX_MB
    FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id
    LEFT JOIN P ON P.object_id=t.object_id WHERE t.is_ms_shipped=0
    UNION ALL SELECT 1, N'', N'', 0, NULL, NULL, NULL, NULL, NULL
    ORDER BY SENTINEL, RESERVED_MB DESC, SCHEMA_NAME, TABLE_NAME OPTION (MAXDOP 1);");
    // El marcador final permite distinguir una base vacía de una lectura fallida/incompleta.
    $tablesOK=!sqlstorage_failed($tables) && (int)($tables[count($tables)-1]['SENTINEL']??0)===1;
    $r['tables']=['ok'=>$tablesOK, 'rows'=>[],
        'error'=>'No se pudo medir las tablas. El DBA debe revisar permisos de metadatos/DMV o bloqueos. Consultá la ayuda de esta sección.'];
    if ($tablesOK) {
        foreach ($tables as $t) {
            if ((int)$t['SENTINEL']===1) continue;
            unset($t['SENTINEL']);
            $memory=(int)$t['IN_MEMORY']===1;
            foreach (['ROWS_APPROX','RESERVED_MB','USED_MB','DATA_MB','INDEX_MB'] as $key) {
                $t[$key]=$memory?null:sqlstorage_number($t[$key]??null);
            }
            $t['IN_MEMORY']=$memory;
            $t['UNUSED_MB']=$t['RESERVED_MB']!==null&&$t['USED_MB']!==null
                ?max(0,$t['RESERVED_MB']-$t['USED_MB']):null;
            $r['tables']['rows'][]=$t;
            if ($memory) $r['warnings'][]='Hay tablas en memoria: su tamaño y sus filas se muestran como no disponibles, no como cero.';
        }
    }

    $log=sqlstorage_query($db,$requested,"SELECT
        CONVERT(float,total_log_size_in_bytes)/1048576.0 AS TOTAL_MB,
        CONVERT(float,used_log_space_in_bytes)/1048576.0 AS USED_MB,
        CONVERT(float,used_log_space_in_percent) AS USED_PERCENT
        FROM sys.dm_db_log_space_usage;");
    $r['log']=['ok'=>!sqlstorage_failed($log), 'total_mb'=>null, 'used_mb'=>null,
        'free_mb'=>null, 'percent'=>null,
        'error'=>'Ocupación del log no disponible. Revisá VIEW SERVER STATE (SQL 2016/2019) o VIEW SERVER PERFORMANCE STATE (SQL 2022+), conexión y bloqueos.'];
    if ($r['log']['ok']) {
        $total=sqlstorage_number($log[0]['TOTAL_MB']??null);
        $used=sqlstorage_number($log[0]['USED_MB']??null);
        $r['log']['total_mb']=$total;
        $r['log']['used_mb']=$used;
        $r['log']['free_mb']=$total!==null&&$used!==null?max(0,$total-$used):null;
        $r['log']['percent']=sqlstorage_number($log[0]['USED_PERCENT']??null);
        $r['log']['ok']=$total!==null&&$used!==null&&$r['log']['percent']!==null;
    }
    $r['warnings']=array_values(array_unique($r['warnings']));
    $r['elapsed_ms']=round((microtime(true)-$started)*1000,1);
    return $r;
}
