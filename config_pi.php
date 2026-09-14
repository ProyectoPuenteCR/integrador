<?php
/* =============================================================
   CLEAR PLATAFORMA — config_pi.php
   Configuración de la conexión a PI Web API. Solo administradores.
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/appconfig.php';
require_once __DIR__ . '/includes/icons.php';

auth_require_admin(); permissions_require_menu('config_pi');

$cfg = require __DIR__ . '/config.php';
$APP_USER = auth_user();
$APP_ROLE = 'Administrador';
$ACTIVE = 'config_pi';

config_ensure_table();

$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $base   = trim($_POST['pi_base'] ?? '');
    $webid  = trim($_POST['pi_ds_webid'] ?? '');
    $user   = trim($_POST['pi_user'] ?? '');
    $pass   = $_POST['pi_pass'] ?? '';

    if ($base === '') {
        $msg = 'La ruta del PI Web API es obligatoria.';
        $msgType = 'err';
    } else {
        // Normalizar: sin barra final
        $base = rtrim($base, '/');
        config_set('pi_base', $base);
        config_set('pi_ds_webid', $webid);
        config_set('pi_user', $user);
        // Solo actualizar la contraseña si se escribió algo (evita borrarla sin querer)
        if ($pass !== '') {
            config_set('pi_pass', $pass);
        }
        $msg = 'Configuración de PI guardada correctamente.';
    }
}

$pi = pi_config();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Conexión PI Web API · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css">
  <style>
    .cfg-wrap { max-width: 640px; }
    .cfg-card { background:#fff; border:1px solid var(--line-mid); border-top:3px solid var(--petrol); border-radius:var(--radius); padding:24px; }
    .cfg-card h3 { font-family:var(--font-head); font-size:18px; font-weight:700; color:var(--text); margin-bottom:6px; letter-spacing:.5px; }
    .cfg-card .desc { font-size:13px; color:var(--text-mut); margin-bottom:20px; }
    .ff { margin-bottom:16px; }
    .ff label { display:block; font-size:13px; color:var(--text-soft); margin-bottom:5px; font-weight:500; }
    .ff .hint { color:var(--text-mut); font-weight:400; font-size:12px; }
    .ff input {
      width:100%; padding:10px 12px; font-size:14px;
      background:#f7fafc; border:1px solid var(--line-mid); border-radius:var(--radius-sm);
      color:var(--text); outline:none; font-family:var(--font-ui);
    }
    .ff input:focus { border-color:var(--petrol); box-shadow:0 0 0 3px var(--petrol-soft); }
    .btn-primary { padding:11px 24px; background:var(--petrol); color:#fff; border:none; border-radius:var(--radius-sm); font-size:14px; font-weight:600; cursor:pointer; }
    .btn-primary:hover { background:var(--petrol-dark); }
    .msg { padding:11px 14px; border-radius:var(--radius-sm); font-size:13px; margin-bottom:18px; display:flex; align-items:center; gap:8px; }
    .msg svg { width:16px; height:16px; flex-shrink:0; }
    .msg.ok { background:var(--green-soft); color:var(--green-tx); border:1px solid #c2e7d5; }
    .msg.err { background:var(--red-soft); color:var(--red-tx); border:1px solid #f5c9c5; }
    .test-row { margin-top:18px; padding-top:18px; border-top:1px solid var(--line); display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .test-result { font-size:13px; }
    .sec-note { margin-top:18px; font-size:12px; color:var(--text-mut); background:#f7fafc; border:1px solid var(--line); border-radius:var(--radius-sm); padding:11px 13px; line-height:1.5; }
  </style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page__head">
      <div>
        <h1 class="page__title">Conexión PI Web API</h1>
        <div class="page__sub">Configuración del servidor PI System</div>
      </div>
      <div class="page__live"><span class="dot"></span>Área admin</div>
    </div>

    <?php if ($msg): ?>
      <div class="msg <?php echo $msgType; ?>">
        <?php echo icon($msgType === 'ok' ? 'check' : 'shield'); ?>
        <?php echo h($msg); ?>
      </div>
    <?php endif; ?>

    <div class="cfg-wrap">
      <div class="cfg-card">
        <h3>Servidor PI</h3>
        <div class="desc">Estos datos los usa la pantalla de PI Histórico para conectarse. Cambialos para apuntar a otro servidor PI.</div>

        <form method="post">
          <div class="ff">
            <label>Ruta del PI Web API</label>
            <input type="text" name="pi_base" value="<?php echo h($pi['base']); ?>"
                   placeholder="https://servidor/piwebapi">
          </div>
          <div class="ff">
            <label>Usuario</label>
            <input type="text" name="pi_user" value="<?php echo h($pi['user']); ?>"
                   autocomplete="off" placeholder="usuario de PI">
          </div>
          <div class="ff">
            <label>Contraseña <span class="hint">(dejá vacío para no cambiarla)</span></label>
            <input type="password" name="pi_pass" value="" autocomplete="new-password"
                   placeholder="<?php echo $pi['pass'] !== '' ? '•••••••• (guardada)' : 'contraseña de PI'; ?>">
          </div>
          <div class="ff">
            <label>Data Server WebID <span class="hint">(opcional, para buscar tags)</span></label>
            <input type="text" name="pi_ds_webid" value="<?php echo h($pi['ds_webid']); ?>"
                   placeholder="F1DS...">
          </div>

          <button type="submit" class="btn-primary">Guardar configuración</button>
        </form>

        <div class="test-row">
          <button type="button" class="btn-export" id="btnTest">
            <?php echo icon('check'); ?> Probar conexión
          </button>
          <span class="test-result" id="testResult"></span>
        </div>

        <div class="sec-note">
          <b>Seguridad:</b> las consultas a PI pasan por un proxy en el servidor
          (<code>pi_proxy.php</code>), así la contraseña <b>no viaja al navegador</b>
          y se evita el bloqueo del certificado HTTPS autofirmado.
        </div>
      </div>
    </div>
  </main>
</div>

<script>
// Botón "Probar conexión": prueba a través del proxy (usa la config GUARDADA).
document.getElementById('btnTest').onclick = function() {
  var result = document.getElementById('testResult');
  result.style.color = 'var(--text-mut)';
  result.innerHTML = 'Probando a través del servidor… (usa la configuración ya guardada — si cambiaste algo, guardá primero)';

  var xhr = new XMLHttpRequest();
  xhr.open('GET', 'pi_proxy.php?path=' + encodeURIComponent('/dataservers'), true);
  xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
  xhr.timeout = 15000;
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      if (xhr.status === 200) {
        result.style.color = 'var(--green)';
        result.textContent = '✓ Conexión correcta. El servidor llegó a PI Web API.';
      } else if (xhr.status === 401) {
        result.style.color = 'var(--red)';
        result.textContent = '✗ Usuario o contraseña incorrectos (401). Guardá las credenciales correctas y reintentá.';
      } else if (xhr.status === 502) {
        result.style.color = 'var(--red)';
        result.textContent = '✗ El servidor no pudo conectarse a PI. Revisá la ruta del PI Web API.';
      } else if (xhr.status === 0) {
        result.style.color = 'var(--red)';
        result.textContent = '✗ No se encontró pi_proxy.php en el servidor. Verificá que esté subido.';
      } else {
        result.style.color = 'var(--amber)';
        result.textContent = 'Respondió HTTP ' + xhr.status + '.';
      }
    }
  };
  xhr.ontimeout = function() { result.style.color = 'var(--red)'; result.textContent = '✗ Tiempo de espera agotado.'; };
  xhr.send();
};
</script>
<script src="assets/js/app.js"></script>
</body>
</html>
