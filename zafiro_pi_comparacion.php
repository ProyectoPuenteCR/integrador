<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/novedades_semanales_common.php';
require_once __DIR__ . '/includes/zafiro_pi_comparacion.php';

$MENU_KEY = 'zafiro_pi_comparacion';
auth_require();
permissions_require_menu($MENU_KEY);

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$APP_USER = auth_user() ?: ($cfg['app']['user'] ?? 'CLEAR01');
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = $MENU_KEY;
$db = clear_db();
$dbError = $db->ok() ? '' : $db->error();
$data = !$dbError ? zpc_load($db) : ['ok'=>false,'error'=>$dbError,'rows'=>[],'methods'=>[]];
$reportEnabled = !$dbError && ns_report_ready($db) && permissions_can_menu('novedades_semanales_reporte');
if (!$data['ok'] && !$dbError) $dbError = $data['error'];

$selectedMethods = $data['ok'] ? zpc_selected_methods($data['methods']) : [];
$selectedStates = $data['ok'] ? zpc_selected_states($data['states']) : [];
$selectedZones = $data['ok'] ? zpc_selected_zones($data['rows']) : [];
$excludedWells = zpc_excluded_wells();
$rowsAllSelected = $data['ok'] ? zpc_apply_filters($data['rows'], $selectedMethods, $selectedStates, $selectedZones, [], ['pi'=>'all','ver_excluidos'=>1]) : [];
$foundCount = 0; $missingCount = 0;
foreach ($rowsAllSelected as $r) { if ($r['enPi']) $foundCount++; else $missingCount++; }
$excludedMethodCount = 0;
if ($data['ok']) {
    $selectedMap = array_flip(array_map('zpc_method_key', $selectedMethods));
    foreach ($data['methods'] as $method=>$count) if (!isset($selectedMap[zpc_method_key($method)])) $excludedMethodCount += $count;
}
$rows = $data['ok'] ? zpc_apply_filters($data['rows'], $selectedMethods, $selectedStates, $selectedZones, $excludedWells, $_GET) : [];
$productionOilTotal = 0.0;
$productionLiquidTotal = 0.0;
foreach ($rows as $rowProduction) {
    if ($rowProduction['petroleo'] !== null) $productionOilTotal += (float)$rowProduction['petroleo'];
    if ($rowProduction['liquido'] !== null) $productionLiquidTotal += (float)$rowProduction['liquido'];
}
$zones = $data['ok'] ? zpc_unique($data['rows'], 'zona') : [];
$batteries = $data['ok'] ? zpc_unique($data['rows'], 'instalacion') : [];
$states = $data['ok'] ? zpc_unique($data['rows'], 'estado') : [];
$selectedMap = array_flip(array_map('zpc_method_key', $selectedMethods));
$selectedStateMap = array_flip(array_map('zpc_method_key', $selectedStates));
$selectedZoneMap = array_flip(array_map('zpc_method_key', $selectedZones));
$zoneCounts = [];
foreach ($data['rows'] as $zr) {
    $zv = trim((string)($zr['zona'] ?? ''));
    if ($zv === '') $zv = 'Sin zona';
    if (!isset($zoneCounts[$zv])) $zoneCounts[$zv] = 0;
    $zoneCounts[$zv]++;
}
arsort($zoneCounts);
$excludedMap = [];
foreach ($excludedWells as $w) $excludedMap[zpc_norm_pozo($w)] = true;

