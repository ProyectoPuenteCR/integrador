<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/zones.php';
require_once __DIR__ . '/includes/alarm_actions.php';

auth_require();
$historyMode = defined('POZOS_ALARMAS_HISTORY_MODE') && POZOS_ALARMAS_HISTORY_MODE === true;
$screenKey = $historyMode ? 'pozos_todas_alarmas' : 'pozos_alarmas24';
$screenUrl = $historyMode ? 'pozos_todas_alarmas.php' : 'pozos_alarmas24.php';
$exportUrl = $historyMode ? 'pozos_todas_alarmas_export.php' : 'pozos_alarmas24_export.php';
permissions_require_menu($screenKey);

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = $screenKey;
$embedMode = isset($_GET['embed']) && (string)$_GET['embed'] === '1';

$db = clear_db();
$dbError = $db->ok() ? '' : $db->error();
$showCfnStatus = $db->ok() && clear_alarm_cfn_show_status($db);
$zones=clear_zones_all($db);
$zoneFilter=clear_zone_valid((string)($_GET['zona']??''),$zones);
$table = 'dbo.FIXALARMS';
$page = max(1, (int)($_GET['p'] ?? 1));
$allowedPerPage = $historyMode ? ['50', '100'] : ['50', '100', 'all'];
$perPageParam = strtolower(trim((string)($_GET['per_page'] ?? '50')));
if (!in_array($perPageParam, $allowedPerPage, true)) $perPageParam = '50';
$showAllRows = $perPageParam === 'all';
$perPage = $showAllRows ? 0 : (int)$perPageParam;
if ($showAllRows) $page = 1;

$wellPrefix = trim((string)($_GET['prefijo_pozo'] ?? 'YPF.SC'));
$wellSearch = trim((string)($_GET['pozo'] ?? ''));
$descSearch = trim((string)($_GET['descripcion'] ?? ''));
$statusFilter = trim((string)($_GET['f_estado'] ?? ''));
if (!$showCfnStatus && strtoupper($statusFilter) === 'CFN') $statusFilter = '';
$priorityFilter = trim((string)($_GET['f_prioridad'] ?? ''));
$valueBucketFilter = trim((string)($_GET['f_valorcat'] ?? ''));

$now = new DateTimeImmutable('now');
$defaultTo = $now;
$defaultFrom = $now->sub(new DateInterval('PT24H'));
$fromDate = trim((string)($_GET['desde'] ?? ($historyMode ? '' : $defaultFrom->format('Y-m-d'))));
$fromTime = trim((string)($_GET['hora_desde'] ?? ($historyMode ? '' : $defaultFrom->format('H:i'))));
$toDate = trim((string)($_GET['hasta'] ?? ($historyMode ? '' : $defaultTo->format('Y-m-d'))));
$toTime = trim((string)($_GET['hora_hasta'] ?? ($historyMode ? '' : $defaultTo->format('H:i'))));
$columnFilterInput = isset($_GET['cf']) && is_array($_GET['cf']) ? $_GET['cf'] : [];

function pa24_valid_date($value, $fallback) {
    $value = trim((string)$value);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : $fallback;
}
function pa24_valid_time($value, $fallback) {
    $value = trim((string)$value);
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $fallback;
}
function pa24_terms($value) {
    $value = trim((string)$value);
    if ($value === '') return [];
    return array_values(array_filter(preg_split('/\s+/', $value), static function ($v) { return trim((string)$v) !== ''; }));
}
function pa24_url_with(array $changes) {
    global $screenUrl;
    $params = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($params[$key]); else $params[$key] = $value;
    }
    unset($params['p']);
    return $screenUrl . '?' . http_build_query($params);
}
function pa24_value($value) {
    if ($value === null || $value === '') return '—';
    if ($value instanceof DateTimeInterface) return $value->format('d/m/Y H:i:s');
    return (string)$value;
}
function pa24_related_tag_query($tag) {
    $tag = trim((string)$tag);
    if ($tag === '') return '';
    $parts = explode('_', $tag);
    if (count($parts) > 1) {
        array_pop($parts);
        $group = trim(implode('_', $parts));
        if ($group !== '') return $group;
    }
    return $tag;
}
function pa24_badge_status($value) {
    $raw = trim((string)$value);
    $u = strtoupper($raw);
    if (in_array($u, ['ALARMA','ALARM','HI','LO','HIHI','LOLO'], true)) return ['red', $raw ?: '—'];
    if (in_array($u, ['OK','NORMAL','RTN'], true)) return ['green', $raw ?: '—'];
    if (in_array($u, ['PARADA','STOP'], true)) return ['amber', $raw ?: '—'];
    if ($u === 'CFN') return ['amber', 'CFN'];
    return ['gray', $raw ?: '—'];
}
function pa24_badge_priority($value) {
    $raw = trim((string)$value);
    $u = strtoupper($raw);
    if (in_array($u, ['HIGH','ALTA','CRITICAL','CRITICA','CRÍTICA'], true)) return ['red', 'Alta'];
    if (in_array($u, ['MED','MEDIUM','MEDIA'], true)) return ['amber', 'Media'];
    if (in_array($u, ['LOW','BAJA'], true)) return ['cyan', 'Baja'];
    if ($u === 'INFO') return ['gray', 'Info'];
    return ['gray', $raw ?: '—'];
}
function pa24_value_bucket_map() {
    return [
        'FALLA'  => ['FALLA', 'FAULT'],
        'MARCHA' => ['MARCHA', 'RUNNING', 'RUN'],
        'PARADO' => ['PARADO', 'PARADA', 'STOP', 'DETENIDO'],
        'OPEN'   => ['OPEN', 'ABIERTO'],
        'CLOSE'  => ['CLOSE', 'CLOSED', 'CERRADO'],
        'NORMAL' => ['NORMAL', 'OK'],
    ];
}
function pa24_value_bucket_sql($column) {
    $expr = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(4000),[$column]))))";
    $cases = [];
    foreach (pa24_value_bucket_map() as $bucket => $aliases) {
        $quoted = array_map(static function ($v) { return "'" . str_replace("'", "''", $v) . "'"; }, $aliases);
        $cases[] = "WHEN $expr IN (" . implode(',', $quoted) . ") THEN '$bucket'";
    }
    return 'CASE ' . implode(' ', $cases) . " ELSE '' END";
}
function pa24_status_group_sql($column) {
    return "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(4000),[$column]))))";
}
function pa24_priority_group_sql($column) {
    $expr = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(4000),[$column]))))";
    return "CASE WHEN $expr IN ('HIGH','ALTA','CRITICAL','CRITICA','CRÍTICA') THEN 'HIGH' WHEN $expr IN ('MED','MEDIUM','MEDIA') THEN 'MEDIUM' WHEN $expr IN ('LOW','BAJA') THEN 'LOW' WHEN $expr='INFO' THEN 'INFO' ELSE $expr END";
}
function pa24_filter_chip_href($kind, $value, $current) {
    $param = $kind === 'status' ? 'f_estado' : ($kind === 'priority' ? 'f_prioridad' : 'f_valorcat');
    $newValue = ($current === $value) ? null : $value;
    return pa24_url_with([$param => $newValue]);
}
function pa24_sort_count_map($input, array $preferredOrder) {
    $result = [];
    foreach ($preferredOrder as $key) {
        if (isset($input[$key])) $result[$key] = $input[$key];
    }
    foreach ($input as $key => $value) {
        if (!isset($result[$key])) $result[$key] = $value;
    }
    return $result;
}

