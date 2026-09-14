<?php
/* =============================================================
   Clasificación compartida de tipos de instalación.
   Trabaja sobre columnas de la consulta actual: no agrega JOINs.
============================================================= */

function clear_installation_type_options()
{
    return [
        'POZO' => 'Pozo',
        'BATERÍA' => 'Batería',
        'SATÉLITE' => 'Satélite',
        'GAS' => 'Gas',
        'PIAS' => 'PIAS',
        'ENERGÍA' => 'Energía',
        'PLANTA TRAT.' => 'Planta Trat.',
        'PLANTA LH' => 'Planta LH',
        'SIN CLASIFICAR' => 'Sin clasificar',
    ];
}

function clear_installation_type_sql($tagExpr, $externalExpr = "N''")
{
    $tag = "UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(255),$tagExpr),N''))))";
    $external = "UPPER(LTRIM(RTRIM(COALESCE(CONVERT(nvarchar(500),$externalExpr),N''))))";
    $installation = "LEFT($tag,CHARINDEX(N'_',$tag+N'_')-1)";

    return "CASE " .
        "WHEN $installation = N'PIALH3' THEN N'PIAS' " .
        "WHEN $external LIKE N'YPF.SC%' OR $tag LIKE N'YPF.SC%' THEN N'POZO' " .
        "WHEN $installation LIKE N'EBB%' OR $installation LIKE N'ELH%' THEN N'ENERGÍA' " .
        "WHEN $installation LIKE N'GL%' THEN N'GAS' " .
        "WHEN $installation LIKE N'PLH%' THEN N'PLANTA LH' " .
        "WHEN $installation LIKE N'PT%' THEN N'PLANTA TRAT.' " .
        "WHEN $installation LIKE N'RL%' OR $installation LIKE N'B%' THEN N'BATERÍA' " .
        "WHEN $installation LIKE N'S%' THEN N'SATÉLITE' " .
        "ELSE N'SIN CLASIFICAR' END";
}

function clear_installation_type_normalize($value)
{
    $value = strtoupper(trim((string)$value));
    $aliases = [
        'BATERIA' => 'BATERÍA',
        'SATELITE' => 'SATÉLITE',
        'ENERGIA' => 'ENERGÍA',
        'PLANTA_TRAT' => 'PLANTA TRAT.',
        'PLANTA LH.' => 'PLANTA LH',
        'SIN_CLASIFICAR' => 'SIN CLASIFICAR',
    ];
    if (isset($aliases[$value])) $value = $aliases[$value];
    return array_key_exists($value, clear_installation_type_options()) ? $value : '';
}

function clear_installation_type_class($value)
{
    $map = [
        'POZO' => 'well',
        'BATERÍA' => 'battery',
        'SATÉLITE' => 'satellite',
        'GAS' => 'gas',
        'PIAS' => 'pias',
        'ENERGÍA' => 'energy',
        'PLANTA TRAT.' => 'plant',
        'PLANTA LH' => 'plant-lh',
        'SIN CLASIFICAR' => 'unknown',
    ];
    $value = clear_installation_type_normalize($value);
    return $map[$value] ?? 'unknown';
}

function clear_installation_type_table_parts($table)
{
    $clean = str_replace(['[', ']'], '', trim((string)$table));
    $parts = explode('.', $clean, 2);
    return count($parts) === 2 ? [$parts[0], $parts[1]] : ['dbo', $parts[0]];
}

function clear_installation_type_has_column($db, $table, $column)
{
    list($schema, $name) = clear_installation_type_table_parts($table);
    return (int)$db->scalar(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?",
        [$schema, $name, $column]
    ) > 0;
}
