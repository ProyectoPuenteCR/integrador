<?php
require_once __DIR__ . '/zones.php';

function sigma_columns()
{
    return [
        'POZO','BATERIA','3SIGMA','CONT_EXCESOS','PANTALLA','HOY','FC','PI-005-PL',
        'VI-001-V','SI-002-SPM','YL-007-WS','METODO','QT:GOLPES-MIN',
        'TI-002-TAE','TI-003-TBP','TI-004-TE'
    ];
}

function sigma_filter_map()
{
    return [
        'POZO'          => ['param'=>'pozo','type'=>'text'],
        'BATERIA'       => ['param'=>'bateria','type'=>'select'],
        '3SIGMA'        => ['param'=>'sigma','type'=>'sigma'],
        'CONT_EXCESOS'  => ['param'=>'excesos','type'=>'excess'],
        'PANTALLA'      => ['param'=>'pantalla','type'=>'link'],
        'HOY'           => ['param'=>'hoy','type'=>'select'],
        'FC'            => ['param'=>'fc','type'=>'select'],
        'PI-005-PL'     => ['param'=>'pi005','type'=>'select'],
        'VI-001-V'      => ['param'=>'vi001','type'=>'select'],
        'SI-002-SPM'    => ['param'=>'si002','type'=>'select'],
        'YL-007-WS'     => ['param'=>'yl007','type'=>'select'],
        'METODO'        => ['param'=>'metodo','type'=>'select'],
        'QT:GOLPES-MIN' => ['param'=>'golpes','type'=>'select'],
        'TI-002-TAE'    => ['param'=>'tae','type'=>'select'],
        'TI-003-TBP'    => ['param'=>'tbp','type'=>'select'],
        'TI-004-TE'     => ['param'=>'te','type'=>'select'],
    ];
}

function sigma_q($value)
{
    return '[' . str_replace(']', ']]', (string)$value) . ']';
}

function sigma_text($value)
{
    if ($value === null) return '';
    if ($value instanceof DateTimeInterface) return $value->format('Y-m-d H:i:s');
    return trim((string)$value);
}

function sigma_date($value, $withTime = true)
{
    if ($value instanceof DateTimeInterface) return $value->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
    $text = sigma_text($value);
    if ($text === '') return '—';
    $timestamp = strtotime($text);
    return $timestamp === false ? $text : date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $timestamp);
}

function sigma_number($value, $decimals = 2)
{
    $text = sigma_text($value);
    if ($text === '') return '—';
    $normalized = str_replace(',', '.', $text);
    return is_numeric($normalized) ? number_format((float)$normalized, $decimals, ',', '.') : $text;
}

function sigma_is_link($value)
{
    return preg_match('~^https?://~i', sigma_text($value)) === 1;
}

