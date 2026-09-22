<?php
/* =============================================================
   CLEAR PLATAFORMA — estado_sistema.php
   Diagnóstico de conectividad + seguimiento de Jobs SQL Agent.
   Solo administradores. Sin refresco automático.
   17/09/2026: se agrega la sección "Jobs de base de datos".
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/sql_jobs_status.php';

auth_require_admin();
$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'America/Argentina/Buenos_Aires');

$APP_USER = auth_user();
$APP_ROLE = 'Administrador';
$ACTIVE   = 'estado_sistema';

$db = clear_db();

/* ---- Pruebas de conectividad (sin cambios respecto de la versión anterior) ---- */
$tests = [];
$start = microtime(true);
$ok = $db->ok();
$tests[] = ['SQL Server', $ok, round((microtime(true) - $start) * 1000, 1), $ok ? 'Conectado' : $db->error()];
foreach (['FIXALARMS', 'BM_RTQP', 'PCP_RTQP', 'BES_RTQP', 'TECSS_RTQP'] as $o) {
    $start = microtime(true);
    $n = $db->scalar("SELECT COUNT_BIG(*) FROM dbo.$o");
    $tests[] = [$o, $n !== null, round((microtime(true) - $start) * 1000, 1),
        $n === null ? $db->error() : number_format((float)$n, 0, ',', '.') . ' registros'];
}

/* ---- Jobs ---- */
$jt = microtime(true);
$J = sqljobs_status($db);
$jobsMs = round((microtime(true) - $jt) * 1000, 1);

$badge = [
    'ok'       => 'jb--ok',
    'fail'     => 'jb--fail',
    'late'     => 'jb--late',
    'warn'     => 'jb--late',
    'running'  => 'jb--run',
    'disabled' => 'jb--off',
    'missing'  => 'jb--off',
];
$runTxt = [0 => 'Falló', 1 => 'OK', 2 => 'Reintento', 3 => 'Cancelado', 4 => 'En curso'];
$runCls = [0 => 'jb--fail', 1 => 'jb--ok', 2 => 'jb--late', 3 => 'jb--late', 4 => 'jb--run'];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CLEAR · Estado del sistema</title>
<link rel="stylesheet" href="assets/css/app.css?v=3.0.0">
<link rel="stylesheet" href="assets/css/estado_sistema.css?v=20260917-jobs1">
</head>
<body>
<div class="app">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
<?php include __DIR__ . '/includes/topbar.php'; ?>

<div class="page__head">
  <div>
    <h1 class="page__title">Estado del sistema</h1>
    <div class="page__sub">Diagnóstico de conectividad, rendimiento y Jobs de base de datos</div>
  </div>
  <div><a class="btn-export" href="estado_sistema.php"><?php echo icon('history'); ?> Actualizar</a></div>
</div>

