<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/scada_realtime.php';

auth_require_admin();
permissions_require_menu('config_scada_realtime');
$APP_USER = auth_user() ?: 'CLEAR';
$APP_ROLE = 'Administrador';
$ACTIVE = 'config_scada_realtime';
$ready = clear_scada_objects_ready();
$csrf = clear_scada_csrf_token();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Configuración SCADA Real time · CLEAR</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260908-scada-fix1">
  <link rel="stylesheet" href="assets/css/scada_realtime_config.css?v=20260908-scada-fix1">
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main scadaCfgMain">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <header class="page__head">
      <div><h1 class="page__title">Configuración de SCADA Real time</h1><div class="page__sub">Fuente OPC, baterías y relación SCADA–PI–Alarmas</div></div>
      <div class="page__live"><span class="dot"></span>Solo administrador</div>
    </header>

    <?php if (!$ready): ?>
      <div class="cfgMessage is-error"><b>Falta instalar el módulo SQL.</b> Ejecutá <code>SQL/CLEAR_SCADA_REALTIME_20260908.sql</code>.</div>
    <?php endif; ?>
    <div class="cfgMessage" id="cfgMessage" hidden></div>

    <section class="cfgGrid cfgGrid--top">
      <article class="cfgCard">
        <div class="cfgCard__head"><div><span>Conexión operativa</span><h2>Fuente y actualización</h2></div><a href="scada_realtime.php">Abrir pantalla ↗</a></div>
        <form id="settingsForm" class="cfgForm">
          <input type="hidden" name="action" value="save_settings"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <label class="cfgWide"><span>Tabla del colector OPC</span><select id="sourceTable" name="source"><option value="">Detectando tablas…</option></select></label>
          <input type="hidden" id="sourceSchema" name="source_schema"><input type="hidden" id="sourceName" name="source_table">
          <label><span>Refresco de pantalla (s)</span><input name="refresh_seconds" type="number" min="5" max="300" value="10"></label>
          <label><span>Dato vencido después de (s)</span><input name="stale_seconds" type="number" min="10" max="86400" value="900"></label>
          <label><span>Ventana de campana (h)</span><input name="alarm_window_hours" type="number" min="1" max="720" value="24"></label>
          <label class="cfgWide"><span>URL base de PI Vision (opcional)</span><input name="pi_vision_base_url" type="url" placeholder="https://servidor/PIVision"></label>
          <button class="cfgButton cfgButton--primary cfgWide" type="submit">Guardar configuración</button>
        </form>
      </article>

      <article class="cfgCard">
        <div class="cfgCard__head"><div><span>Diagnóstico</span><h2>Estado del módulo</h2></div></div>
        <div class="runtimeStatus" id="runtimeStatus"><p>Cargando estado…</p></div>
        <div class="cfgInfo"><b>Protección de rendimiento</b><p>La pantalla consulta la vista de valores actuales. Las alarmas se leen desde una caché pequeña que el Job actualiza cada 5 minutos. No se modifica <code>dbo.FIXALARMS</code>.</p></div>
      </article>
    </section>

    <section class="cfgGrid">
      <article class="cfgCard">
        <div class="cfgCard__head"><div><span>Maestro</span><h2>Baterías y zonas</h2></div></div>
        <form id="batteryForm" class="cfgForm cfgForm--battery">
          <input type="hidden" name="action" value="save_battery"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <label><span>Código de batería</span><input name="battery" required placeholder="BCE008"></label>
          <label><span>Nombre</span><input name="name" placeholder="Batería 008"></label>
          <label><span>Zona</span><input name="zone" placeholder="Las Heras"></label>
          <label><span>Orden</span><input name="order" type="number" min="0" value="0"></label>
          <label class="cfgCheck"><input name="active" type="checkbox" value="1" checked><span>Activa</span></label>
          <button class="cfgButton cfgButton--primary" type="submit">Guardar batería</button>
        </form>
        <div class="cfgTableWrap cfgTableWrap--small"><table class="cfgTable"><thead><tr><th>Batería</th><th>Nombre</th><th>Zona</th><th>Estado</th></tr></thead><tbody id="batteryBody"></tbody></table></div>
      </article>

      <article class="cfgCard cfgCard--mapping">
        <div class="cfgCard__head"><div><span>Relación</span><h2>TAG SCADA ↔ PI ↔ Alarmas</h2></div><button class="cfgButton" id="autoLink" type="button">Vincular 10 con PI</button></div>
        <div class="tagToolbar"><input id="tagSearch" type="search" placeholder="Buscar TAG, descripción o batería…"><span id="tagCount">0 TAGs</span></div>
        <div class="cfgTableWrap"><table class="cfgTable cfgTable--tags"><thead><tr><th>TAG</th><th>Batería</th><th>Área</th><th>PI</th><th>TAG alarma</th></tr></thead><tbody id="tagBody"></tbody></table></div>
      </article>
    </section>

    <article class="cfgCard tagEditor" id="tagEditor">
      <div class="cfgCard__head"><div><span>Edición del TAG seleccionado</span><h2 id="tagEditorTitle">Seleccioná un TAG de la tabla</h2></div><button class="cfgButton" id="resolvePi" type="button" disabled>Buscar punto normalizado en PI</button></div>
      <form id="tagForm" class="cfgForm cfgForm--tag">
        <input type="hidden" name="action" value="save_tag"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <label class="cfgWide"><span>TAG SCADA</span><input id="tagName" name="tag" readonly required></label>
        <label><span>Batería</span><input name="battery"></label><label><span>Zona</span><input name="zone"></label><label><span>Área / equipo</span><input name="area"></label><label><span>Unidad</span><input name="unit"></label>
        <label><span>Punto PI</span><input name="pi_point"></label><label class="cfgWide"><span>PI WebID</span><input name="pi_webid"></label>
        <label class="cfgWide"><span>URL directa de PI Vision</span><input name="pi_vision_url" type="url"></label>
        <label class="cfgWide"><span>TAG en alarmas SQL</span><input name="alarm_tag"><small>Normalmente coincide con el TAG SCADA. Cambialo solo si FIXALARMS utiliza otro nombre.</small></label>
        <label class="cfgCheck"><input name="active" type="checkbox" value="1" checked><span>Visible en SCADA Real time</span></label>
        <button class="cfgButton cfgButton--primary" type="submit" disabled>Guardar relación</button>
      </form>
    </article>
  </main>
</div>
<script>window.CLEAR_SCADA_CONFIG={ready:<?php echo $ready?'true':'false'; ?>,csrf:<?php echo json_encode($csrf); ?>};</script>
<script src="assets/js/scada_realtime_config.js?v=20260908-scada-fix1"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
