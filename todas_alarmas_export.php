<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
auth_require(); permissions_require_menu('todas_alarmas'); audit_log('EXPORTACION','todas_alarmas','Exportación Excel');

$db = clear_db();
if (!$db->ok()) { http_response_code(500); exit('Sin conexión a la base.'); }
$table = 'dbo.FIXALARMS';

function xls_clean($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function valid_date($v) { $d = DateTime::createFromFormat('!Y-m-d', $v); return $d && $d->format('Y-m-d') === $v ? $v : ''; }
function valid_time($v) { return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : ''; }
function terms($v) { return array_values(array_filter(preg_split('/\s+/', trim((string)$v)))); }

$metaRows = $db->all("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='FIXALARMS' ORDER BY ORDINAL_POSITION");
$allColumns = [];
foreach ($metaRows as $r) { $c = trim((string)($r['COLUMN_NAME'] ?? $r['column_name'] ?? reset($r))); if ($c !== '') $allColumns[] = $c; }
if (!$allColumns) { $sample = $db->all("SELECT TOP 1 * FROM $table"); if ($sample) $allColumns = array_keys($sample[0]); }

$requested = isset($_GET['columns']) ? explode(',', (string)$_GET['columns']) : [];
$columns = array_values(array_filter($requested, function ($c) use ($allColumns) { return in_array($c, $allColumns, true); }));
if (!$columns) $columns = $allColumns;

$lookup = array_change_key_case(array_combine($allColumns, $allColumns), CASE_UPPER);
$dateColumn = $lookup['ALM_NATIVETIMEIN'] ?? ($lookup['ALM_NATIVETIMELAST'] ?? 'ALM_NATIVETIMEIN');
$tagColumn = $lookup['ALM_TAGNAME'] ?? ($lookup['TAG_FIX'] ?? 'ALM_TAGNAME');
$descColumns = [];
$msgTypeColumn = $lookup['ALM_MSGTYPE'] ?? 'ALM_MSGTYPE';
$physNodeColumn = $lookup['ALM_PHYSNODE'] ?? ($lookup['ALM_PHYSLNODE'] ?? 'ALM_PHYSNODE');
foreach (['ALM_TAGDESC','ALM_DESCR','DESCRIPCION','DESCRIPCIÓN'] as $c) if (isset($lookup[$c])) $descColumns[] = $lookup[$c];

$tag = trim((string)($_GET['tag'] ?? ''));
$desc = trim((string)($_GET['descripcion'] ?? ''));
$from = valid_date(trim((string)($_GET['fecha_desde'] ?? '')));
$to = valid_date(trim((string)($_GET['fecha_hasta'] ?? '')));
$fromTime = valid_time(trim((string)($_GET['hora_desde'] ?? ''))) ?: '00:00';
$toTime = valid_time(trim((string)($_GET['hora_hasta'] ?? ''))) ?: '23:59';
$msgType = strtoupper(trim((string)($_GET['msgtype'] ?? '')));
if ($msgType === '__ALL__' || !in_array($msgType, ['OPERATOR','TEXT','ALARM','NETWORK'], true)) $msgType = '';
$physNode = trim((string)($_GET['physnode'] ?? ''));
if ($physNode === '__ALL__') $physNode = '';
$conditions = []; $params = [];
foreach (terms($tag) as $term) {
    $conditions[] = "(CONVERT(nvarchar(4000), [$tagColumn]) LIKE ? OR REPLACE(REPLACE(REPLACE(CONVERT(nvarchar(4000), [$tagColumn]),'_',''),'-',''),' ','') LIKE ?)";
    $params[] = '%' . $term . '%'; $params[] = '%' . str_replace(['_','-',' '], '', $term) . '%';
}
foreach (terms($desc) as $term) {
    if (!$descColumns) break;
    $or = [];
    foreach ($descColumns as $col) { $or[] = "CONVERT(nvarchar(4000), [$col]) LIKE ?"; $params[] = '%' . $term . '%'; }
    $conditions[] = '(' . implode(' OR ', $or) . ')';
}
if ($msgType !== '' && in_array($msgTypeColumn, $allColumns, true)) { $conditions[] = "UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100), [$msgTypeColumn])))) = ?"; $params[] = $msgType; }
if ($physNode !== '' && in_array($physNodeColumn, $allColumns, true)) { $conditions[] = "LTRIM(RTRIM(CONVERT(nvarchar(255), [$physNodeColumn]))) = ?"; $params[] = $physNode; }
if ($from !== '') { $conditions[] = "[$dateColumn] >= ?"; $params[] = $from . ' ' . $fromTime . ':00'; }
if ($to !== '') { $conditions[] = "[$dateColumn] <= ?"; $params[] = $to . ' ' . $toTime . ':59.997'; }
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$selectColumns = implode(', ', array_map(function ($c) { return '[' . str_replace(']', ']]', $c) . ']'; }, $columns));
$rows = $db->all("SELECT $selectColumns FROM $table $where ORDER BY [$dateColumn] DESC", $params);

$filename = 'CLEAR_Todas_Las_Alarmas_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>table{border-collapse:collapse;font-family:Arial;font-size:10pt}th{background:#1a4d5c;color:white;font-weight:bold}th,td{border:1px solid #cbd7de;padding:5px;vertical-align:top}td{mso-number-format:'\@'}</style></head><body>
<table><thead><tr><?php foreach ($columns as $column): ?><th><?php echo xls_clean($column); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><?php foreach ($columns as $column): ?><td><?php echo xls_clean($row[$column] ?? ''); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></body></html>