$fromDate = ($historyMode && $fromDate === '') ? '' : pa24_valid_date($fromDate, $historyMode ? '' : $defaultFrom->format('Y-m-d'));
$toDate = ($historyMode && $toDate === '') ? '' : pa24_valid_date($toDate, $historyMode ? '' : $defaultTo->format('Y-m-d'));
$fromTime = ($historyMode && $fromDate === '' && $fromTime === '') ? '' : pa24_valid_time($fromTime, '00:00');
$toTime = ($historyMode && $toDate === '' && $toTime === '') ? '' : pa24_valid_time($toTime, '23:59');
if ($fromDate !== '' && $fromTime === '') $fromTime = '00:00';
if ($toDate !== '' && $toTime === '') $toTime = '23:59';
$fromSql = $fromDate !== '' ? $fromDate . 'T' . $fromTime . ':00' : '';
$toSql = $toDate !== '' ? $toDate . 'T' . $toTime . ':59' : '';
if ($fromSql !== '' && $toSql !== '' && strtotime($fromSql) > strtotime($toSql)) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
    [$fromTime, $toTime] = [$toTime, $fromTime];
    $fromSql = $fromDate . 'T' . $fromTime . ':00';
    $toSql = $toDate . 'T' . $toTime . ':59';
}
$hasExplicitDateInputs = isset($_GET['desde']) || isset($_GET['hasta']) || isset($_GET['hora_desde']) || isset($_GET['hora_hasta']);

$columns = [];
$rows = [];
$total = 0;
$statusCounts = [];
$priorityCounts = [];
$valueCounts = array_fill_keys(array_keys(pa24_value_bucket_map()), 0);
$columnFilters = [];
$usedFallback = false;
$displayFromSql = $fromSql;
$displayToSql = $toSql;
$statsFromSql = $defaultFrom->format('Y-m-d\TH:i:s');
$statsToSql = $defaultTo->format('Y-m-d\TH:i:s');
$wellColumn = 'ALM_ALMEXTFLD2';
$descColumn = 'ALM_DESCR';
$dateColumn = 'ALM_NATIVETIMEIN';
$lastDateColumn = 'ALM_NATIVETIMELAST';
$valueColumn = 'ALM_VALUE';
$tagColumn = 'ALM_TAGNAME';
$statusColumn = 'ALM_ALMSTATUS';
$priorityColumn = 'ALM_ALMPRIORITY';
$unitColumn = 'ALM_UNIT';