function sigma_cache_exists($db)
{
    return $db && $db->ok() && (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_CACHE_TECSS_3SIGMA',N'U') IS NULL THEN 0 ELSE 1 END") === 1;
}

function sigma_filters_from_request()
{
    $filters = [];
    foreach (sigma_filter_map() as $column => $spec) {
        $filters[$column] = trim((string)($_GET[$spec['param']] ?? ''));
    }
    return $filters;
}

function sigma_build_where($db, array $filters, $globalSearch, $zone, array $zoneOptions)
{
    $where = [];
    $params = [];
    $globalSearch = trim((string)$globalSearch);
    if ($globalSearch !== '') {
        $parts = [];
        foreach (['POZO','BATERIA','METODO'] as $column) {
            $parts[] = 'LTRIM(RTRIM(CONVERT(nvarchar(255),' . sigma_q($column) . '))) LIKE ?';
            $params[] = '%' . $globalSearch . '%';
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    $zone = clear_zone_valid((string)$zone, $zoneOptions);
    if ($zone !== '') {
        $zoneBatteries = array_values(array_unique(array_filter(array_map('clear_zone_compact_value', clear_zone_batteries($db, $zone, 'pozos')))));
        if ($zoneBatteries) {
            $where[] = clear_zone_normalized_battery_expr('[BATERIA]') . ' COLLATE DATABASE_DEFAULT IN (' . implode(',', array_fill(0, count($zoneBatteries), '?')) . ')';
            foreach ($zoneBatteries as $zoneBattery) $params[] = $zoneBattery;
        } else {
            $where[] = '1=0';
        }
    }

    foreach (sigma_filter_map() as $column => $spec) {
        $value = trim((string)($filters[$column] ?? ''));
        if ($value === '') continue;
        $quoted = sigma_q($column);
        switch ($spec['type']) {
            case 'text':
                $where[] = 'LTRIM(RTRIM(CONVERT(nvarchar(255),' . $quoted . '))) LIKE ?';
                $params[] = '%' . $value . '%';
                break;
            case 'sigma':
                if ($value === 'con') $where[] = "NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255),[3SIGMA]))),'') IS NOT NULL";
                elseif ($value === 'sin') $where[] = "NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255),[3SIGMA]))),'') IS NULL";
                break;
            case 'excess':
                if ($value === 'con') $where[] = '[CONT_EXCESOS_NUM] > 0';
                elseif ($value === 'sin') $where[] = 'ISNULL([CONT_EXCESOS_NUM],0) = 0';
                break;
            case 'link':
                if ($value === 'con') $where[] = "LTRIM(RTRIM(CONVERT(nvarchar(1000),[PANTALLA]))) LIKE 'http%'";
                elseif ($value === 'sin') $where[] = "([PANTALLA] IS NULL OR LTRIM(RTRIM(CONVERT(nvarchar(1000),[PANTALLA]))) NOT LIKE 'http%')";
                break;
            default:
                $where[] = 'LTRIM(RTRIM(CONVERT(nvarchar(255),' . $quoted . '))) = ?';
                $params[] = $value;
        }
    }

    return [$where ? (' WHERE ' . implode(' AND ', $where)) : '', $params, $zone];
}

function sigma_filter_options($db)
{
    $wanted = [];
    foreach (sigma_filter_map() as $column => $spec) {
        if ($spec['type'] === 'select') $wanted[] = $column;
    }
    $options = array_fill_keys($wanted, []);
    if (!$wanted || !$db || !$db->ok()) return $options;

    $unions = [];
    foreach ($wanted as $column) {
        $quoted = sigma_q($column);
        $literal = str_replace("'", "''", $column);
        $unions[] = "SELECT N'$literal' AS FILTER_COLUMN, LTRIM(RTRIM(CONVERT(nvarchar(255),$quoted))) AS FILTER_VALUE FROM dbo.CLEAR_CACHE_TECSS_3SIGMA WHERE $quoted IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(255),$quoted)))<>''";
    }
    $sql = ";WITH Valores AS (" . implode(' UNION ALL ', $unions) . "), Unicos AS
            (SELECT FILTER_COLUMN,FILTER_VALUE,ROW_NUMBER() OVER(PARTITION BY FILTER_COLUMN ORDER BY FILTER_VALUE) AS RN FROM Valores GROUP BY FILTER_COLUMN,FILTER_VALUE)
            SELECT FILTER_COLUMN,FILTER_VALUE FROM Unicos WHERE RN<=200 ORDER BY FILTER_COLUMN,FILTER_VALUE";
    foreach ($db->all($sql) as $row) {
        $column = sigma_text($row['FILTER_COLUMN'] ?? '');
        $value = sigma_text($row['FILTER_VALUE'] ?? '');
        if ($column !== '' && $value !== '' && isset($options[$column])) $options[$column][] = $value;
    }
    return $options;
}

function sigma_page_url(array $changes = [])
{
    $query = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
        else $query[$key] = $value;
    }
    return 'tecss_3sigma.php' . ($query ? ('?' . http_build_query($query)) : '');
}

function sigma_export_url()
{
    $query = $_GET;
    unset($query['page']);
    return 'tecss_3sigma_export.php' . ($query ? ('?' . http_build_query($query)) : '');
}
