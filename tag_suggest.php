<?php
/* =============================================================
   CLEAR PLATAFORMA — tag_suggest.php
   Autocompletado dinámico de TAG para las vistas de list.php.
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/screens.php';

auth_require();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function respond_tags($ok, $tags = [], $error = '') {
    echo json_encode([
        'ok' => (bool)$ok,
        'tags' => array_values($tags),
        'error' => $error,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function valid_date_value($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    $dt = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    $valid = $dt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $dt->format('Y-m-d') === $value;
    return $valid ? $value : '';
}

function valid_time_value($value) {
    $value = trim((string)$value);
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : '';
}

function valid_combo_value($value, $maxLength = 150) {
    $value = trim((string)$value);
    if ($value === '' || strlen($value) > $maxLength) return '';
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) return '';
    return $value;
}

$screens = clear_screens();
$key = trim((string)($_GET['s'] ?? ''));
$term = trim((string)($_GET['term'] ?? ''));

if (!isset($screens[$key])) respond_tags(false, [], 'Pantalla no encontrada.');
if (strlen($term) < 2) respond_tags(true, []);
if (strlen($term) > 120) $term = substr($term, 0, 120);

$sc = $screens[$key];
$tagField = $sc['tag_col'] ?? '';
if ($tagField === '') respond_tags(true, []);

$validCols = array_map(function ($c) { return $c[0]; }, $sc['cols']);
if (!in_array($tagField, $validCols, true)) respond_tags(true, []);

$statusField = $sc['estado_col'] ?? '';
$priorityField = $sc['prioridad_col'] ?? '';
foreach ($sc['cols'] as $columnConfig) {
    $field = $columnConfig[0] ?? '';
    $label = trim((string)($columnConfig[1] ?? ''));
    $type = $columnConfig[2] ?? '';
    if ($statusField === '' && ($type === 'status' || strcasecmp($label, 'Estado') === 0)) $statusField = $field;
    if ($priorityField === '' && ($type === 'prio' || strcasecmp($label, 'Prioridad') === 0)) $priorityField = $field;
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

$filterInstallation = valid_combo_value($_GET['instalacion'] ?? '', 100);
$filterStatus = valid_combo_value($_GET['estado'] ?? '');
$filterPriority = valid_combo_value($_GET['prioridad'] ?? '');
$filterValue = valid_combo_value($_GET['valor'] ?? '');
$filterFrom = valid_date_value($_GET['fecha_desde'] ?? '');
$filterTo = valid_date_value($_GET['fecha_hasta'] ?? '');
$filterFromTime = valid_time_value($_GET['hora_desde'] ?? '');
$filterToTime = valid_time_value($_GET['hora_hasta'] ?? '');
if ($filterFrom !== '' && $filterFromTime === '') $filterFromTime = '00:00';
if ($filterTo !== '' && $filterToTime === '') $filterToTime = '00:00';

$db = clear_db();
if (!$db->ok()) respond_tags(false, [], 'Sin conexión a la base.');

$table = $sc['tabla'];
$dateField = $sc['fecha_col'] ?? '';
$normalizedTag = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$tagField])))";
$installationExpr = "LEFT($normalizedTag, CHARINDEX('_', $normalizedTag + '_') - 1)";

$conditions = ["[$tagField] IS NOT NULL", "$normalizedTag <> ''", "$normalizedTag LIKE ?"];
$params = ['%' . $term . '%'];

if ($filterInstallation !== '') {
    $conditions[] = "$installationExpr = ?";
    $params[] = $filterInstallation;
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
if ($dateField !== '') {
    if ($filterFrom !== '') {
        $conditions[] = "[$dateField] >= ?";
        $params[] = $filterFrom . ' ' . $filterFromTime . ':00';
    }
    if ($filterTo !== '') {
        $conditions[] = "[$dateField] < DATEADD(minute, 1, ?)";
        $params[] = $filterTo . ' ' . $filterToTime . ':00';
    }
}

$where = 'WHERE ' . implode(' AND ', $conditions);
$params[] = $term . '%';
$sql = "SELECT TOP 20 tag_sugerido FROM (" .
       "SELECT DISTINCT $normalizedTag AS tag_sugerido FROM $table $where" .
       ") AS tags_unicos ORDER BY CASE WHEN tag_sugerido LIKE ? THEN 0 ELSE 1 END, tag_sugerido";

$rows = $db->all($sql, $params);
$tags = [];
foreach ($rows as $row) {
    $values = array_values($row);
    $tag = trim((string)($row['tag_sugerido'] ?? $row['TAG_SUGERIDO'] ?? ($values[0] ?? '')));
    if ($tag !== '') $tags[] = $tag;
}
respond_tags(true, $tags);
