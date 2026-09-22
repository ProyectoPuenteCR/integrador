<?php
/* =============================================================
   CLEAR PLATAFORMA — sin_telemetria_zafiro_export.php
   Exporta a Excel el listado visible en "Sin telemetría en Zafiro"
   con los mismos filtros de la pantalla (semana, zona, batería,
   telemetría, búsqueda y vista).
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/zafiro_sin_telemetria.php';

auth_require();
permissions_require_menu('sin_telemetria_zafiro');

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');

$db = clear_db();
if (!$db->ok()) { http_response_code(500); exit('Sin conexión a la base.'); }
if (!zst_ready($db)) { http_response_code(500); exit('Falta ejecutar SQL/CLEAR_ZAFIRO_SIN_TELEMETRIA_JOB.sql.'); }

$filters = zst_filters($_GET);
$data = zst_load($db, $filters);
if (!$data['ok']) { http_response_code(500); exit('No se pudo leer la información: ' . $data['error']); }

audit_log('EXPORTACION', 'sin_telemetria_zafiro', 'Exportación Excel pozos sin telemetría en Zafiro');

$views = ['sin' => 'Sin telemetría', 'nuevos' => 'Nuevos sin telemetría', 'normalizados' => 'Normalizados'];
$weekText = $data['weekStart'] ? ($data['weekStart']->format('d/m/Y') . ' al ' . $data['weekEnd']->format('d/m/Y')) : '';
$filename = 'CLEAR_Sin_Telemetria_Zafiro_' . ($data['weekStart'] ? $data['weekStart']->format('Ymd') : date('Ymd')) . '_' . date('His') . '.xls';

function zex($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
echo "\xEF\xBB\xBF";
?>
<html><head><meta charset="UTF-8"></head><body>
<table>
  <tr><td colspan="12"><b>Sin telemetría en Zafiro · <?php echo zex($views[$filters['view']]); ?></b></td></tr>
  <tr><td colspan="12">Semana: <?php echo zex($weekText); ?><?php if ($data['weekRun']): ?> · cierre <?php echo zex(zst_fmt_date($data['weekRun']['date'])); ?> · Zafiro <?php echo zex(zst_fmt_datetime($data['weekRun']['zafiro'])); ?><?php endif; ?></td></tr>
  <tr><td colspan="12">Filtros: zona <?php echo zex($filters['zone'] ?: 'Todas'); ?> · batería <?php echo zex($filters['battery'] ?: 'Todas'); ?> · telemetría <?php echo zex($filters['telemetry'] ?: 'Todas'); ?><?php if ($filters['q'] !== ''): ?> · búsqueda "<?php echo zex($filters['q']); ?>"<?php endif; ?></td></tr>
  <tr><td colspan="12"><b>Producción Bruta total:</b> <?php echo number_format((float)($data['productionLiquidTotal'] ?? 0), 2, ',', '.'); ?> · <b>Producción petróleo total:</b> <?php echo number_format((float)($data['productionOilTotal'] ?? 0), 2, ',', '.'); ?></td></tr>
  <tr><td colspan="12"></td></tr>
  <tr>
    <th>Pozo</th><th>Batería</th><th>Zona</th><th>Telemetría</th><th>Comunicación</th>
    <th>Sem. anterior</th><th>Estado actual</th><th>Semanas sin telemetría</th><th>Sin telemetría desde</th><th>Producción líquido</th><th>Producción petróleo</th><th>Observaciones</th>
  </tr>
  <?php foreach ($data['rows'] as $row):
    $prev = $row['prevStatus'] === 'without' ? 'Sin dato' : ($row['prevStatus'] === 'new' ? 'Nuevo' : '');
    $cur = $row['currentStatus'] === 'normalized' ? 'Con telemetría' : 'Sin dato';
  ?>
  <tr>
    <td><?php echo zex($row['well']); ?></td>
    <td><?php echo zex($row['battery']); ?></td>
    <td><?php echo zex($row['zone']); ?></td>
    <td><?php echo zex($row['telemetry']); ?></td>
    <td><?php echo zex($row['comm']); ?></td>
    <td><?php echo zex($prev); ?></td>
    <td><?php echo zex($cur); ?></td>
    <td><?php echo $row['weeksWithout'] === null ? '' : (int)$row['weeksWithout']; ?></td>
    <td><?php echo zex($row['firstSeen'] !== '' ? zst_fmt_date($row['firstSeen']) : ''); ?></td>
    <td><?php echo $row['productionLiquid'] === null ? '' : number_format((float)$row['productionLiquid'], 2, ',', '.'); ?></td>
    <td><?php echo $row['productionOil'] === null ? '' : number_format((float)$row['productionOil'], 2, ',', '.'); ?></td>
    <td><?php echo zex($row['notes']); ?></td>
  </tr>
  <?php endforeach; ?>
</table>
</body></html>