if ($db->ok()) {
    $metaRows = $db->all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS' ORDER BY ORDINAL_POSITION");
    $allColumns = [];
    foreach ($metaRows as $metaRow) {
        $column = trim((string)($metaRow['COLUMN_NAME'] ?? $metaRow['column_name'] ?? reset($metaRow)));
        if ($column !== '') $allColumns[] = $column;
    }
    if (!$allColumns) {
        $sample = $db->all("SELECT TOP 1 * FROM $table");
        if ($sample) $allColumns = array_keys($sample[0]);
    }

    $lookup = [];
    foreach ($allColumns as $column) $lookup[strtoupper($column)] = $column;
    $wellColumn = $lookup['ALM_ALMEXTFLD2'] ?? $wellColumn;
    $descColumn = $lookup['ALM_DESCR'] ?? ($lookup['ALM_TAGDESC'] ?? $descColumn);
    $dateColumn = $lookup['ALM_NATIVETIMEIN'] ?? $dateColumn;
    $lastDateColumn = $lookup['ALM_NATIVETIMELAST'] ?? $lastDateColumn;
    $valueColumn = $lookup['ALM_VALUE'] ?? $valueColumn;
    $tagColumn = $lookup['ALM_TAGNAME'] ?? $tagColumn;
    $statusColumn = $lookup['ALM_ALMSTATUS'] ?? $statusColumn;
    $priorityColumn = $lookup['ALM_ALMPRIORITY'] ?? $priorityColumn;
    $unitColumn = $lookup['ALM_UNIT'] ?? $unitColumn;

    $preferred = [$wellColumn, $descColumn, $dateColumn, $lastDateColumn, $valueColumn, $tagColumn, $unitColumn, $statusColumn, $priorityColumn];
    foreach ($preferred as $column) {
        if (in_array($column, $allColumns, true) && !in_array($column, $columns, true)) $columns[] = $column;
    }
    foreach ($allColumns as $column) {
        if (!in_array($column, $columns, true)) $columns[] = $column;
    }

    foreach ($columns as $index => $column) {
        $filterValue = trim((string)($columnFilterInput[$index] ?? ''));
        if ($filterValue !== '') $columnFilters[$index] = $filterValue;
    }

    $baseConditions = [
        "[$wellColumn] IS NOT NULL",
        "LTRIM(RTRIM(CONVERT(nvarchar(4000),[$wellColumn]))) <> ''"
    ];
    $baseParams = [];
    if ($zoneFilter !== '') {
        $tagNorm="LTRIM(RTRIM(CONVERT(nvarchar(255),[$tagColumn])))";
        $tagInst="LEFT($tagNorm,CHARINDEX('_',$tagNorm+'_')-1)";
        $baseConditions[]="EXISTS (SELECT 1 FROM [CLEAR].[ZONAS] Z WHERE LTRIM(RTRIM(Z.ZONA)) COLLATE DATABASE_DEFAULT = ? COLLATE DATABASE_DEFAULT AND LTRIM(RTRIM(Z.BATERIA)) COLLATE DATABASE_DEFAULT = $tagInst COLLATE DATABASE_DEFAULT)";
        $baseParams[]=$zoneFilter;
    }
    if ($wellPrefix !== '') {
        $baseConditions[] = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(4000),[$wellColumn])))) LIKE ?";
        $baseParams[] = strtoupper($wellPrefix) . '%';
    }
    foreach (pa24_terms($wellSearch) as $term) {
        $baseConditions[] = "(CONVERT(nvarchar(4000),[$wellColumn]) LIKE ? OR REPLACE(REPLACE(REPLACE(CONVERT(nvarchar(4000),[$wellColumn]),'_',''),'-',''),' ','') LIKE ?)";
        $baseParams[] = '%' . $term . '%';
        $baseParams[] = '%' . str_replace(['_', '-', ' '], '', $term) . '%';
    }
    foreach (pa24_terms($descSearch) as $term) {
        $baseConditions[] = "CONVERT(nvarchar(4000),[$descColumn]) LIKE ?";
        $baseParams[] = '%' . $term . '%';
    }

    $rangeConditions = [];
    $rangeParams = [];
    if ($fromSql !== '') {
        $rangeConditions[] = "[$dateColumn] >= CONVERT(datetime2, ?, 126)";
        $rangeParams[] = $fromSql;
    }
    if ($toSql !== '') {
        $rangeConditions[] = "[$dateColumn] <= CONVERT(datetime2, ?, 126)";
        $rangeParams[] = $toSql;
    }

    $effectiveConditions = array_merge($baseConditions, $rangeConditions);
    $effectiveParams = array_merge($baseParams, $rangeParams);
    $effectiveWhere = 'WHERE ' . implode(' AND ', $effectiveConditions);
    $preTotal = $historyMode ? 1 : (int)$db->scalar("SELECT COUNT(*) FROM $table $effectiveWhere", $effectiveParams);

    if (!$historyMode && $preTotal === 0 && !$hasExplicitDateInputs) {
        $usedFallback = true;
        $effectiveConditions = $baseConditions;
        $effectiveParams = $baseParams;
        $latest = $db->all("SELECT TOP 1 [$dateColumn] AS dt FROM $table WHERE " . implode(' AND ', $baseConditions) . " ORDER BY [$dateColumn] DESC", $baseParams);
        if ($latest) {
            $displayToSql = date('Y-m-d\TH:i:s', strtotime((string)$latest[0]['dt']));
            $displayFromSql = $displayToSql;
        }
    }

    $effectiveWhere = 'WHERE ' . implode(' AND ', $effectiveConditions);

    $statusSql = pa24_status_group_sql($statusColumn);
    $prioritySql = pa24_priority_group_sql($priorityColumn);
    $valueBucketSql = pa24_value_bucket_sql($valueColumn);

    $statsConditions = $historyMode ? $effectiveConditions : array_merge($baseConditions, [
        "[$dateColumn] >= CONVERT(datetime2, ?, 126)",
        "[$dateColumn] <= CONVERT(datetime2, ?, 126)"
    ]);
    $statsParams = $historyMode ? $effectiveParams : array_merge($baseParams, [$statsFromSql, $statsToSql]);
    $statsWhere = 'WHERE ' . implode(' AND ', $statsConditions);

    // Una sola lectura del rango para las tres familias de indicadores.
    // CROSS APPLY evita repetir tres escaneos completos de FIXALARMS.
    $facetSql = "SELECT F.tipo, F.grp, COUNT(*) AS total FROM $table " .
        "CROSS APPLY (VALUES " .
        "('STATUS', CONVERT(nvarchar(4000), $statusSql)), " .
        "('PRIORITY', CONVERT(nvarchar(4000), $prioritySql)), " .
        "('VALUE', CONVERT(nvarchar(4000), $valueBucketSql))" .
        ") F(tipo, grp) $statsWhere GROUP BY F.tipo, F.grp";
    foreach ($db->all($facetSql, $statsParams) as $item) {
        $type = strtoupper(trim((string)($item['tipo'] ?? $item['TIPO'] ?? '')));
        $key = trim((string)($item['grp'] ?? $item['GRP'] ?? ''));
        $count = (int)($item['total'] ?? $item['TOTAL'] ?? 0);
        if ($key === '') continue;
        if ($type === 'STATUS' && ($showCfnStatus || strtoupper($key) !== 'CFN')) $statusCounts[$key] = $count;
        elseif ($type === 'PRIORITY') $priorityCounts[$key] = $count;
        elseif ($type === 'VALUE' && array_key_exists($key, $valueCounts)) $valueCounts[$key] = $count;
    }

    $finalConditions = $effectiveConditions;
    $finalParams = $effectiveParams;
    if ($statusFilter !== '') {
        $finalConditions[] = pa24_status_group_sql($statusColumn) . ' = ?';
        $finalParams[] = strtoupper($statusFilter);
    }
    if ($priorityFilter !== '') {
        $finalConditions[] = pa24_priority_group_sql($priorityColumn) . ' = ?';
        $finalParams[] = strtoupper($priorityFilter);
    }
    if ($valueBucketFilter !== '') {
        $finalConditions[] = pa24_value_bucket_sql($valueColumn) . ' = ?';
        $finalParams[] = strtoupper($valueBucketFilter);
    }
    foreach ($columnFilters as $index => $filterValue) {
        if (!isset($columns[$index])) continue;
        $filterColumn = $columns[$index];
        foreach (pa24_terms($filterValue) as $term) {
            $finalConditions[] = "CONVERT(nvarchar(4000),[$filterColumn]) LIKE ?";
            $finalParams[] = '%' . $term . '%';
        }
    }

    $where = 'WHERE ' . implode(' AND ', $finalConditions);
    $total = (int)$db->scalar("SELECT COUNT(*) FROM $table $where", $finalParams);
    $offset = ($page - 1) * $perPage;
    $dataSql = "SELECT * FROM $table $where ORDER BY [$dateColumn] DESC";
    if (!$showAllRows) $dataSql .= " OFFSET $offset ROWS FETCH NEXT $perPage ROWS ONLY";
    $rows = $db->all($dataSql, $finalParams);
}

