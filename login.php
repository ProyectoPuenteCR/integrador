<?php
/* =============================================================
   CLEAR PLATAFORMA — login.php
============================================================= */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/icons.php';

auth_start();

// Si ya está logueado, al dashboard.
if (auth_check()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['usuario'] ?? '');
    $p = $_POST['pass'] ?? '';
    if ($u === '' || $p === '') {
        $error = 'Ingresá usuario y contraseña.';
    } else {
        $res = auth_login($u, $p);
        if ($res['ok']) {
            header('Location: index.php');
            exit;
        }
        $error = $res['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ingresar · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260714-21">
  <style>
    body {
      min-height:100vh;
      background:
        linear-gradient(90deg, rgba(8,31,40,.38), rgba(8,31,40,.10) 48%, rgba(8,31,40,.22)),
        url('assets/img/login-clear-field.png') center center / cover no-repeat fixed;
    }
    .login-wrap {
      min-height: 100vh;
      display: flex; align-items: center; justify-content: center;
      padding: 20px;
      backdrop-filter: blur(.4px);
    }
    .login-card {
      width: 100%; max-width: 380px;
      background: rgba(255,255,255,.96);
      border: 1px solid var(--line-mid);
      border-top: 3px solid var(--petrol);
      border-radius: var(--radius-lg);
      box-shadow: 0 22px 65px rgba(5,24,32,.32);
      backdrop-filter: blur(8px);
      overflow: hidden;
    }
    .login-logo {
      padding: 28px 28px 20px;
      text-align: center;
      border-bottom: 1px solid var(--line);
    }
    .login-logo img { max-width: 180px; width: 100%; height: auto; }
    .login-body { padding: 26px 28px 30px; }
    .login-title {
      font-family: var(--font-head); font-weight: 700;
      font-size: 22px; color: var(--text); text-align: center;
      margin-bottom: 4px;
    }
    .login-sub {
      font-size: 12px; letter-spacing: 1px; text-transform: uppercase;
      color: var(--text-mut); text-align: center; margin-bottom: 24px;
    }
    .login-field { margin-bottom: 16px; }
    .login-field label {
      display: block; font-size: 13px; color: var(--text-soft);
      margin-bottom: 6px; font-weight: 500;
    }
    .login-field .inp {
      display: flex; align-items: center; gap: 9px;
      background: #f7fafc;
      border: 1px solid var(--line-mid);
      border-radius: var(--radius-sm);
      padding: 11px 13px;
      transition: border-color .15s, box-shadow .15s;
    }
    .login-field .inp:focus-within {
      border-color: var(--petrol);
      box-shadow: 0 0 0 3px var(--petrol-soft);
    }
    .login-field .inp svg { width: 17px; height: 17px; color: var(--text-mut); flex-shrink: 0; }
    .login-field input {
      background: none; border: none; outline: none;
      color: var(--text); font-size: 14px; width: 100%;
    }
    .login-btn {
      width: 100%; margin-top: 8px;
      padding: 12px;
      background: var(--petrol); color: #fff;
      border: none; border-radius: var(--radius-sm);
      font-size: 14px; font-weight: 600; letter-spacing: .3px;
      transition: background .15s;
    }
    .login-btn:hover { background: var(--petrol-dark); }
    .login-error {
      background: var(--red-soft); color: var(--red-tx);
      border: 1px solid #f5c9c5; border-radius: var(--radius-sm);
      padding: 10px 13px; font-size: 13px; margin-bottom: 18px;
      display: flex; align-items: center; gap: 8px;
    }
    .login-error svg { width: 16px; height: 16px; flex-shrink: 0; }
    @media (max-width:760px) {
      body { background-position:42% center; }
      .login-card { max-width:360px; }
    }
    .login-foot {
      text-align: center; margin-top: 20px;
      font-size: 11px; color: var(--text-dim); letter-spacing: 1px; text-transform: uppercase;
    }
  </style>
</head>
<body>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-logo">
        <img src="assets/img/logo-clear.jpg" alt="CLEAR Petroleum">
      </div>
      <div class="login-body">
        <div class="login-title">Plataforma inteligente</div>
        <div class="login-sub">Monitoreo instalaciones</div>

        <?php if ($error): ?>
          <div class="login-error"><?php echo icon('shield'); ?><?php echo h($error); ?></div>
        <?php endif; ?>

        <form method="post" action="login.php">
          <div class="login-field">
            <label>Usuario</label>
            <div class="inp">
              <?php echo icon('check'); ?>
              <input type="text" name="usuario" autocomplete="username" autofocus
                     value="<?php echo h($_POST['usuario'] ?? ''); ?>">
            </div>
          </div>
          <div class="login-field">
            <label>Contraseña</label>
            <div class="inp">
              <?php echo icon('shield'); ?>
              <input type="password" name="pass" autocomplete="current-password">
            </div>
          </div>
          <button type="submit" class="login-btn">Ingresar</button>
        </form>

        <div class="login-foot">CLEAR Petroleum</div>
      </div>
    </div>
  </div>
</body>
</html>
