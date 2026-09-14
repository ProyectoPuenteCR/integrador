<?php
/* =============================================================
   CLEAR PLATAFORMA — config_menu.php
   Permite mostrar/ocultar ítems del menú lateral. Solo admin.
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/appconfig.php';
require_once __DIR__ . '/includes/icons.php';

auth_require_admin(); permissions_require_menu('config_menu');

$cfg = require __DIR__ . '/config.php';
$APP_USER = auth_user();
$APP_ROLE = 'Administrador';
$ACTIVE = 'config_menu';

// Lista de ítems del menú que se pueden ocultar (las 15 listas).
// Debe coincidir con $nav de sidebar.php.
$items = [
    ['key'=>'dashboard_inst_sup','label'=>'Dashboard Inst Sup','icon'=>'grid'],
    ['key'=>'dashboard_pozos','label'=>'Dashboard Pozos','icon'=>'oil'],
    ['key'=>'pozos_por_baterias','label'=>'Pozos por Baterías','icon'=>'grid'],
    ['key'=>'scada_realtime','label'=>'SCADA Real time','icon'=>'monitor'],
    ['key' => 'monitoreo_pozos','label' => 'Telemetría · Monitoreo Pozos','icon' => 'monitor'],
    ['key'=>'telemetria_pcp','label'=>'Telemetría · PCP','icon'=>'oil'],
    ['key'=>'telemetria_bes','label'=>'Telemetría · BES','icon'=>'gauge'],
    ['key'=>'telemetria_tecss','label'=>'Telemetría · TECCS','icon'=>'trend'],
    ['key'=>'tecss_3sigma','label'=>'Telemetría · 3Sigma TECSS','icon'=>'chart'],
    ['key'=>'tecss_vibraciones','label'=>'Telemetría · Análisis de Vibraciones TECSS','icon'=>'wave'],
    ['key' => 'inyeccion_agua',  'label' => 'Inyección de Agua',   'icon' => 'droplet'],
    ['key' => 'alarmas24h',      'label' => 'Alarmas 24h',         'icon' => 'bell'],
    ['key' => 'instalaciones_alarmas_semanal','label' => 'Alarmas semanal','icon' => 'chart'],
    ['key' => 'novedades_semanales_panel','label' => 'Novedades semanales · Panel','icon' => 'grid'],
    ['key' => 'micros_contables','label' => 'Novedades semanales · Micros contables','icon' => 'chart'],
    ['key' => 'micros_contables_cierre','label' => 'Novedades semanales · Cierre diario','icon' => 'trend'],
    ['key' => 'novedades_semanales_malos_actores','label' => 'Novedades semanales · Malos actores','icon' => 'chart'],
    ['key' => 'novedades_semanales_comparativa','label' => 'Novedades semanales · Comparativa','icon' => 'trend'],
    ['key' => 'novedades_semanales_seguimiento','label' => 'Novedades semanales · Seguimiento','icon' => 'message'],
    ['key' => 'novedades_semanales_reporte','label' => 'Novedades semanales · Reporte','icon' => 'download'],
    ['key' => 'alarmas_activas', 'label' => 'Alarmas por Historicos',     'icon' => 'wave'],
    ['key' => 'suprimidas',      'label' => 'Suprimidas',          'icon' => 'shield'],
    ['key' => 'reconocidas',     'label' => 'Reconocidas',         'icon' => 'check'],
    ['key' => 'reconocidas_usr', 'label' => 'Reconocidas usuario', 'icon' => 'check'],
    ['key' => 'top20_all',       'label' => 'Top 20 alarmas',      'icon' => 'chart'],
    ['key' => 'top20_24h',       'label' => 'Top 20 24h',          'icon' => 'chart'],
    ['key' => 'ranking24h',      'label' => 'Ranking 24h',         'icon' => 'chart'],
    ['key' => 'tendencia_sem',   'label' => 'Tendencia semanal',   'icon' => 'trend'],
    ['key' => 'tend_sem_tags',   'label' => 'Tendencia por tag',   'icon' => 'trend'],
    ['key' => 'prioridad',       'label' => 'Prioridad',           'icon' => 'gauge'],
    ['key' => 'todas_alarmas',   'label' => 'Todas las alarmas', 'icon' => 'file'],
    ['key' => 'pozos',           'label' => 'Pozos · Ficha',       'icon' => 'oil'],
    ['key' => 'pozos_tecss',     'label' => 'Pozos · técnico',     'icon' => 'gauge'],
    ['key' => 'analisis_ia',    'label' => 'Análisis IA',          'icon' => 'trend'],
    ['key' => 'importadas',      'label' => 'Alarmas importadas',  'icon' => 'file'],
];

$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Los checkboxes marcados = ítems VISIBLES. Los no marcados = ocultos.
    $visibles = $_POST['visible'] ?? [];
    $ocultos = [];
    foreach ($items as $it) {
        if (!in_array($it['key'], $visibles, true)) {
            $ocultos[] = $it['key'];
        }
    }
    if (menu_set_ocultos($ocultos)) {
        $msg = 'Configuración del menú guardada. Los cambios se ven al recargar (Ctrl+F5).';
    } else {
        $msg = 'No se pudo guardar la configuración.';
        $msgType = 'err';
    }
}

$ocultosActual = menu_ocultos();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Configurar menú · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css">
  <style>
    .menu-cfg { max-width: 680px; }
    .menu-card { background:#fff; border:1px solid var(--line-mid); border-top:3px solid var(--petrol); border-radius:var(--radius); padding:24px; }
    .menu-card h3 { font-family:var(--font-head); font-size:18px; font-weight:700; color:var(--text); margin-bottom:6px; }
    .menu-card .desc { font-size:13px; color:var(--text-mut); margin-bottom:18px; }
    .chk-row {
      display:flex; align-items:center; gap:12px;
      padding:11px 14px; border:1px solid var(--line-mid); border-radius:var(--radius-sm);
      margin-bottom:8px; transition:background .12s, border-color .12s;
      cursor:pointer; user-select:none;
    }
    .chk-row:hover { background:var(--petrol-soft); border-color:var(--petrol-line); }
    .chk-row input { width:18px; height:18px; accent-color:var(--petrol); cursor:pointer; flex-shrink:0; }
    .chk-row .ic { width:30px; height:30px; border-radius:7px; background:var(--petrol-soft); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .chk-row .ic svg { width:16px; height:16px; color:var(--petrol); }
    .chk-row .lbl { font-size:14px; color:var(--text); font-weight:500; }
    .chk-row.oculto { opacity:.55; }
    .chk-row.oculto .lbl::after { content:' (oculto)'; color:var(--text-mut); font-weight:400; font-size:12px; }
    .actions { display:flex; gap:10px; align-items:center; margin-top:20px; padding-top:18px; border-top:1px solid var(--line); }
    .btn-primary { padding:11px 24px; background:var(--petrol); color:#fff; border:none; border-radius:var(--radius-sm); font-size:14px; font-weight:600; cursor:pointer; }
    .btn-primary:hover { background:var(--petrol-dark); }
    .btn-link { padding:8px 14px; background:none; border:none; color:var(--petrol); font-size:13px; cursor:pointer; text-decoration:underline; }
    .msg { padding:11px 14px; border-radius:var(--radius-sm); font-size:13px; margin-bottom:18px; display:flex; align-items:center; gap:8px; }
    .msg svg { width:16px; height:16px; flex-shrink:0; }
    .msg.ok { background:var(--green-soft); color:var(--green-tx); border:1px solid #c2e7d5; }
    .msg.err { background:var(--red-soft); color:var(--red-tx); border:1px solid #f5c9c5; }
    .fixed-note { font-size:12px; color:var(--text-mut); margin-bottom:16px; background:#f7fafc; border:1px solid var(--line); border-radius:var(--radius-sm); padding:10px 13px; }
  </style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page__head">
      <div>
        <h1 class="page__title">Configurar menú</h1>
        <div class="page__sub">Mostrar u ocultar opciones del menú lateral</div>
      </div>
      <div class="page__live"><span class="dot"></span>Área admin</div>
    </div>

    <?php if ($msg): ?>
      <div class="msg <?php echo $msgType; ?>">
        <?php echo icon($msgType === 'ok' ? 'check' : 'shield'); ?>
        <?php echo h($msg); ?>
      </div>
    <?php endif; ?>

    <div class="menu-cfg">
      <div class="menu-card">
        <h3>Opciones del menú</h3>
        <div class="desc">Marcá las opciones que querés que aparezcan en el menú lateral. Las desmarcadas quedan ocultas para todos los usuarios.</div>
        <div class="fixed-note">Dashboard y PI Histórico siempre están visibles y no se pueden ocultar.</div>

        <form method="post" id="menuForm">
          <?php foreach ($items as $it):
            $oculto = in_array($it['key'], $ocultosActual, true);
            $visible = !$oculto;
          ?>
            <label class="chk-row <?php echo $oculto ? 'oculto' : ''; ?>">
              <input type="checkbox" name="visible[]" value="<?php echo h($it['key']); ?>" <?php echo $visible ? 'checked' : ''; ?>>
              <span class="ic"><?php echo icon($it['icon']); ?></span>
              <span class="lbl"><?php echo h($it['label']); ?></span>
            </label>
          <?php endforeach; ?>

          <div class="actions">
            <button type="submit" class="btn-primary">Guardar cambios</button>
            <button type="button" class="btn-link" onclick="marcarTodos(true)">Marcar todas</button>
            <button type="button" class="btn-link" onclick="marcarTodos(false)">Desmarcar todas</button>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>

<script>
function marcarTodos(estado){
  var chks = document.querySelectorAll('#menuForm input[type=checkbox]');
  for (var i=0;i<chks.length;i++){ chks[i].checked = estado; }
}
</script>
<script src="assets/js/app.js"></script>
</body>
</html>