$totalPages = $showAllRows ? 1 : max(1, (int)ceil($total / max(1,$perPage)));
$hasFilters = $zoneFilter !== '' || $wellSearch !== '' || $descSearch !== '' || $wellPrefix !== 'YPF.SC' || $statusFilter !== '' || $priorityFilter !== '' || $valueBucketFilter !== '' || $hasExplicitDateInputs || !empty($columnFilters);

$statusCounts = pa24_sort_count_map($statusCounts, ['HI','HIHI','LO','LOLO','OK','NORMAL','RTN','PARADA','STOP','CFN']);
$priorityCounts = pa24_sort_count_map($priorityCounts, ['HIGH','MEDIUM','LOW','INFO']);

$columnLabels = [
    strtoupper($wellColumn) => 'POZO',
    strtoupper($descColumn) => 'DESCRIPCIÓN',
    strtoupper($dateColumn) => 'HORA INICIO',
    strtoupper($lastDateColumn) => 'ÚLTIMA',
    strtoupper($valueColumn) => 'VALOR',
    strtoupper($tagColumn) => 'TAG',
    strtoupper($unitColumn) => 'ALM_UNIT',
    strtoupper($statusColumn) => 'ESTADO',
    strtoupper($priorityColumn) => 'PRIORIDAD',
];
function pa24_column_label($column, $labels) {
    return $labels[strtoupper((string)$column)] ?? $column;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $historyMode ? 'Pozos · Histórico de alarmas' : 'Pozos · Alarmas'; ?> · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260803-grid1">
  <link rel="stylesheet" href="assets/css/alarm_actions.css?v=20260807-2">
  <style>
    .pozosSummary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:14px}
    .pozosSummary__card{background:#fff;border:1px solid var(--line-mid);border-top:3px solid var(--petrol);border-radius:var(--radius);padding:14px 16px}
    .pozosSummary__label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-mut);font-weight:700}
    .pozosSummary__value{font-family:var(--font-head);font-size:25px;font-weight:700;color:var(--petrol);margin-top:5px}
    .pozosSummary__hint{font-size:11px;color:var(--text-mut);margin-top:3px}
    .pozosToolbar{display:grid;grid-template-columns:minmax(120px,.55fr) minmax(190px,.8fr) minmax(230px,1fr) minmax(520px,1.7fr) auto;gap:10px;align-items:center;margin-bottom:12px}
    .pozosToolbar .allAlarmsSearch{min-width:0}
    .pozosDateRange{display:grid;grid-template-columns:auto 1fr 1fr auto 1fr 1fr;align-items:center;gap:7px;background:#fff;border:1px solid var(--line-mid);border-radius:var(--radius-sm);padding:6px 9px;min-width:0}
    .pozosDateRange__label{font-size:9px;font-weight:800;color:var(--text-mut);letter-spacing:.7px;text-transform:uppercase}
    .pozosDateRange input{min-width:0;border:0;background:transparent;color:var(--text);font:inherit;outline:0}
    .pozosFilterSections{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0 0 14px}
    .pozosFilterSection{background:#fff;border:1px solid var(--line-mid);border-radius:var(--radius);padding:14px 16px}
    .pozosFilterSection__title{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-mut);font-weight:800;margin-bottom:12px}
    .pozosFilterChips{display:flex;flex-wrap:wrap;gap:8px}
    .pozosChip{display:inline-flex;align-items:center;gap:8px;padding:9px 12px;border-radius:999px;border:1px solid var(--line-mid);background:#fff;color:var(--text);text-decoration:none;font-size:12px;font-weight:700;transition:.15s ease}
    .pozosChip:hover{transform:translateY(-1px);box-shadow:0 2px 8px rgba(16,46,60,.08)}
    .pozosChip__count{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 7px;border-radius:999px;background:rgba(16,46,60,.07);font-size:11px}
    .pozosChip.is-active{background:var(--petrol-soft);border-color:var(--petrol-line);color:var(--petrol)}
    .pozosChip--red{border-color:#f3c8c3;color:var(--red-tx);background:#fff7f6}
    .pozosChip--red.is-active{background:var(--red-soft)}
    .pozosChip--amber{border-color:#efd9af;color:var(--amber-tx);background:#fffaf0}
    .pozosChip--amber.is-active{background:var(--amber-soft)}
    .pozosChip--green{border-color:#c5e7d5;color:var(--green-tx);background:#f4fbf7}
    .pozosChip--green.is-active{background:var(--green-soft)}
    .pozosChip--cyan{border-color:var(--petrol-line);color:var(--petrol);background:#f2f9fb}
    .pozosChip--cyan.is-active{background:var(--petrol-soft)}
    .pozosChip--gray{background:#f7f9fb;color:var(--text-soft)}
    .pozosFilterEmpty{font-size:12px;color:var(--text-mut)}
    .allAlarmsScroll table.grid thead .pozosColumnFilterRow th{padding:7px 8px!important;background:#e8f0f3!important;top:39px!important;z-index:13!important;text-transform:none!important}
    html[data-density="compact"] .allAlarmsScroll table.grid thead .pozosColumnFilterRow th{top:29px!important}
    html[data-density="comfortable"] .allAlarmsScroll table.grid thead .pozosColumnFilterRow th{top:45px!important}
    .pozosColumnFilter{display:block;width:100%;min-width:105px;height:30px;border:1px solid #b9cbd2;border-radius:6px;background:#fff;color:var(--text);font:500 11px/1 var(--font-body);padding:0 9px;outline:0}
    .pozosColumnFilter:focus{border-color:var(--petrol);box-shadow:0 0 0 2px rgba(26,77,92,.13)}
    .pozosColumnFilter::placeholder{color:#81959e}
    html[data-theme="dark"] .allAlarmsScroll table.grid thead .pozosColumnFilterRow th{background:#123e4b!important}
    html[data-theme="dark"] .pozosColumnFilter{background:#0f303a;border-color:#416570;color:#e2edf0}
    body.is-embedded{background:var(--bg,#eef3f6);overflow:auto}
    body.is-embedded .app{display:block;min-height:100vh}
    body.is-embedded .main{width:100%;max-width:none;margin:0;padding:14px 16px 22px}
    body.is-embedded .pozosSummary{margin-top:0}
    body.is-embedded .tablewrap{box-shadow:none}
    @media(max-width:1450px){.pozosToolbar{grid-template-columns:1fr 1fr}.pozosDateRange{grid-column:1/-1}.pozosFilterSections{grid-template-columns:1fr}}
    @media(max-width:760px){.pozosToolbar{grid-template-columns:1fr}.pozosDateRange{grid-template-columns:auto 1fr 1fr}.pozosSummary{grid-template-columns:1fr}}
  </style>
</head>
<body class="<?php echo $embedMode ? 'is-embedded' : ''; ?>">
<div class="app">
  <?php if (!$embedMode) include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <?php if (!$embedMode) include __DIR__ . '/includes/topbar.php'; ?>
    <?php if (!$embedMode): ?>
    <div class="page__head">
      <div>
        <h1 class="page__title"><?php echo $historyMode ? 'Pozos · Todas las alarmas' : 'Pozos · Alarmas'; ?></h1>
        <div class="page__sub"><?php echo $historyMode ? 'Histórico completo de alarmas de pozos desde dbo.FIXALARMS · más nuevas primero.' : 'Consulta operativa de alarmas por pozo desde dbo.FIXALARMS.'; ?></div>
      </div>
      <div class="page__live"><span class="dot"></span><?php echo $dbError ? 'Sin conexión' : 'En vivo'; ?></div>
    </div>
    <?php endif; ?>

    <?php if ($dbError): ?>
      <div class="tablewrap"><div class="empty"><p><b>Sin conexión a la base:</b> <?php echo h($dbError); ?></p></div></div>
    <?php else: ?>
      <div class="pozosSummary">
        <div class="pozosSummary__card">
          <div class="pozosSummary__label"><?php echo $historyMode ? 'Alarmas históricas de pozos' : 'Alarmas de pozos'; ?></div>
          <div class="pozosSummary__value"><?php echo number_format($total,0,',','.'); ?></div>
          <div class="pozosSummary__hint">Resultado actual según rango, prefijo y filtros aplicados.</div>
        </div>
        <div class="pozosSummary__card">
          <div class="pozosSummary__label">Ventana analizada</div>
          <?php if ($historyMode && $fromSql === '' && $toSql === ''): ?>
            <div class="pozosSummary__value" style="font-size:18px">Histórico completo</div>
            <div class="pozosSummary__hint">Sin límite de fechas. Podés acotar el análisis desde los filtros.</div>
          <?php else: ?>
            <div class="pozosSummary__value" style="font-size:18px"><?php echo $usedFallback ? 'Últimos registros' : ($fromSql !== '' ? h(date('d/m/Y H:i', strtotime($displayFromSql))) : 'Desde el primer registro'); ?></div>
            <div class="pozosSummary__hint"><?php echo $usedFallback ? 'No hubo datos en las últimas 24 hs. Se muestran los últimos registros disponibles.' : ($toSql !== '' ? ('Hasta ' . h(date('d/m/Y H:i', strtotime($displayToSql))) . '.') : 'Hasta el último registro disponible.'); ?></div>
          <?php endif; ?>
        </div>
      </div>

      <form class="pozosToolbar" method="get" action="<?php echo h($screenUrl); ?>" id="pozosAlarmasFilters">
        <?php if ($embedMode): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label class="allAlarmsSearch">
          <?php echo icon('map'); ?><span>ZONA</span>
          <select name="zona" onchange="this.form.submit()"><option value="">Todas</option><?php foreach($zones as $z): ?><option value="<?php echo h($z); ?>"<?php echo $zoneFilter===$z?' selected':''; ?>><?php echo h($z); ?></option><?php endforeach; ?></select>
        </label>
        <label class="allAlarmsSearch">
          <?php echo icon('search'); ?><span>PREFIJO</span>
          <input type="text" name="prefijo_pozo" value="<?php echo h($wellPrefix); ?>" placeholder="Ej. YPF.SC" autocomplete="off">
        </label>
        <label class="allAlarmsSearch">
          <?php echo icon('search'); ?><span>POZO</span>
          <input type="text" name="pozo" value="<?php echo h($wellSearch); ?>" placeholder="Buscar pozo aproximado…" autocomplete="off">
        </label>
        <label class="allAlarmsSearch allAlarmsSearch--wide">
          <?php echo icon('search'); ?><span>DESCRIPCIÓN</span>
          <input type="text" name="descripcion" value="<?php echo h($descSearch); ?>" placeholder="Buscar palabras en la descripción…" autocomplete="off">
        </label>
        <div class="pozosDateRange">
          <span class="pozosDateRange__label">Desde</span>
          <input type="date" name="desde" value="<?php echo h($fromDate); ?>" aria-label="Fecha desde">
          <input type="time" name="hora_desde" value="<?php echo h($fromTime); ?>" aria-label="Hora desde">
          <span class="pozosDateRange__label">Hasta</span>
          <input type="date" name="hasta" value="<?php echo h($toDate); ?>" aria-label="Fecha hasta">
          <input type="time" name="hora_hasta" value="<?php echo h($toTime); ?>" aria-label="Hora hasta">
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <button type="submit" class="toolbar__dateApply">Aplicar</button>
          <?php if ($hasFilters): ?><a class="btn-clear-filters" href="<?php echo h($screenUrl); ?><?php echo $embedMode ? '?embed=1' : ''; ?>">↺ Limpiar</a><?php endif; ?>
        </div>
      </form>

      <div class="pozosFilterSections">
        <section class="pozosFilterSection">
          <div class="pozosFilterSection__title">Conteo por prioridad<?php echo $historyMode ? ' · período seleccionado' : ' · 24 hs'; ?></div>
          <div class="pozosFilterChips">
            <?php if ($priorityCounts): ?>
              <?php foreach ($priorityCounts as $raw => $count): list($badgeColor,$badgeText)=pa24_badge_priority($raw); ?>
                <a class="pozosChip pozosChip--<?php echo h($badgeColor); ?> <?php echo strtoupper($priorityFilter)===strtoupper($raw)?'is-active':''; ?>" href="<?php echo h(pa24_filter_chip_href('priority', strtoupper($raw), strtoupper($priorityFilter))); ?>">
                  <span><?php echo h($badgeText); ?></span><span class="pozosChip__count"><?php echo number_format($count,0,',','.'); ?></span>
                </a>
              <?php endforeach; ?>
            <?php else: ?><div class="pozosFilterEmpty">Sin datos para el rango actual.</div><?php endif; ?>
          </div>
        </section>
        <section class="pozosFilterSection">
          <div class="pozosFilterSection__title">Conteo por estado<?php echo $historyMode ? ' · período seleccionado' : ' · 24 hs'; ?></div>
          <div class="pozosFilterChips">
            <?php if ($statusCounts): ?>
              <?php foreach ($statusCounts as $raw => $count): list($badgeColor,$badgeText)=pa24_badge_status($raw); ?>
                <a class="pozosChip pozosChip--<?php echo h($badgeColor); ?> <?php echo strtoupper($statusFilter)===strtoupper($raw)?'is-active':''; ?>" href="<?php echo h(pa24_filter_chip_href('status', strtoupper($raw), strtoupper($statusFilter))); ?>">
                  <span><?php echo h($badgeText); ?></span><span class="pozosChip__count"><?php echo number_format($count,0,',','.'); ?></span>
                </a>
              <?php endforeach; ?>
            <?php else: ?><div class="pozosFilterEmpty">Sin datos para el rango actual.</div><?php endif; ?>
          </div>
        </section>
        <section class="pozosFilterSection">
          <div class="pozosFilterSection__title">Conteo por valor<?php echo $historyMode ? ' · período seleccionado' : ' · 24 hs'; ?></div>
          <div class="pozosFilterChips">
            <?php foreach ($valueCounts as $bucket => $count):
              $valueColor = in_array($bucket, ['FALLA','PARADO'], true) ? 'red' : (in_array($bucket, ['MARCHA','NORMAL'], true) ? 'green' : (in_array($bucket, ['OPEN','CLOSE'], true) ? 'cyan' : 'gray')); ?>
              <a class="pozosChip pozosChip--<?php echo h($valueColor); ?> <?php echo strtoupper($valueBucketFilter)===strtoupper($bucket)?'is-active':''; ?>" href="<?php echo h(pa24_filter_chip_href('value', strtoupper($bucket), strtoupper($valueBucketFilter))); ?>">
                <span><?php echo h(ucfirst(strtolower($bucket))); ?></span><span class="pozosChip__count"><?php echo number_format($count,0,',','.'); ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

      <div class="allAlarmsActions">
        <div class="toolbar__count">
          <?php if ($total > 0): ?><?php if ($showAllRows): ?>Mostrando <b>todas las <?php echo number_format($total,0,',','.'); ?></b> filas<?php else: ?>Mostrando <b><?php echo number_format(min(($page-1)*$perPage+1,$total),0,',','.'); ?></b>–<b><?php echo number_format(min($page*$perPage,$total),0,',','.'); ?></b> de <b><?php echo number_format($total,0,',','.'); ?></b><?php endif; ?><?php else: ?>Sin resultados<?php endif; ?>
        </div>
        <div class="allAlarmsActionButtons">
          <label class="autoRefresh" for="pozosPerPage"><span>Filas</span>
            <select id="pozosPerPage" aria-label="Cantidad de filas por página" onchange="var u=new URL(window.location.href);u.searchParams.set('per_page',this.value);u.searchParams.delete('p');window.location.href=u.toString();">
              <?php foreach ($allowedPerPage as $size): ?><option value="<?php echo h($size); ?>" <?php echo $perPageParam === $size ? 'selected' : ''; ?>><?php echo $size === 'all' ? 'Todas' : h($size); ?></option><?php endforeach; ?>
            </select>
          </label>
          <button type="button" class="btn-export" id="columnChooserButton"><?php echo icon('grid'); ?> Columnas</button>
          <button type="button" class="btn-export" id="exportPozosExcel"><?php echo icon('download'); ?> Exportar Excel</button>
        </div>
      </div>

      <div class="columnChooser" id="columnChooser" hidden>
        <div class="columnChooser__head">
          <div><b>Mostrar u ocultar columnas</b><span>La selección queda guardada en este navegador.</span></div>
          <div><button type="button" id="showAllColumns">Mostrar todas</button><button type="button" id="hideOptionalColumns">Vista compacta</button></div>
        </div>
        <div class="columnChooser__grid">
          <?php foreach ($columns as $index => $column): ?>
            <label><input type="checkbox" data-column-toggle="<?php echo $index; ?>" checked> <?php echo h(pa24_column_label($column,$columnLabels)); ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="tablewrap allAlarmsTableWrap">
        <?php if (!$rows): ?>
          <div class="empty"><p>No se encontraron alarmas de pozos para el rango y filtros seleccionados.</p></div>
        <?php else: ?>
          <div class="tablescroll allAlarmsScroll">
            <table class="grid grid--sortable js-sortable allAlarmsTable" id="pozosAlarmasTable">
              <thead>
                <tr><?php foreach ($columns as $index => $column): ?><th data-column-index="<?php echo $index; ?>"><span><?php echo h(pa24_column_label($column,$columnLabels)); ?></span></th><?php endforeach; ?></tr>
                <tr class="pozosColumnFilterRow">
                  <?php foreach ($columns as $index => $column): ?>
                    <th class="no-sort" data-sortable="false" data-column-index="<?php echo $index; ?>">
                      <input class="pozosColumnFilter" type="text" name="cf[<?php echo $index; ?>]" form="pozosAlarmasFilters" value="<?php echo h($columnFilters[$index] ?? ''); ?>" placeholder="Filtrar…" aria-label="Filtrar columna <?php echo h(pa24_column_label($column,$columnLabels)); ?>" autocomplete="off">
                    </th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $row):
                $rowTag = trim((string)($row[$tagColumn] ?? ''));
                $rowTimestamp = trim((string)($row[$dateColumn] ?? ''));
                $rowValue = trim((string)($row[$valueColumn] ?? ''));
                $rowDescription = trim((string)($row[$descColumn] ?? ''));
              ?>
                <tr class="js-detail-row" tabindex="0" aria-label="Ver detalle de alarma de pozo">
                  <?php foreach ($columns as $index => $column): $value = $row[$column] ?? ''; $upperColumn = strtoupper((string)$column); ?>
                    <td data-column-index="<?php echo $index; ?>" data-label="<?php echo h($column); ?>" data-raw-value="<?php echo h((string)$value); ?>" title="<?php echo h((string)$value); ?>">
                      <?php if ($upperColumn === strtoupper($wellColumn) && trim((string)$value) !== ''): ?>
                        <?php echo clear_alarm_actions_cell([
                          'display' => $value,
                          'tag' => $rowTag,
                          'timestamp' => $rowTimestamp,
                          'value' => $rowValue,
                          'description' => $rowDescription,
                          'show_comment' => true,
                          'context' => $screenKey,
                        ]); ?>
                      <?php elseif ($upperColumn === strtoupper($tagColumn) && trim((string)$value) !== ''): ?>
                        <button type="button" class="tagLink" data-pi-tag="<?php echo h((string)$value); ?>" data-pi-query="<?php echo h(pa24_related_tag_query($value)); ?>" title="Ver tendencia en PI Histórico"><?php echo h((string)$value); ?></button>
                      <?php elseif ($upperColumn === strtoupper($statusColumn)): list($badgeColor,$badgeText)=pa24_badge_status($value); ?>
                        <span class="badge badge--<?php echo h($badgeColor); ?>"><?php echo h($badgeText); ?></span>
                      <?php elseif ($upperColumn === strtoupper($priorityColumn)): list($badgeColor,$badgeText)=pa24_badge_priority($value); ?>
                        <span class="badge badge--<?php echo h($badgeColor); ?>"><?php echo h($badgeText); ?></span>
                      <?php else: ?><?php echo h(pa24_value($value)); ?><?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($totalPages > 1): ?>
      <div class="pager">
        <div class="pager__info">Página <?php echo $page; ?> de <?php echo $totalPages; ?></div>
        <div class="pager__btns">
          <a class="pager__btn <?php echo $page<=1?'is-disabled':''; ?>" href="<?php echo h(pa24_url_with(['p'=>max(1,$page-1)])); ?>">‹</a>
          <?php $start=max(1,$page-2);$end=min($totalPages,$start+4);$start=max(1,$end-4);for($i=$start;$i<=$end;$i++): ?>
            <a class="pager__btn <?php echo $i===$page?'is-active':''; ?>" href="<?php echo h(pa24_url_with(['p'=>$i])); ?>"><?php echo $i; ?></a>
          <?php endfor; ?>
          <a class="pager__btn <?php echo $page>=$totalPages?'is-disabled':''; ?>" href="<?php echo h(pa24_url_with(['p'=>min($totalPages,$page+1)])); ?>">›</a>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>

<?php clear_alarm_actions_modal(); ?>

<div class="piModal__overlay" id="piModalOverlay" hidden></div>
<section class="piModal" id="piModal" aria-hidden="true" aria-labelledby="piModalTitle">
  <div class="piModal__head">
    <div><div class="piModal__eyebrow">Vista rápida</div><h2 id="piModalTitle">PI Histórico</h2><p class="piModal__sub">Tendencia y sugerencias de tags relacionadas.</p></div>
    <div class="piModal__actions"><a class="piModal__link" id="piModalOpenPage" href="pi_historico.php" target="_blank" rel="noopener">Abrir página completa</a><button type="button" class="piModal__close" id="piModalClose" aria-label="Cerrar PI Histórico">×</button></div>
  </div>
  <div class="piModal__body"><iframe id="piModalFrame" class="piModal__frame" src="about:blank" title="PI Histórico embebido" loading="lazy"></iframe></div>
</section>

<div class="detailDrawer__overlay" id="detailDrawerOverlay" hidden></div>
<aside class="detailDrawer" id="detailDrawer" aria-hidden="true" aria-labelledby="detailDrawerTitle">
  <div class="detailDrawer__head"><div><div class="detailDrawer__eyebrow">Detalle de alarma de pozo</div><h2 id="detailDrawerTitle">Pozo</h2></div><button type="button" class="detailDrawer__close" id="detailDrawerClose">×</button></div>
  <div class="detailDrawer__body" id="detailDrawerBody"></div>
</aside>
<script>
window.CLEAR_POZOS_ALARMAS_COLUMNS=<?php echo json_encode($columns,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
window.CLEAR_POZOS_ALARMAS_EXPORT_URL=<?php echo json_encode($exportUrl,JSON_UNESCAPED_SLASHES); ?>;
window.CLEAR_POZOS_ALARMAS_STORAGE_KEY=<?php echo json_encode($historyMode ? 'clear:pozosTodasAlarmas:visibleColumns:v1' : 'clear:pozosAlarmas24:visibleColumns:v3'); ?>;
</script>
<script src="assets/js/app.js?v=20260716-pozos3"></script>
<script src="assets/js/alarm_actions.js?v=20260826-central-1"></script>
<script src="assets/js/pozos_alarmas24.js?v=20260807-history1"></script>
</body>
</html>