function zpc_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function zpc_n($v){ return number_format((int)$v,0,',','.'); }
function zpc_dt($v){
    if(!$v) return '—';
    $t=strtotime((string)$v);
    return $t?date('d/m/Y H:i',$t):(string)$v;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zafiro vs PI · CLEAR Plataforma</title>
<link rel="stylesheet" href="assets/css/app.css?v=20261005-zpc1">
<link rel="stylesheet" href="assets/css/sin_telemetria_zafiro.css?v=20260922-2">
<link rel="stylesheet" href="assets/css/zafiro_pi_comparacion.css?v=20261007-1">
<?php if($reportEnabled): ?><link rel="stylesheet" href="assets/css/novedades_semanales.css?v=20260826-report-common-1"><?php endif; ?>
</head>
<body>
<div class="app">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
<?php include __DIR__ . '/includes/topbar.php'; ?>

<header class="zpcHead">
  <div class="zpcHead__title">
    <span class="zpcHead__icon"><?php echo icon('signal-off'); ?></span>
    <div>
      <h1>Pozos en Zafiro con método y sin registro en PI</h1>
      <p>Compara los métodos configurados en Zafiro Q412 contra los pozos disponibles en la grilla general de telemetría proveniente de PI.</p>
    </div>
  </div>
  <?php if($data['ok']): ?>
  <div class="zpcSync">
    <?php echo icon('calendar'); ?>
    <div><span>Q412</span><b><?php echo zpc_h(zpc_dt($data['latestQ412'])); ?></b></div>
    <div><span>PI / grilla</span><b><?php echo zpc_h(zpc_dt($data['latestPI'])); ?></b></div>
  </div>
  <?php endif; ?>
</header>

<?php if($dbError): ?>
<div class="zstNotice is-error"><b>No se puede construir la comparación:</b> <?php echo zpc_h($dbError); ?></div>
<?php else: ?>

<div class="zpcLayout">
  <div class="zpcMainColumn">
<div class="zpcTop">
  <form class="zpcFilters" method="get" action="zafiro_pi_comparacion.php">
    <div class="zpcFilters__title">Filtros de análisis</div>
    <label><span>Zona</span><select name="zona"><option value="">Todas</option><?php foreach($zones as $v): ?><option value="<?php echo zpc_h($v); ?>" <?php echo (($_GET['zona']??'')===$v)?'selected':''; ?>><?php echo zpc_h($v); ?></option><?php endforeach; ?></select></label>
    <label><span>Batería / instalación</span><select name="bateria"><option value="">Todas</option><?php foreach($batteries as $v): ?><option value="<?php echo zpc_h($v); ?>" <?php echo (($_GET['bateria']??'')===$v)?'selected':''; ?>><?php echo zpc_h($v); ?></option><?php endforeach; ?></select></label>
    <label><span>Estado Zafiro</span><select name="estado"><option value="">Todos</option><?php foreach($states as $v): ?><option value="<?php echo zpc_h($v); ?>" <?php echo (($_GET['estado']??'')===$v)?'selected':''; ?>><?php echo zpc_h($v); ?></option><?php endforeach; ?></select></label>
    <label><span>Disponibilidad PI</span><select name="pi">
      <option value="missing" <?php echo (($_GET['pi']??'missing')==='missing')?'selected':''; ?>>No encontrados en PI</option>
      <option value="found" <?php echo (($_GET['pi']??'')==='found')?'selected':''; ?>>Encontrados en PI</option>
      <option value="all" <?php echo (($_GET['pi']??'')==='all')?'selected':''; ?>>Todos</option>
    </select></label>
    <label class="zpcFilters__search"><span>Buscar pozo</span><input type="search" name="q" value="<?php echo zpc_h($_GET['q']??''); ?>" placeholder="Código de pozo o batería"></label>
    <label class="zpcCheckLine"><input type="checkbox" name="ver_excluidos" value="1" <?php echo !empty($_GET['ver_excluidos'])?'checked':''; ?>> Mostrar pozos excluidos</label>
    <div class="zpcFilters__actions">
      <button class="zstBtn is-primary" type="submit"><?php echo icon('search'); ?> Aplicar</button>
      <a class="zstBtn is-ghost" href="zafiro_pi_comparacion.php">Limpiar</a>
      <a class="zstBtn is-ghost" href="zafiro_pi_comparacion_export.php?<?php echo zpc_h(http_build_query($_GET)); ?>"><?php echo icon('download'); ?> Exportar Excel</a>
      <?php if($reportEnabled): ?>
        <button type="button" class="zstBtn is-ghost" data-clear-report-add-selected><?php echo icon('file'); ?> Generar reporte</button>
        <button type="button" class="zstBtn is-ghost" data-ns-report-open>Ver reporte <span class="nsReportCount" data-ns-report-count hidden>0</span></button>
      <?php endif; ?>
    </div>
  </form>

</div>


<section class="zpcKpis">
  <div class="zpcKpi is-blue"><span><?php echo icon('grid'); ?></span><div><small>Pozos evaluados</small><b><?php echo zpc_n(count($rowsAllSelected)); ?></b><em>Con métodos seleccionados</em></div></div>
  <div class="zpcKpi is-green"><span><?php echo icon('check'); ?></span><div><small>Encontrados en PI</small><b><?php echo zpc_n($foundCount); ?></b><em><?php echo count($rowsAllSelected)?number_format($foundCount*100/count($rowsAllSelected),1,',','.'):'0,0'; ?> %</em></div></div>
  <div class="zpcKpi is-red"><span><?php echo icon('signal-off'); ?></span><div><small>No encontrados en PI</small><b><?php echo zpc_n($missingCount); ?></b><em><?php echo count($rowsAllSelected)?number_format($missingCount*100/count($rowsAllSelected),1,',','.'):'0,0'; ?> %</em></div></div>
  <div class="zpcKpi is-amber"><span><?php echo icon('history'); ?></span><div><small>Métodos excluidos</small><b><?php echo zpc_n($excludedMethodCount); ?></b><em>Pozos fuera del análisis</em></div></div>
  <div class="zpcKpi is-oil"><span><?php echo icon('oil'); ?></span><div><small>Producción neta</small><b><?php echo number_format($productionOilTotal,2,',','.'); ?></b><em>Petróleo · según filtros aplicados</em></div></div>
  <div class="zpcKpi is-liquid"><span><?php echo icon('gauge'); ?></span><div><small>Producción bruta</small><b><?php echo number_format($productionLiquidTotal,2,',','.'); ?></b><em>Líquido · según filtros aplicados</em></div></div>
</section>

<div class="zpcInfo"><?php echo icon('gauge'); ?><span><b>Regla:</b> solo se comparan contra PI los pozos cuyos <b>métodos, estados Zafiro y zonas</b> están seleccionados. Por defecto <b>No Posee</b> queda desmarcado. Métodos, estados, zonas y exclusiones por pozo se guardan por usuario.</span></div>

<section class="zpcGridCard">
  <div class="zpcGridHead">
    <h2><?php echo icon('grid'); ?> Pozos Zafiro y disponibilidad en PI</h2>
    <div>Mostrando <b><?php echo zpc_n(count($rows)); ?></b> registros</div>
  </div>
  <div class="zpcTableScroll">
    <table class="zpcTable" id="zpcTable">
      <thead>
        <tr>
          <?php if($reportEnabled): ?><th class="zpcReportPick"><input type="checkbox" data-ns-report-select-all aria-label="Seleccionar todos para reporte"></th><?php endif; ?>
          <th>Pozo</th><th>Instalación</th><th>Zona</th><th>Método Zafiro</th><th>Estado Zafiro</th>
          <th>En PI</th><th>Fuente PI / Sistema</th><th>Última telemetría</th><th>Producción petróleo</th><th>Producción líquido</th><th>Excluir</th>
        </tr>
        <tr class="zpcTable__filters">
          <?php if($reportEnabled): ?><th></th><?php endif; ?>
          <th><input type="search" data-zpc-col="0" placeholder="Filtrar…"></th>
          <th><select data-zpc-col="1"><option value="">Todas</option><?php foreach($batteries as $v): ?><option value="<?php echo zpc_h($v); ?>"><?php echo zpc_h($v); ?></option><?php endforeach; ?></select></th>
          <th><select data-zpc-col="2"><option value="">Todas</option><?php foreach($zones as $v): ?><option value="<?php echo zpc_h($v); ?>"><?php echo zpc_h($v); ?></option><?php endforeach; ?></select></th>
          <?php for($i=3;$i<10;$i++): ?><th><input type="search" data-zpc-col="<?php echo $i; ?>"></th><?php endfor; ?>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if(!$rows): ?>
        <tr><td colspan="<?php echo $reportEnabled?12:11; ?>" class="zpcEmpty">No hay pozos para los filtros y métodos seleccionados.</td></tr>
      <?php else: foreach($rows as $r): ?>
        <tr class="<?php echo $r['excluded']?'is-excluded':''; ?>">
          <?php if($reportEnabled):
            $reportColumns=[
              'Pozo'=>$r['pozo'],
              'Instalación'=>$r['instalacion']!==''?$r['instalacion']:'—',
              'Zona'=>$r['zona']!==''?$r['zona']:'—',
              'Método Zafiro'=>$r['metodo'],
              'Estado Zafiro'=>$r['estado']!==''?$r['estado']:'—',
              'En PI'=>$r['enPi']?'Encontrado en PI':'NO ENCONTRADO EN PI',
              'Fuente PI / Sistema'=>$r['piSource']!==''?$r['piSource']:'—',
              'Última telemetría'=>zpc_dt($r['piLast']),
              'Producción petróleo'=>$r['petroleo']===null?'—':number_format($r['petroleo'],2,',','.'),
              'Producción líquido'=>$r['liquido']===null?'—':number_format($r['liquido'],2,',','.')
            ];
          ?>
          <td class="zpcReportPick"><?php echo ns_report_pick(
            ns_report_key('zafiro-vs-pi',[$r['key'],$r['metodo'],$r['enPi']]),
            'row',
            'Zafiro vs PI · '.$r['pozo'],
            ns_report_row_payload($reportColumns),
            'Incluir'
          ); ?></td>
          <?php endif; ?>
          <td><b><?php echo zpc_h($r['pozo']); ?></b></td>
          <td><?php echo zpc_h($r['instalacion']!==''?$r['instalacion']:'—'); ?></td>
          <td><?php echo zpc_h($r['zona']!==''?$r['zona']:'—'); ?></td>
          <td><?php echo zpc_h($r['metodo']); ?></td>
          <td><span class="zpcBadge <?php echo $r['activo']?'is-green':'is-muted'; ?>"><?php echo zpc_h($r['estado']!==''?$r['estado']:'—'); ?></span></td>
          <td><span class="zpcBadge <?php echo $r['enPi']?'is-green':'is-red'; ?>"><?php echo $r['enPi']?'Encontrado en PI':'NO ENCONTRADO EN PI'; ?></span></td>
          <td><?php echo zpc_h($r['piSource']!==''?$r['piSource']:'—'); ?></td>
          <td><?php echo zpc_h(zpc_dt($r['piLast'])); ?></td>
          <td class="zpcMono"><?php echo $r['petroleo']===null?'—':number_format($r['petroleo'],2,',','.'); ?></td>
          <td class="zpcMono"><?php echo $r['liquido']===null?'—':number_format($r['liquido'],2,',','.'); ?></td>
          <td class="zpcExclude"><label class="zpcSwitch" title="Excluir este pozo del análisis"><input type="checkbox" data-zpc-exclude value="<?php echo zpc_h($r['pozo']); ?>" <?php echo isset($excludedMap[$r['key']])?'checked':''; ?>><span></span></label></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>
  </div>
  <aside class="zpcSideColumn">
  <div class="zpcComparePanels">
  <section class="zpcMethods" data-zpc-pref-panel data-zpc-pref-key="zafiro_pi_metodos">
    <div class="zpcMethods__title">Métodos Zafiro a comparar</div>
    <?php foreach($data['methods'] as $method=>$count): $checked=isset($selectedMap[zpc_method_key($method)]); ?>
    <label class="zpcMethod">
      <input type="checkbox" value="<?php echo zpc_h($method); ?>" <?php echo $checked?'checked':''; ?>>
      <span><?php echo zpc_h($method); ?></span>
      <b><?php echo zpc_n($count); ?></b>
    </label>
    <?php endforeach; ?>
    <div class="zpcMethods__actions">
      <button type="button" class="zstBtn is-ghost" data-zpc-all>Seleccionar todos</button>
      <button type="button" class="zstBtn is-ghost" data-zpc-none>Limpiar</button>
      <button type="button" class="zstBtn is-primary" data-zpc-save><?php echo icon('check'); ?> Guardar configuración</button>
    </div>
    <small><?php echo zpc_n(count($excludedWells)); ?> pozo(s) excluido(s) manualmente por este usuario.</small>
  </section>

  <section class="zpcMethods" data-zpc-pref-panel data-zpc-pref-key="zafiro_pi_estados">
    <div class="zpcMethods__title">Estados Zafiro a comparar</div>
    <?php foreach($data['states'] as $state=>$count): $checked=isset($selectedStateMap[zpc_method_key($state)]); ?>
    <label class="zpcMethod">
      <input type="checkbox" value="<?php echo zpc_h($state); ?>" <?php echo $checked?'checked':''; ?>>
      <span><?php echo zpc_h($state); ?></span>
      <b><?php echo zpc_n($count); ?></b>
    </label>
    <?php endforeach; ?>
    <div class="zpcMethods__actions">
      <button type="button" class="zstBtn is-ghost" data-zpc-all>Seleccionar todos</button>
      <button type="button" class="zstBtn is-ghost" data-zpc-none>Limpiar</button>
      <button type="button" class="zstBtn is-primary" data-zpc-save><?php echo icon('check'); ?> Guardar estados</button>
    </div>
    <small>Los estados destildados quedan fuera de la comparación con PI para tu usuario.</small>
  </section>

  <section class="zpcMethods" data-zpc-pref-panel data-zpc-pref-key="zafiro_pi_zonas">
    <div class="zpcMethods__title">Zonas a incluir en el análisis</div>
    <?php foreach($zoneCounts as $zone=>$count): $checked=isset($selectedZoneMap[zpc_method_key($zone)]); ?>
    <label class="zpcMethod">
      <input type="checkbox" value="<?php echo zpc_h($zone); ?>" <?php echo $checked?'checked':''; ?>>
      <span><?php echo zpc_h($zone); ?></span>
      <b><?php echo zpc_n($count); ?></b>
    </label>
    <?php endforeach; ?>
    <div class="zpcMethods__actions">
      <button type="button" class="zstBtn is-ghost" data-zpc-all>Seleccionar todas</button>
      <button type="button" class="zstBtn is-ghost" data-zpc-none>Limpiar</button>
      <button type="button" class="zstBtn is-primary" data-zpc-save><?php echo icon('check'); ?> Guardar zonas</button>
    </div>
    <small>Las zonas destildadas no se muestran ni participan de la comparación con PI para tu usuario.</small>
  </section>
  </div>
  </aside>
</div>
<?php endif; ?>
</main>
</div>
<script>
window.CLEAR_ZPC={
  selectedMethods:<?php echo json_encode(array_values($selectedMethods),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>,
  selectedStates:<?php echo json_encode(array_values($selectedStates),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>,
  selectedZones:<?php echo json_encode(array_values($selectedZones),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>,
  excludedWells:<?php echo json_encode(array_values($excludedWells),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>
};
</script>
<script src="assets/js/app.js?v=20260824-as1"></script>
<script src="assets/js/zafiro_pi_comparacion.js?v=20261006-1"></script>
<?php if($reportEnabled): ?><script src="assets/js/novedades_semanales.js?v=20260901-report-chart-2"></script><?php endif; ?>
</body>
</html>
