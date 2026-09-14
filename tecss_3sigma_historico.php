<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
auth_require();
permissions_require_menu('tecss_3sigma');
$cfg=require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz']??'America/Argentina/Buenos_Aires');
$well=trim((string)($_GET['pozo']??''));
$embedded=(string)($_GET['embedded']??'')==='1';
$today=new DateTimeImmutable('today');
$from=$today->modify('-30 days');
function sigma_hist_h($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Histórico 3Sigma · <?php echo sigma_hist_h($well); ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=20260812-sigma-history"><link rel="stylesheet" href="assets/css/tecss_3sigma_historico.css?v=20260812-sigma-history"><link rel="stylesheet" href="assets/css/chart_preferences.css?v=20260826-chartprefs-2"></head>
<body><main class="sigma-history<?php echo $embedded?' is-embedded':''; ?>">
  <header class="sigma-history__head"><div><div class="sigma-history__eyebrow">Histórico SQL</div><h1>3Sigma · <?php echo sigma_hist_h($well!==''?$well:'Pozo'); ?></h1><p>Tendencia obtenida exclusivamente de dbo.TECSS_TECSSAIB_HISTORICO.</p></div><div class="sigma-history__source">Consulta indexada por pozo</div></header>
  <form class="sigma-history__toolbar" id="sigmaHistoryForm">
    <label><span>POZO</span><input id="historyWell" type="text" value="<?php echo sigma_hist_h($well); ?>" readonly></label>
    <label><span>DESDE</span><input id="historyFrom" type="date" value="<?php echo $from->format('Y-m-d'); ?>" required></label>
    <label><span>HASTA</span><input id="historyTo" type="date" value="<?php echo $today->format('Y-m-d'); ?>" required></label>
    <button class="sigma-history__btn is-primary" type="submit" id="historyApply">Aplicar</button>
    <button class="sigma-history__btn" type="button" id="historyExcel">Exportar Excel</button>
  </form>
  <div class="sigma-history__status" id="historyStatus"></div>
  <section class="sigma-history__cards"><article><span>Registros</span><b id="historyCount">0</b></article><article><span>Último 3Sigma</span><b id="historyLastSigma">—</b></article><article><span>Máximo 3Sigma</span><b id="historyMaxSigma">—</b></article><article><span>Máximo excesos</span><b id="historyMaxExcess">—</b></article></section>
  <section class="sigma-history__panel"><div class="sigma-history__panel-head"><h2>Tendencia 3Sigma</h2><span id="historyRange">Últimos 30 días</span></div><div class="sigma-history__chart"><canvas id="sigmaHistoryChart"></canvas><div class="sigma-history__empty" id="historyEmpty">No hay datos en el período seleccionado.</div></div></section>
  <section class="sigma-history__panel sigma-history__table-panel"><div class="sigma-history__panel-head"><h2>Valores registrados</h2><span>Máximo 2.000 registros por consulta</span></div><div class="sigma-history__table-wrap"><table><thead><tr><th>Captura</th><th>HOY</th><th>3SIGMA</th><th>CONT_EXCESOS</th><th>Batería</th><th>Método</th></tr></thead><tbody id="historyRows"></tbody></table></div></section>
</main><script src="assets/js/chart.umd.js"></script><script src="assets/js/chart_preferences.js?v=20260826-chartprefs-2"></script><script src="assets/js/tecss_3sigma_historico.js?v=20260909-1"></script></body></html>
