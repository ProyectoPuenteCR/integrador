<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/performance_cache.php
   Lectura centralizada de la caché operativa creada por
   SQL/10_OPTIMIZACION_GLOBAL_CLEAR8.sql.

   Si la caché todavía no fue instalada, devuelve null y las
   pantallas conservan automáticamente sus consultas anteriores.
============================================================= */

function clear_performance_cache_load($db = null)
{
    static $loaded = false;
    static $metrics = null;

    if ($loaded) return $metrics;
    $loaded = true;

    if ($db === null) $db = clear_db();
    if (!$db || !$db->ok()) return null;

    $exists = $db->scalar(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_CACHE_OPERATIVA', N'U') IS NULL THEN 0 ELSE 1 END"
    );
    if ((int)$exists !== 1) return null;

    $rows = $db->all(
        "SELECT METRICA, ORDEN, DIMENSION1, DIMENSION2, VALOR1, VALOR2, VALOR3, TEXTO1, FECHA_CACHE " .
        "FROM dbo.CLEAR_CACHE_OPERATIVA ORDER BY METRICA, ORDEN, DIMENSION1"
    );
    if (!$rows) return null;

    $metrics = [];
    foreach ($rows as $row) {
        $name = strtoupper(trim((string)($row['METRICA'] ?? '')));
        if ($name === '') continue;
        if (!isset($metrics[$name])) $metrics[$name] = [];
        $metrics[$name][] = $row;
    }
    return $metrics;
}

function clear_performance_cache_metric($name, $db = null)
{
    $metrics = clear_performance_cache_load($db);
    if ($metrics === null) return null;
    $key = strtoupper(trim((string)$name));
    return $metrics[$key] ?? [];
}

function clear_performance_cache_kpi($name, $default = null, $db = null)
{
    $rows = clear_performance_cache_metric('KPI', $db);
    if ($rows === null) return $default;
    $key = strtoupper(trim((string)$name));
    foreach ($rows as $row) {
        if (strtoupper(trim((string)($row['DIMENSION1'] ?? ''))) === $key) {
            return $row['VALOR1'] ?? $default;
        }
    }
    return $default;
}

function clear_performance_cache_text($name, $default = null, $db = null)
{
    $rows = clear_performance_cache_metric('KPI', $db);
    if ($rows === null) return $default;
    $key = strtoupper(trim((string)$name));
    foreach ($rows as $row) {
        if (strtoupper(trim((string)($row['DIMENSION1'] ?? ''))) === $key) {
            $value = trim((string)($row['TEXTO1'] ?? ''));
            return $value !== '' ? $value : $default;
        }
    }
    return $default;
}

