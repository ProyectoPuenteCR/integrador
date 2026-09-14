<?php
/* =============================================================
   CLEAR PLATAFORMA — Configuración global de alarmas.
   Solo administradores. La web encola el cambio y SQL Agent
   reconstruye las cachés de manera secuencial.
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/icons.php';

auth_require_admin();
permissions_require_menu('config_alarmas');

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');
$APP_USER = auth_user();
$APP_ROLE = 'Administrador';
$ACTIVE = 'config_alarmas';
$db = clear_db();
$installed = $db->ok() && (int)$db->scalar(
    "SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_ALARM_POLICY',N'U') IS NOT NULL " .
    "AND OBJECT_ID(N'dbo.SP_CLEAR_SOLICITAR_POLITICA_CFN',N'P') IS NOT NULL THEN 1 ELSE 0 END"
) === 1;

auth_start();
if (empty($_SESSION['clear_alarm_policy_csrf'])) {
    $_SESSION['clear_alarm_policy_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['clear_alarm_policy_csrf'];

$policy = [
    'MODO_ACTIVO' => 'OCULTAR_ESTADO', 'ESTADO' => $installed ? 'COMPLETADO' : 'NO_INSTALADO',
    'SOLICITADO_POR' => '', 'SOLICITADO_EN' => '', 'INICIADO_EN' => '', 'FINALIZADO_EN' => '',
    'DETALLE' => '', 'ULTIMO_ERROR' => '', 'RECALCULO_COMPLETO' => 0,
];
if ($installed) {
    $rows = $db->all(
        "SELECT MODO_ACTIVO,ESTADO,SOLICITADO_POR,CONVERT(nvarchar(19),SOLICITADO_EN,120) SOLICITADO_EN," .
        "CONVERT(nvarchar(19),INICIADO_EN,120) INICIADO_EN,CONVERT(nvarchar(19),FINALIZADO_EN,120) FINALIZADO_EN," .
        "DETALLE,ULTIMO_ERROR,RECALCULO_COMPLETO FROM dbo.CLEAR_ALARM_POLICY WHERE ID=1"
    );
    if ($rows) $policy = array_merge($policy, $rows[0]);
}

function alarm_policy_mode_label($mode)
{
    $labels = ['OCULTAR_ESTADO'=>'Ocultar solo el estado CFN','MOSTRAR'=>'Mostrar CFN','EXCLUIR'=>'Excluir CFN completamente'];
    return $labels[strtoupper((string)$mode)] ?? (string)$mode;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Configuración de alarmas · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260908-alarm-policy-1">
  <style>
    .alarmCfg{padding:0 22px 28px;max-width:1120px}.alarmCfg__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.alarmMode{display:block;position:relative;background:var(--surface,#fff);border:1px solid var(--line-mid,#d7e0e5);border-radius:14px;padding:18px;cursor:pointer;min-height:190px;transition:.16s ease}.alarmMode:hover{border-color:var(--petrol,#15566a);transform:translateY(-1px)}.alarmMode:has(input:checked){border:2px solid var(--petrol,#15566a);box-shadow:0 0 0 3px var(--petrol-soft,#e5f2f5);padding:17px}.alarmMode input{position:absolute;right:16px;top:16px;width:18px;height:18px;accent-color:var(--petrol,#15566a)}.alarmMode h2{font-size:17px;margin:0 34px 8px 0;color:var(--text,#163747)}.alarmMode p{font-size:13px;line-height:1.55;color:var(--text-soft,#526c78);margin:0}.alarmMode__tag{display:inline-block;margin-top:13px;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#e9f7ef;color:#187248}.alarmMode--warn .alarmMode__tag{background:#fff3d7;color:#9a5b00}.alarmMode--danger .alarmMode__tag{background:#fee9e7;color:#b42318}.alarmCfg__actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:18px 0}.alarmCfg button{border:0;border-radius:8px;padding:11px 18px;font-weight:700;cursor:pointer}.alarmCfg button:disabled{opacity:.55;cursor:not-allowed}.alarmCfg__save{background:var(--petrol,#15566a);color:#fff}.alarmCfg__rebuild{background:var(--surface,#fff);color:var(--petrol,#15566a);border:1px solid var(--line-mid,#d7e0e5)!important}.alarmCfg__notice{font-size:13px;color:var(--text-soft,#526c78)}.alarmStatus{background:var(--surface,#fff);border:1px solid var(--line-mid,#d7e0e5);border-radius:14px;padding:18px}.alarmStatus__head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px}.alarmStatus__head h2{font-size:17px;margin:0}.alarmStatus__badge{padding:5px 10px;border-radius:999px;font-size:12px;font-weight:800;background:#e9f7ef;color:#187248}.alarmStatus__badge[data-state="PENDIENTE"],.alarmStatus__badge[data-state="EJECUTANDO"]{background:#fff3d7;color:#9a5b00}.alarmStatus__badge[data-state="ERROR"],.alarmStatus__badge[data-state="NO_INSTALADO"]{background:#fee9e7;color:#b42318}.alarmStatus__meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;font-size:13px}.alarmStatus__meta span{display:block;color:var(--text-mut,#78909a);font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px}.alarmStatus__detail{margin-top:13px;padding-top:13px;border-top:1px solid var(--line,#e8edef);font-size:13px;line-height:1.5;white-space:pre-wrap}.alarmCfg__error{margin:0 0 16px;padding:12px 14px;border:1px solid #efb7b2;background:#fee9e7;color:#9b231a;border-radius:9px}.alarmCfg__confirm{display:none;align-items:flex-start;gap:8px;margin:-5px 0 15px;padding:12px;border-radius:9px;background:#fff3d7;color:#764500;font-size:13px}.alarmCfg__confirm.is-visible{display:flex}.alarmCfg__confirm input{margin-top:2px}.alarmCfg__result{min-height:20px;font-size:13px;font-weight:650}.alarmCfg__result.ok{color:#187248}.alarmCfg__result.err{color:#b42318}@media(max-width:900px){.alarmCfg__grid,.alarmStatus__meta{grid-template-columns:1fr}.alarmCfg{padding:0 14px 24px}}
  </style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page__head">
      <div><h1 class="page__title">Configuración de alarmas</h1><div class="page__sub">Política global para el estado técnico CFN</div></div>
      <div class="page__live"><span class="dot"></span>Solo administradores</div>
    </div>

    <section class="alarmCfg">
      <?php if (!$installed): ?>
        <div class="alarmCfg__error">Falta instalar <b>SQL/CLEAR_CONFIGURACION_GLOBAL_CFN_20260908.sql</b>. La configuración actual no fue modificada.</div>
      <?php endif; ?>

      <div class="alarmCfg__grid" role="radiogroup" aria-label="Modo global de CFN">
        <label class="alarmMode">
          <input type="radio" name="alarmMode" value="OCULTAR_ESTADO" <?php echo strtoupper((string)$policy['MODO_ACTIVO'])==='OCULTAR_ESTADO'?'checked':''; ?> <?php echo !$installed?'disabled':''; ?>>
          <h2>Ocultar solo el estado CFN</h2>
          <p>Conserva todos los eventos y sus valores, incluida <b>Falla</b>, pero no muestra CFN como estado en grillas, filtros, gráficos ni reportes.</p>
          <span class="alarmMode__tag">Recomendado</span>
        </label>
        <label class="alarmMode alarmMode--warn">
          <input type="radio" name="alarmMode" value="MOSTRAR" <?php echo strtoupper((string)$policy['MODO_ACTIVO'])==='MOSTRAR'?'checked':''; ?> <?php echo !$installed?'disabled':''; ?>>
          <h2>Mostrar CFN</h2>
          <p>Conserva los eventos y además permite ver CFN como estado en tablas, filtros y estadísticas de estado.</p>
          <span class="alarmMode__tag">Diagnóstico técnico</span>
        </label>
        <label class="alarmMode alarmMode--danger">
          <input type="radio" name="alarmMode" value="EXCLUIR" <?php echo strtoupper((string)$policy['MODO_ACTIVO'])==='EXCLUIR'?'checked':''; ?> <?php echo !$installed?'disabled':''; ?>>
          <h2>Excluir CFN completamente</h2>
          <p>Elimina del análisis toda fila cuyo estado sea CFN. Puede reducir u ocultar conteos de <b>Falla</b> porque esas activaciones pueden venir en filas CFN.</p>
          <span class="alarmMode__tag">Usar con precaución</span>
        </label>
      </div>

      <label class="alarmCfg__confirm" id="excludeConfirm"><input type="checkbox" id="excludeCheck"> <span>Confirmo que entiendo que la exclusión completa puede quitar alarmas en Falla de los análisis.</span></label>
      <div class="alarmCfg__actions">
        <button type="button" class="alarmCfg__save" id="savePolicy" <?php echo !$installed?'disabled':''; ?>>Guardar configuración</button>
        <button type="button" class="alarmCfg__rebuild" id="rebuildPolicy" <?php echo !$installed?'disabled':''; ?>>Recalcular todas las cachés</button>
        <span class="alarmCfg__notice">La solicitud se procesa en segundo plano; SQL Agent la toma en hasta un minuto.</span>
      </div>
      <div class="alarmCfg__result" id="actionResult" aria-live="polite"></div>

      <article class="alarmStatus">
        <div class="alarmStatus__head"><h2>Estado de aplicación</h2><span class="alarmStatus__badge" id="policyState" data-state="<?php echo h($policy['ESTADO']); ?>"><?php echo h($policy['ESTADO']); ?></span></div>
        <div class="alarmStatus__meta">
          <div><span>Modo activo</span><b id="policyMode"><?php echo h(alarm_policy_mode_label($policy['MODO_ACTIVO'])); ?></b></div>
          <div><span>Solicitado por</span><b id="policyUser"><?php echo h($policy['SOLICITADO_POR'] ?: '—'); ?></b></div>
          <div><span>Solicitud</span><b id="policyRequested"><?php echo h($policy['SOLICITADO_EN'] ?: '—'); ?></b></div>
          <div><span>Inicio</span><b id="policyStarted"><?php echo h($policy['INICIADO_EN'] ?: '—'); ?></b></div>
          <div><span>Finalización</span><b id="policyFinished"><?php echo h($policy['FINALIZADO_EN'] ?: '—'); ?></b></div>
          <div><span>Tipo</span><b id="policyFull"><?php echo !empty($policy['RECALCULO_COMPLETO'])?'Recálculo completo':'Actualización liviana'; ?></b></div>
        </div>
        <div class="alarmStatus__detail" id="policyDetail"><?php echo h($policy['ULTIMO_ERROR'] ?: ($policy['DETALLE'] ?: 'Sin tareas pendientes.')); ?></div>
      </article>
    </section>
  </main>
</div>
<script src="assets/js/app.js"></script>
<script>
(function(){
  var installed=<?php echo $installed?'true':'false'; ?>;
  if(!installed)return;
  var csrf=<?php echo json_encode($csrf); ?>;
  var labels={OCULTAR_ESTADO:'Ocultar solo el estado CFN',MOSTRAR:'Mostrar CFN',EXCLUIR:'Excluir CFN completamente'};
  var save=document.getElementById('savePolicy'),rebuild=document.getElementById('rebuildPolicy');
  var confirmBox=document.getElementById('excludeConfirm'),confirmCheck=document.getElementById('excludeCheck');
  var result=document.getElementById('actionResult'),timer=null;
  function selected(){var el=document.querySelector('input[name="alarmMode"]:checked');return el?el.value:'OCULTAR_ESTADO';}
  function renderConfirm(){var excluding=selected()==='EXCLUIR';confirmBox.classList.toggle('is-visible',excluding);if(!excluding)confirmCheck.checked=false;}
  document.querySelectorAll('input[name="alarmMode"]').forEach(function(el){el.addEventListener('change',renderConfirm);});renderConfirm();
  function text(id,value){document.getElementById(id).textContent=value||'—';}
  function render(data){
    if(!data||!data.policy)return;var p=data.policy,state=(p.ESTADO||'').toUpperCase();
    document.querySelectorAll('input[name="alarmMode"]').forEach(function(el){el.checked=el.value===p.MODO_ACTIVO;});renderConfirm();
    text('policyMode',labels[p.MODO_ACTIVO]||p.MODO_ACTIVO);text('policyUser',p.SOLICITADO_POR);text('policyRequested',p.SOLICITADO_EN);
    text('policyStarted',p.INICIADO_EN);text('policyFinished',p.FINALIZADO_EN);text('policyFull',Number(p.RECALCULO_COMPLETO)?'Recálculo completo':'Actualización liviana');
    var badge=document.getElementById('policyState');badge.textContent=state||'DESCONOCIDO';badge.setAttribute('data-state',state);
    text('policyDetail',p.ULTIMO_ERROR||p.DETALLE||'Sin tareas pendientes.');
    var busy=state==='PENDIENTE'||state==='EJECUTANDO';save.disabled=busy;rebuild.disabled=busy;
    if(busy){window.clearTimeout(timer);timer=window.setTimeout(loadStatus,3500);}
  }
  function loadStatus(){fetch('config_alarmas_api.php',{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json();}).then(render).catch(function(){});}
  function send(action){
    if(selected()==='EXCLUIR'&&!confirmCheck.checked){result.className='alarmCfg__result err';result.textContent='Confirmá la advertencia antes de excluir CFN.';return;}
    save.disabled=true;rebuild.disabled=true;result.className='alarmCfg__result';result.textContent='Enviando solicitud…';
    var body=new FormData();body.append('csrf',csrf);body.append('action',action);body.append('mode',selected());body.append('confirm_exclude',confirmCheck.checked?'1':'0');
    fetch('config_alarmas_api.php',{method:'POST',body:body,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.json().then(function(j){if(!r.ok)throw new Error(j.error||'No se pudo guardar.');return j;});})
      .then(function(data){result.className='alarmCfg__result ok';result.textContent=data.message||'Solicitud registrada.';render(data);})
      .catch(function(err){result.className='alarmCfg__result err';result.textContent=err.message;save.disabled=false;rebuild.disabled=false;});
  }
  save.addEventListener('click',function(){send('set');});rebuild.addEventListener('click',function(){send('rebuild');});
  loadStatus();
})();
</script>
</body>
</html>