<section class="sys">
  <div class="sysgrid">
    <?php foreach ($tests as $t): ?>
    <div class="syscard">
      <div class="<?php echo $t[1] ? 'sysok' : 'sysbad'; ?>"><?php echo $t[1] ? '● Operativo' : '● Error'; ?></div>
      <h2><?php echo h($t[0]); ?></h2>
      <b><?php echo h($t[3]); ?></b>
      <div><?php echo $t[2]; ?> ms</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ===================== JOBS DE BASE DE DATOS ===================== -->
  <div class="jobs" id="jobs">
    <div class="jobs__head">
      <div>
        <h2 class="jobs__title">Jobs de base de datos</h2>
        <div class="jobs__sub">
          SQL Server Agent · <?php echo h($cfg['db']['host'] ?? ''); ?>
          <?php if (!empty($J['server_now'])): ?> · Hora del servidor <?php echo h(sqljobs_fmt_dt($J['server_now'])); ?><?php endif; ?>
          · Lectura <?php echo $jobsMs; ?> ms
        </div>
      </div>
      <?php if ($J['ok']): ?>
      <label class="jobs__filter"><input type="checkbox" id="jobsOnlyIssues"> Mostrar solo con problemas</label>
      <?php endif; ?>
    </div>

    <?php if (!$J['ok']): ?>
      <div class="jobs__alert">
        <b>No se pudo leer el estado de los Jobs.</b>
        <div><?php echo h($J['error']); ?></div>
      </div>
    <?php else: $S = $J['summary']; $A = $J['agent']; ?>

      <div class="jobs__cards">
        <div class="jcard <?php echo $A['ok'] ? 'jcard--ok' : 'jcard--fail'; ?>">
          <span>SQL Server Agent</span>
          <b><?php echo $A['ok'] ? 'Activo' : 'Revisar'; ?></b>
          <small><?php echo h($A['texto']); ?><?php if ($A['inicio']): ?> · Inicio <?php echo h(sqljobs_fmt_dt($A['inicio'])); ?><?php endif; ?></small>
        </div>
        <div class="jcard jcard--ok"><span>OK</span><b><?php echo (int)$S['ok']; ?></b><small>de <?php echo (int)$S['total']; ?> monitoreados</small></div>
        <div class="jcard <?php echo $S['fail'] ? 'jcard--fail' : ''; ?>"><span>Con falla</span><b><?php echo (int)$S['fail']; ?></b><small>última ejecución fallida</small></div>
        <div class="jcard <?php echo ($S['late'] + $S['warn']) ? 'jcard--late' : ''; ?>"><span>Atrasados / a revisar</span><b><?php echo (int)($S['late'] + $S['warn']); ?></b><small>no corrieron en el intervalo esperado</small></div>
        <div class="jcard"><span>En ejecución</span><b><?php echo (int)$S['running']; ?></b><small>ahora</small></div>
        <div class="jcard"><span>No instalados / deshabilitados</span><b><?php echo (int)($S['missing'] + $S['disabled']); ?></b><small>verificar si corresponde</small></div>
      </div>

      <div class="jobs__wrap">
        <table class="jobs__table">
          <thead>
            <tr>
              <th>Estado</th>
              <th>Job</th>
              <th>Frecuencia</th>
              <th>Última ejecución</th>
              <th>Duración</th>
              <th>Próxima</th>
              <th title="Según el historial que conserva SQL Agent">Últimas 24 h</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($J['jobs'] as $i => $x):
              $issue = in_array($x['estado'], ['fail', 'late', 'warn'], true);
              $u = $x['ultima']; ?>
            <tr class="jrow" data-issue="<?php echo $issue ? '1' : '0'; ?>">
              <td><span class="jb <?php echo $badge[$x['estado']] ?? 'jb--off'; ?>"><?php echo h($x['estado_txt']); ?></span></td>
              <td>
                <div class="jname"><?php echo h($x['nombre']); ?></div>
                <div class="jmeta"><?php echo h($x['modulo']); ?></div>
              </td>
              <td class="jmono"><?php echo h($x['frecuencia']); ?></td>
              <td>
                <?php if ($x['corriendo']): ?>
                  <div class="jmono">Inició <?php echo h(sqljobs_fmt_dt($x['corriendo'])); ?></div>
                <?php endif; ?>
                <?php if ($u): ?>
                  <div class="jmono"><?php echo h(sqljobs_fmt_dt($u['inicio'])); ?></div>
                  <div class="jmeta"><?php echo h(sqljobs_fmt_age($x['ultima_edad'])); ?> · <?php echo h($runTxt[$u['estado']] ?? ('Estado ' . $u['estado'])); ?></div>
                <?php elseif (!$x['corriendo']): ?>
                  <span class="jmeta">—</span>
                <?php endif; ?>
              </td>
              <td class="jmono"><?php echo $u ? h(sqljobs_fmt_duration($u['duracion'])) : '—'; ?></td>
              <td class="jmono"><?php echo $x['proxima'] ? h(sqljobs_fmt_dt($x['proxima'])) : '—'; ?></td>
              <td>
                <?php if ($x['tot24'] > 0): ?>
                  <span class="jcount jcount--ok"><?php echo (int)$x['ok24']; ?> OK</span>
                  <?php if ($x['fail24'] > 0): ?><span class="jcount jcount--fail"><?php echo (int)$x['fail24']; ?> fallas</span><?php endif; ?>
                <?php else: ?>
                  <span class="jmeta">Sin registros</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($x['estado'] !== 'missing'): ?>
                <button type="button" class="jbtn" data-toggle="jd<?php echo $i; ?>">Detalle</button>
                <?php else: ?>
                <span class="jmeta jscript" title="Script de instalación"><?php echo h($x['script']); ?></span>
                <?php endif; ?>
              </td>
            </tr>
            <?php if ($x['estado'] !== 'missing'): ?>
            <tr class="jdetail" id="jd<?php echo $i; ?>" data-issue="<?php echo $issue ? '1' : '0'; ?>" hidden>
              <td colspan="8">
                <div class="jd">
                  <div class="jd__info">
                    <div><b>Origen:</b> <?php echo h($x['script']); ?></div>
                    <div><b>Habilitado:</b> <?php echo $x['habilitado'] ? 'Sí' : 'No'; ?></div>
                    <?php if ($x['periodo']): ?>
                    <div><b>Se considera atrasado si no corre en:</b> <?php echo h(sqljobs_fmt_duration($x['periodo'] + max(600, (int)round($x['periodo'] * 0.5)))); ?></div>
                    <?php endif; ?>
                  </div>
                  <?php if ($x['error_paso']): $e = $x['error_paso']; ?>
                  <div class="jd__err">
                    <b>Último paso con error:</b> <?php echo h($e['PASO']); ?> ·
                    <?php echo h(sqljobs_fmt_dt(sqljobs_agent_datetime($e['FECHA'], $e['HORA']))); ?>
                    <pre><?php echo h($e['MSG']); ?></pre>
                  </div>
                  <?php endif; ?>
                  <?php if ($x['historial']): ?>
                  <table class="jd__hist">
                    <thead><tr><th>Inicio</th><th>Resultado</th><th>Duración</th><th>Mensaje</th></tr></thead>
                    <tbody>
                    <?php foreach ($x['historial'] as $r): ?>
                      <tr>
                        <td class="jmono"><?php echo h(sqljobs_fmt_dt($r['inicio'])); ?></td>
                        <td><span class="jb <?php echo $runCls[$r['estado']] ?? 'jb--off'; ?>"><?php echo h($runTxt[$r['estado']] ?? $r['estado']); ?></span></td>
                        <td class="jmono"><?php echo h(sqljobs_fmt_duration($r['duracion'])); ?></td>
                        <td class="jmsg"><?php echo h($r['mensaje']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                  <?php else: ?>
                  <div class="jmeta">SQL Agent no conserva ejecuciones de este Job.</div>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="jobs__note">
        Estados: <b>Atrasado</b> = no registró ejecución dentro de su intervalo más un margen (50 % o 10 min).
        "Últimas 24 h" cuenta sobre el historial que conserva SQL Agent; si el límite de historial es bajo, puede cubrir menos tiempo.
        La pantalla solo lee msdb: no inicia, detiene ni modifica Jobs.
      </div>
    <?php endif; ?>
  </div>

  <div class="sysmeta">
    <b>Servidor PHP</b>
    <p>PHP <?php echo h(PHP_VERSION); ?> · Memoria actual <?php echo round(memory_get_usage(true) / 1048576, 1); ?> MB · Pico <?php echo round(memory_get_peak_usage(true) / 1048576, 1); ?> MB</p>
  </div>
</section>
</main>
</div>
<script src="assets/js/app.js"></script>
<script>
(function () {
  document.querySelectorAll('.jbtn[data-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var row = document.getElementById(b.getAttribute('data-toggle'));
      if (!row) return;
      row.hidden = !row.hidden;
      b.textContent = row.hidden ? 'Detalle' : 'Ocultar';
    });
  });
  var only = document.getElementById('jobsOnlyIssues');
  if (only) {
    only.addEventListener('change', function () {
      document.querySelectorAll('.jobs__table tr.jrow').forEach(function (tr) {
        var hide = only.checked && tr.getAttribute('data-issue') !== '1';
        tr.style.display = hide ? 'none' : '';
        var det = tr.nextElementSibling;
        if (det && det.classList.contains('jdetail') && hide) { det.hidden = true; }
      });
      document.querySelectorAll('.jbtn[data-toggle]').forEach(function (b) {
        var row = document.getElementById(b.getAttribute('data-toggle'));
        if (row && row.hidden) b.textContent = 'Detalle';
      });
    });
  }
})();
</script>
</body>
</html>
