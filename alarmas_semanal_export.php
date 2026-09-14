<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/alarmas_semanal_common.php';

auth_require();
$type = strtoupper(trim((string)($_GET['tipo'] ?? 'P'))) === 'I' ? 'I' : 'P';
$unified = (string)($_GET['unificada'] ?? '') === '1';
$menuKey = $unified ? 'instalaciones_alarmas_semanal' : ($type === 'P' ? 'pozos_alarmas_semanal' : 'instalaciones_alarmas_semanal');
permissions_require_menu($menuKey);

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$week = as_selected_week($_GET, new DateTimeImmutable('now'));
$filters = as_build_filters($type, $_GET, $week);
if ($unified && $type === 'P') $filters['installation_type'] = 'POZO';
$db = clear_db();
if (!$db->ok()) { http_response_code(500); exit('Sin conexión a la base de datos.'); }

$columns = as_column_lookup($db);
$params = [];
$source = as_source_parts($type, $filters, $columns, $params);
if (!$source['ok']) { http_response_code(500); exit($source['error']); }

$limit = 50000;
$showTypeColumn = $unified || $type === 'I';
$select = ($showTypeColumn ? ($type === 'P' ? "N'POZO'" : $source['installation_type']) . " AS TIPO_INSTALACION," : '') .
          $source['date'] . " AS FECHA_HORA," .
          $source['entity'] . " AS ENTIDAD," .
          $source['tag'] . " AS TAG," .
          $source['description'] . " AS DESCRIPCION," .
          $source['status'] . " AS ESTADO," .
          $source['priority'] . " AS PRIORIDAD," .
          $source['value'] . " AS VALOR," .
          $source['unit'] . " AS UNIDAD";
$rows = $db->all("SELECT TOP " . ($limit + 1) . " $select FROM dbo.FIXALARMS " . $source['where'] . " ORDER BY " . $source['date'] . " DESC", $params);
$truncated = count($rows) > $limit;
if ($truncated) array_pop($rows);

audit_log('EXPORTACION', $menuKey, 'Exportación semanal ' . ($type==='P'?'Pozos':'Instalaciones') . ' · ' . count($rows) . ' registros');
$filename = 'CLEAR_Alarmas_Semanal_' . ($unified ? 'Unificadas_' : ($type === 'P' ? 'Pozos_' : 'Instalaciones_')) . $week['start']->format('Ymd') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
echo "\xEF\xBB\xBF";

function as_xls($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function as_xls_date($value) {
    if ($value instanceof DateTimeInterface) return $value->format('d/m/Y H:i:s');
    $timestamp = strtotime((string)$value);
    return $timestamp ? date('d/m/Y H:i:s', $timestamp) : (string)$value;
}
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;font-size:10pt;color:#15303a}h1{color:#1a4d5c}p{margin:4px 0 12px}table{border-collapse:collapse}th{background:#1a4d5c;color:#fff;font-weight:bold}th,td{border:1px solid #cbd7de;padding:5px;vertical-align:top}td{mso-number-format:'\@'}.warn{background:#fdf3e2;color:#9a5f0e;padding:8px;font-weight:bold}
</style></head><body>
<h1><?php echo $unified ? 'Alarmas semanal' : 'Alarmas semanal · '.($type==='P'?'Pozos':'Instalaciones'); ?></h1>
<p>Semana: <?php echo as_xls($week['start']->format('d/m/Y')); ?> al <?php echo as_xls($week['end']->format('d/m/Y')); ?></p>
<?php if($truncated): ?><p class="warn">La exportación se limitó a los primeros <?php echo number_format($limit,0,',','.'); ?> registros para proteger el servidor.</p><?php endif; ?>
<table><thead><tr><?php if($showTypeColumn): ?><th>TIPO DE INSTALACIÓN</th><?php endif; ?><th>FECHA Y HORA</th><th><?php echo $type==='P'?'POZO':'INSTALACIÓN'; ?></th><th>TAG</th><th>DESCRIPCIÓN</th><th>ESTADO</th><th>PRIORIDAD</th><th>VALOR</th><th>UNIDAD</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr>
<?php if($showTypeColumn): ?><td><?php echo as_xls(as_value($row,'TIPO_INSTALACION')); ?></td><?php endif; ?><td><?php echo as_xls(as_xls_date(as_value($row,'FECHA_HORA'))); ?></td><td><?php echo as_xls(as_value($row,'ENTIDAD')); ?></td><td><?php echo as_xls(as_value($row,'TAG')); ?></td><td><?php echo as_xls(as_value($row,'DESCRIPCION')); ?></td><td><?php echo as_xls(as_value($row,'ESTADO')); ?></td><td><?php echo as_xls(as_value($row,'PRIORIDAD')); ?></td><td><?php echo as_xls(as_value($row,'VALOR')); ?></td><td><?php echo as_xls(as_value($row,'UNIDAD')); ?></td>
</tr><?php endforeach; ?>
</tbody></table></body></html>
