<?php
/* =============================================================
   CLEAR PLATAFORMA — export.php
   Exporta a CSV la pantalla indicada, respetando búsqueda y orden.
   Uso: export.php?s=alarmas24h&q=...&sort=...&dir=...
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/screens.php';
require_once __DIR__ . '/includes/zones.php';
require_once __DIR__ . '/includes/installation_type.php';

auth_require();

$screens = clear_screens();
$key = $_GET['s'] ?? '';
if (!isset($screens[$key])) {
    http_response_code(404);
    echo 'Pantalla no encontrada';
    exit;
}
$sc = $screens[$key];
permissions_require_menu($key);
audit_log('EXPORTACION','export',$key);

$q          = trim($_GET['q'] ?? '');
$filterZone = trim($_GET['zona'] ?? '');
$filterInstallation = trim($_GET['instalacion'] ?? '');
$filterStatus       = trim($_GET['estado'] ?? '');
$filterPriority     = trim($_GET['prioridad'] ?? '');
$filterValue        = trim($_GET['valor'] ?? '');
$filterInstallationType = trim($_GET['tipo_instalacion'] ?? '');
$alarmScope = strtolower(trim((string)($_GET['scope'] ?? '')));
if (!in_array($alarmScope, ['instalaciones','pozos'], true)) $alarmScope = '';
$columnFiltersRaw = isset($_GET['col']) && is_array($_GET['col']) ? $_GET['col'] : [];
$filterFrom     = trim($_GET['fecha_desde'] ?? '');
$filterFromTime = trim($_GET['hora_desde'] ?? '');
$filterTo       = trim($_GET['fecha_hasta'] ?? '');
$filterToTime   = trim($_GET['hora_hasta'] ?? '');
$sortCol        = $_GET['sort'] ?? '';
$sortDir        = (strtolower($_GET['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';

$legacyDate = trim($_GET['fecha'] ?? '');
if ($filterFrom === '' && $filterTo === '' && $legacyDate !== '') {
    $filterFrom = $legacyDate;
    $filterTo = $legacyDate;
}

function clear_export_valid_iso_date($value) {
    if ($value === '') return '';
    $dt = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    $isValid = $dt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $dt->format('Y-m-d') === $value;
    return $isValid ? $value : '';
}

function clear_export_valid_hhmm_time($value) {
    if ($value === '') return '';
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) return '';
    return $value;
}

function clear_export_valid_installation($value) {
    $value = trim((string)$value);
    if ($value === '' || strlen($value) > 100) return '';
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) return '';
    return $value;
}

function clear_export_valid_combo_value($value) {
    $value = trim((string)$value);
    if ($value === '' || strlen($value) > 150) return '';
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) return '';
    return $value;
}

function clear_export_effective_from_datetime($date, $time) {
    if ($date === '') return null;
    return $date . ' ' . ($time !== '' ? $time : '00:00');
}

function clear_export_effective_to_datetime($date, $time) {
    if ($date === '') return null;
    return $date . ' ' . ($time !== '' ? $time : '00:00');
}

$filterInstallation = clear_export_valid_installation($filterInstallation);
$filterStatus       = clear_export_valid_combo_value($filterStatus);
$filterPriority     = clear_export_valid_combo_value($filterPriority);
$filterValue        = clear_export_valid_combo_value($filterValue);
$filterInstallationType = clear_installation_type_normalize($filterInstallationType);
$filterFrom = clear_export_valid_iso_date($filterFrom);
$filterTo   = clear_export_valid_iso_date($filterTo);
$filterFromTime = clear_export_valid_hhmm_time($filterFromTime);
$filterToTime   = clear_export_valid_hhmm_time($filterToTime);
if ($filterFrom !== '' && $filterFromTime === '') $filterFromTime = '00:00';
if ($filterTo !== '' && $filterToTime === '') $filterToTime = '00:00';
$fromComparable = clear_export_effective_from_datetime($filterFrom, $filterFromTime);
$toComparable   = clear_export_effective_to_datetime($filterTo, $filterToTime);
if ($fromComparable !== null && $toComparable !== null && $fromComparable > $toComparable) {
    $tmpDate = $filterFrom;
    $tmpTime = $filterFromTime;
    $filterFrom = $filterTo;
    $filterFromTime = $filterToTime;
    $filterTo = $tmpDate;
    $filterToTime = $tmpTime;
}

$db = clear_db();
$zones=clear_zones_all($db);
$filterZone=clear_zone_valid($filterZone,$zones);
if (!$db->ok()) {
    http_response_code(500);
    echo 'Sin conexión a la base.';
    exit;
}

$table = $sc['tabla'];
$validCols = array_map(function ($c) { return $c[0]; }, $sc['cols']);
$columnFilters = [];
if (!empty($sc['standard_filters'])) {
    foreach ($columnFiltersRaw as $field => $value) {
        if (!is_string($field) || !in_array($field, $validCols, true) || is_array($value)) continue;
        $cleanValue = clear_export_valid_combo_value((string)$value);
        if ($cleanValue !== '') $columnFilters[$field] = $cleanValue;
    }
}

// Detectar las columnas Estado y Prioridad igual que en list.php.
$statusField = $sc['estado_col'] ?? '';
$priorityField = $sc['prioridad_col'] ?? '';
foreach ($sc['cols'] as $columnConfig) {
    $columnField = $columnConfig[0] ?? '';
    $columnLabel = trim((string)($columnConfig[1] ?? ''));
    $columnType  = $columnConfig[2] ?? '';
    if ($statusField === '' && ($columnType === 'status' || strcasecmp($columnLabel, 'Estado') === 0)) {
        $statusField = $columnField;
    }
    if ($priorityField === '' && ($columnType === 'prio' || strcasecmp($columnLabel, 'Prioridad') === 0)) {
        $priorityField = $columnField;
    }
}
if (!in_array($statusField, $validCols, true)) $statusField = '';
if (!in_array($priorityField, $validCols, true)) $priorityField = '';
$valueField = $sc['valor_col'] ?? '';
if ($valueField === '') {
    foreach ($sc['cols'] as $columnConfig) {
        $field = $columnConfig[0] ?? '';
        $label = trim((string)($columnConfig[1] ?? ''));
        if (strcasecmp($label, 'Valor') === 0) { $valueField = $field; break; }
    }
}
if (!in_array($valueField, $validCols, true)) $valueField = '';

$tagField = $sc['tag_col'] ?? '';
$installationExpr = '';
$installationTypeEnabled = !empty($sc['installation_type']);
$installationTypeExpr = '';
$installationTypeExternalField = '';
if ($tagField !== '' && in_array($tagField, $validCols, true)) {
    $normalizedTagExpr = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$tagField])))";
    $installationExpr = "LEFT($normalizedTagExpr, CHARINDEX('_', $normalizedTagExpr + '_') - 1)";
    if ($installationTypeEnabled) {
        $externalField = trim((string)($sc['installation_type_external_col'] ?? ''));
        if ($externalField !== '' && preg_match('/^[A-Za-z0-9_]+$/', $externalField) && clear_installation_type_has_column($db, $table, $externalField)) {
            $installationTypeExternalField = $externalField;
        }
        $externalExpr = $installationTypeExternalField !== '' ? '[' . $installationTypeExternalField . ']' : "N''";
        $installationTypeExpr = clear_installation_type_sql('[' . $tagField . ']', $externalExpr);
    }
}

// WHERE combinado: búsqueda + instalación + estado + prioridad + rango de fechas.
$conditions = [];
$params = [];
if ($q !== '' && !empty($sc['buscar'])) {
    $likes = [];
    foreach ($sc['buscar'] as $col) {
        $likes[] = "[$col] LIKE ?";
        $params[] = '%' . $q . '%';
    }
    if ($likes) $conditions[] = '(' . implode(' OR ', $likes) . ')';
}

if ($filterZone !== '' && $installationExpr !== '') {
    $conditions[] = "EXISTS (SELECT 1 FROM [CLEAR].[ZONAS] Z WHERE LTRIM(RTRIM(Z.ZONA)) COLLATE DATABASE_DEFAULT=? COLLATE DATABASE_DEFAULT AND LTRIM(RTRIM(Z.BATERIA)) COLLATE DATABASE_DEFAULT=$installationExpr COLLATE DATABASE_DEFAULT)";
    $params[] = $filterZone;
}
if ($filterInstallation !== '' && $installationExpr !== '') {
    $conditions[] = "$installationExpr = ?";
    $params[] = $filterInstallation;
}
if ($alarmScope === 'instalaciones' && $installationTypeExpr !== '') {
    $conditions[] = "$installationTypeExpr <> N'POZO'";
} elseif ($alarmScope === 'pozos' && $installationTypeExpr !== '') {
    $conditions[] = "$installationTypeExpr = N'POZO'";
}
if ($filterInstallationType !== '' && $installationTypeExpr !== '') {
    $conditions[] = "$installationTypeExpr = ?";
    $params[] = $filterInstallationType;
}
foreach ($columnFilters as $columnField => $columnValue) {
    $conditions[] = "LTRIM(RTRIM(CONVERT(nvarchar(1000), [$columnField]))) LIKE ?";
    $params[] = '%' . $columnValue . '%';
}

if ($filterValue !== '' && $valueField !== '') {
    $conditions[] = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), [$valueField])))) = ?";
    $params[] = strtoupper($filterValue);
}

if ($filterStatus !== '' && $statusField !== '') {
    $conditions[] = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), [$statusField])))) = ?";
    $params[] = strtoupper($filterStatus);
}

if ($filterPriority !== '' && $priorityField !== '') {
    $normalizedPriorityFilter = strtoupper($filterPriority);
    if ($normalizedPriorityFilter === 'LOW') {
        $conditions[] = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), [$priorityField])))) IN ('LOW','BAJA')";
    } elseif ($normalizedPriorityFilter === 'CRITICAL') {
        $conditions[] = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), [$priorityField])))) IN ('CRITICAL','CRITICA','CRÍTICA')";
    } else {
        $conditions[] = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(255), [$priorityField])))) = ?";
        $params[] = $normalizedPriorityFilter;
    }
}

$dateField = $sc['fecha_col'] ?? '';
if ($dateField !== '') {
    if ($filterFrom !== '') {
        $conditions[] = "[$dateField] >= ?";
        $params[] = $filterFrom . ' ' . ($filterFromTime !== '' ? $filterFromTime : '00:00') . ':00';
    }
    if ($filterTo !== '') {
        $conditions[] = "[$dateField] < DATEADD(minute, 1, ?)";
        $params[] = $filterTo . ' ' . ($filterToTime !== '' ? $filterToTime : '00:00') . ':00';
    }
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ORDER BY
if ($sortCol === '__INSTALACION' && $alarmScope === 'instalaciones' && $installationExpr !== '') {
    $orderBy = "$installationExpr $sortDir";
} elseif ($sortCol === '__TIPO_INSTALACION' && $installationTypeExpr !== '') {
    $orderBy = "$installationTypeExpr $sortDir";
} elseif ($sortCol !== '' && in_array($sortCol, $validCols, true)) {
    $orderBy = "[$sortCol] $sortDir";
} else {
    $orderBy = $sc['orderby'];
}

// Traer TODO (sin paginar) para el export
$selectParts = [];
if ($installationTypeExpr !== '') $selectParts[] = "$installationTypeExpr AS [__TIPO_INSTALACION]";
if ($alarmScope === 'instalaciones' && $installationExpr !== '') $selectParts[] = "$installationExpr AS [__INSTALACION]";
$selectParts[] = '*';
$selectSql = implode(', ', $selectParts);
$sql = "SELECT $selectSql FROM $table $where ORDER BY $orderBy";
$rows = $db->all($sql, $params);

// Cabeceras HTTP para descarga
$dateSuffix = '';
if ($filterInstallation !== '') {
    $safeInstallation = preg_replace('/[^A-Za-z0-9-]+/', '_', $filterInstallation);
    $dateSuffix .= '_inst_' . trim($safeInstallation, '_');
}
if ($filterInstallationType !== '') {
    $safeType = preg_replace('/[^A-Za-z0-9-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filterInstallationType));
    $dateSuffix .= '_tipo_' . trim($safeType, '_');
}
if ($filterStatus !== '') {
    $safeStatus = preg_replace('/[^A-Za-z0-9-]+/', '_', $filterStatus);
    $dateSuffix .= '_estado_' . trim($safeStatus, '_');
}
if ($filterPriority !== '') {
    $safePriority = preg_replace('/[^A-Za-z0-9-]+/', '_', $filterPriority);
    $dateSuffix .= '_prioridad_' . trim($safePriority, '_');
}
if ($filterValue !== '') {
    $safeValue = preg_replace('/[^A-Za-z0-9-]+/', '_', $filterValue);
    $dateSuffix .= '_valor_' . trim($safeValue, '_');
}
if ($filterFrom !== '') {
    $dateSuffix .= '_desde_' . str_replace('-', '', $filterFrom);
    if ($filterFromTime !== '') $dateSuffix .= '_' . str_replace(':', '', $filterFromTime);
}
if ($filterTo !== '') {
    $dateSuffix .= '_hasta_' . str_replace('-', '', $filterTo);
    if ($filterToTime !== '') $dateSuffix .= '_' . str_replace(':', '', $filterToTime);
}
$filename = 'CLEAR_' . $key . $dateSuffix . '_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// BOM para que Excel reconozca UTF-8
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Encabezados y columnas visibles: en superficie usamos la instalación real
// derivada del TAG y ocultamos el campo externo que identifica pozos.
$exportCols = $sc['cols'];
if ($alarmScope === 'instalaciones' && $installationExpr !== '') {
    $filteredCols = [];
    foreach ($exportCols as $exportCol) {
        if ($installationTypeExternalField !== '' && (string)($exportCol[0] ?? '') === $installationTypeExternalField) continue;
        $filteredCols[] = $exportCol;
    }
    $exportCols = $filteredCols;
    array_unshift($exportCols, ['__INSTALACION','Instalación','text']);
}
$headers = array_map(function ($c) { return $c[1]; }, $exportCols);
if ($installationTypeExpr !== '') array_unshift($headers, 'Tipo de instalación');
fputcsv($out, $headers, ';');

foreach ($rows as $row) {
    $line = [];
    if ($installationTypeExpr !== '') $line[] = $row['__TIPO_INSTALACION'] ?? 'SIN CLASIFICAR';
    foreach ($exportCols as $exportCol) {
        $field = $exportCol[0];
        $line[] = isset($row[$field]) ? $row[$field] : '';
    }
    fputcsv($out, $line, ';');
}

fclose($out);
exit;
