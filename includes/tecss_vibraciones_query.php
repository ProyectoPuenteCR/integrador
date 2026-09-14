<?php

function tecss_vib_tables_ready($db)
{
    if (!$db || !$db->ok()) return false;
    return (int)$db->scalar(
        "SELECT CASE WHEN " .
        "OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_DATO',N'U') IS NOT NULL AND " .
        "OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_ESTADO',N'U') IS NOT NULL AND " .
        "OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_EVENTO',N'U') IS NOT NULL AND " .
        "OBJECT_ID(N'dbo.CLEAR_TECSS_VIBRACIONES_EJECUCION',N'U') IS NOT NULL " .
        "THEN 1 ELSE 0 END"
    ) === 1;
}

function tecss_vib_text($value)
{
    if ($value === null) return '';
    if ($value instanceof DateTimeInterface) return $value->format('Y-m-d H:i:s');
    return trim((string)$value);
}

function tecss_vib_date($value, $withTime = true)
{
    $text = tecss_vib_text($value);
    if ($text === '') return '—';
    $timestamp = strtotime($text);
    return $timestamp === false ? $text : date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $timestamp);
}

function tecss_vib_number($value, $decimals = 1)
{
    $text = tecss_vib_text($value);
    if ($text === '') return '—';
    $normalized = str_replace(',', '.', $text);
    return is_numeric($normalized)
        ? number_format((float)$normalized, (int)$decimals, ',', '.')
        : $text;
}

function tecss_vib_states()
{
    return [
        'critico' => 'Crítico',
        'alerta' => 'Alerta',
        'frecuente' => 'Exceso frecuente',
        'parado' => 'Parado / Sin señal',
        'normal' => 'Normal',
    ];
}

function tecss_vib_filters_from_request()
{
    $state = strtolower(trim((string)($_GET['estado'] ?? '')));
    if (!array_key_exists($state, tecss_vib_states())) $state = '';
    return [
        'q' => trim((string)($_GET['q'] ?? '')),
        'pozo' => trim((string)($_GET['pozo'] ?? '')),
        'estado' => $state,
        'bateria' => trim((string)($_GET['bateria'] ?? '')),
    ];
}

function tecss_vib_build_where(array $filters)
{
    $where = [];
    $params = [];
    if ($filters['q'] !== '') {
        $where[] = "(POZO LIKE ? OR ISNULL(NOMBRE,'') LIKE ? OR ISNULL(BATERIA,'') LIKE ?)";
        $search = '%' . $filters['q'] . '%';
        array_push($params, $search, $search, $search);
    }
    if (($filters['pozo'] ?? '') !== '') {
        $where[] = 'POZO LIKE ?';
        $params[] = '%' . $filters['pozo'] . '%';
    }
    if ($filters['estado'] !== '') {
        $where[] = 'ESTADO=?';
        $params[] = $filters['estado'];
    }
    if ($filters['bateria'] !== '') {
        $where[] = "LTRIM(RTRIM(ISNULL(BATERIA,'')))=?";
        $params[] = $filters['bateria'];
    }
    return [$where ? (' WHERE ' . implode(' AND ', $where)) : '', $params];
}

function tecss_vib_page_url(array $changes = [])
{
    $query = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
        else $query[$key] = $value;
    }
    return 'tecss_vibraciones.php' . ($query ? ('?' . http_build_query($query)) : '');
}

function tecss_vib_export_url()
{
    $query = $_GET;
    unset($query['page']);
    return 'tecss_vibraciones_export.php' . ($query ? ('?' . http_build_query($query)) : '');
}

function tecss_vib_state_priority_sql()
{
    return "CASE ESTADO WHEN 'critico' THEN 0 WHEN 'alerta' THEN 1 " .
           "WHEN 'frecuente' THEN 2 WHEN 'parado' THEN 3 ELSE 4 END";
}

function tecss_vib_sort_from_request()
{
    $key = strtolower(trim((string)($_GET['orden'] ?? 'prioridad')));
    $allowed = [
        'prioridad' => tecss_vib_state_priority_sql(),
        'pozo' => 'POZO',
        'bateria' => "ISNULL(BATERIA,'')",
        'estado' => tecss_vib_state_priority_sql(),
    ];
    if (!isset($allowed[$key])) $key = 'prioridad';
    $direction = strtolower(trim((string)($_GET['dir'] ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';
    return ['key' => $key, 'direction' => $direction, 'sql' => $allowed[$key]];
}

function tecss_vib_order_sql(array $sort)
{
    if (($sort['key'] ?? 'prioridad') === 'prioridad') {
        return ' ORDER BY ' . tecss_vib_state_priority_sql() . ',SCORE_SEVERIDAD DESC,POZO ASC';
    }
    return ' ORDER BY ' . $sort['sql'] . ' ' . $sort['direction'] . ',POZO ASC';
}

function tecss_vib_datetime_for_json($value)
{
    if ($value instanceof DateTimeInterface) return $value->format('Y-m-d H:i:s');
    return tecss_vib_text($value);
}
