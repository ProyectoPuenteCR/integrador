<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/tecss_3sigma_admin.php';
auth_require();
permissions_require_menu('tecss_3sigma');
header('Cache-Control: no-store');
$cfg=require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz']??'America/Argentina/Buenos_Aires');
$well=trim((string)($_GET['pozo']??''));
$embedded=(string)($_GET['embedded']??'')==='1';
$today=new DateTimeImmutable('now');
$from=$today->modify('-24 hours');
$sigmaAdmin=sigma_analysis_admin_allowed(auth_es_admin(),permissions_get_user(auth_user()),!empty($_SESSION['clear_report_mode']));
if($sigmaAdmin&&empty($_SESSION['clear_sigma_analysis_csrf']))$_SESSION['clear_sigma_analysis_csrf']=bin2hex(random_bytes(32));
function sigma_hist_h($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Histórico 3Sigma · <?php echo sigma_hist_h($well); ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=20260812-sigma-history"><link rel="stylesheet" href="assets/css/tecss_3sigma_historico.css?v=20261028-afadmin-r5"><link rel="stylesheet" href="assets/css/chart_preferences.css?v=20260826-chartprefs-2">
<link rel="stylesheet" href="assets/css/grid_tools.css?v=20261019-all-grids-1"><script src="assets/js/grid_tools.js?v=20261019-all-grids-1" defer></script></head>
<body><main class="sigma-history<?php echo $embedded?' is-embedded':''; ?>">
  <header class="sigma-history__head"><div><div class="sigma-history__eyebrow">Histórico 3Sigma</div><h1>3Sigma · <?php echo sigma_hist_h($well!==''?$well:'Pozo'); ?></h1><p>Valores registrados, alertas reales y auditoría de email.</p></div><div class="sigma-history__source">SQL + eventos AF</div></header>
  <form class="sigma-history__toolbar" id="sigmaHistoryForm">
    <label><span>POZO</span><input id="historyWell" type="text" value="<?php echo sigma_hist_h($well); ?>" readonly></label>
    <label><span>DESDE</span><input id="historyFrom" type="datetime-local" value="<?php echo $from->format('Y-m-d\TH:i'); ?>" required></label>
    <label><span>HASTA</span><input id="historyTo" type="datetime-local" value="<?php echo $today->format('Y-m-d\TH:i'); ?>" required></label>
    <button class="sigma-history__btn is-primary" type="submit" id="historyApply">Aplicar</button>
    <button class="sigma-history__btn" type="button" id="historyExcel">Exportar Excel</button>
  </form>
  <div class="sigma-history__status" id="historyStatus" role="status" aria-live="polite"></div>
  <?php if($sigmaAdmin): ?><section class="sigma-history__analysis-admin" id="sigmaAnalysisAdmin" data-well="<?php echo sigma_hist_h($well); ?>" data-csrf="<?php echo sigma_hist_h($_SESSION['clear_sigma_analysis_csrf']); ?>"><div><strong>Análisis AF · 3Sigma</strong><span id="sigmaAnalysisState">Comprobando estado…</span></div><button type="button" class="sigma-history__btn" id="sigmaAnalysisRefresh">Verificar estado</button><button type="button" class="sigma-history__btn is-primary" id="sigmaAnalysisEnable" hidden disabled>Habilitar análisis 3Sigma</button><p>Solo administradores. Este control habilita el cálculo 3Sigma del pozo; la regla de envío de emails tiene su propio estado.</p><p id="sigmaAnalysisResult" role="status" aria-live="polite"></p></section><?php endif; ?>
  <section class="sigma-history__cards"><article><span>Registros</span><b id="historyCount">0</b></article><article><span>Último 3Sigma</span><b id="historyLastSigma">—</b></article><article><span>Máximo 3Sigma</span><b id="historyMaxSigma">—</b></article><article><span>Máximo excesos</span><b id="historyMaxExcess">—</b></article><article><span>Duración último exceso observado</span><b id="historyLastDuration">—</b></article></section>
  <section class="sigma-history__panel"><div class="sigma-history__panel-head"><h2>Tendencia 3Sigma</h2><span id="historyRange">Últimas 24 horas</span></div><div class="sigma-history__zoom-controls"><span>Zoom: arrastrá sobre el gráfico · Ctrl + rueda para acercar</span><button type="button" class="sigma-history__btn" id="historyZoomIn" aria-label="Acercar gráfico">+</button><button type="button" class="sigma-history__btn" id="historyZoomOut" aria-label="Alejar gráfico">−</button><button type="button" class="sigma-history__btn" id="historyResetZoom" disabled>Restaurar vista</button><span id="historyZoomRange" role="status" aria-live="polite"></span></div><div class="sigma-history__chart"><canvas id="sigmaHistoryChart" data-clear-chart-local="true" role="img" aria-label="Tendencia temporal de 3Sigma y excesos con marcas independientes de alertas y envíos reales. Consultá el detalle en la tabla inferior."></canvas><div class="sigma-history__empty" id="historyEmpty">No hay datos en el período seleccionado.</div></div></section>
  <section class="sigma-history__panel sigma-history__excess-panel"><h2>Últimos tramos de exceso · desde / hasta</h2><div id="historyExcessList" aria-live="polite"></div></section><section class="sigma-history__panel sigma-history__table-panel"><div class="sigma-history__panel-head"><h2>Valores registrados y eventos</h2><span>Hasta 2.000 valores SQL y 250 alertas AF por consulta</span></div><p class="sigma-history__audit-note" id="historyAuditStatus" aria-live="polite"></p><p class="sigma-history__audit-note">Los envíos se muestran con su propia fecha y destinatarios registrados. «Enviado» indica éxito informado por PI Notifications; no confirma lectura ni recepción en la bandeja. Los marcadores no representan valores de 3Sigma.</p><div class="sigma-history__table-wrap"><table><thead><tr><th>Captura / evento</th><th>HOY</th><th>3SIGMA</th><th>CONT_EXCESOS</th><th>Batería</th><th>Método</th><th>Evento</th><th>Estado email</th><th>Fecha de envío</th><th>Destinatarios</th><th>Error / detalle</th></tr></thead><tbody id="historyRows"></tbody></table></div></section>
</main><script src="assets/js/chart.umd.js"></script><script src="assets/js/chartjs-plugin-zoom.min.js?v=2.0.1"></script><script src="assets/js/chart_preferences.js?v=20260826-chartprefs-2"></script><script src="assets/js/tecss_3sigma_historico.js?v=20261028-afadmin-r5"></script><?php if($sigmaAdmin): ?><script src="assets/js/tecss_3sigma_admin.js?v=20261028-afadmin-r5"></script><?php endif; ?></body></html>
