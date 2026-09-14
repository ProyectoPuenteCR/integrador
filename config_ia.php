<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/ai_analysis.php';

auth_require();
if (!auth_es_admin()) {
    http_response_code(403);
    exit('Acceso restringido a administradores.');
}

ai_analysis_ensure_tables();
$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'America/Argentina/Buenos_Aires');
$APP_USER = auth_user();
$APP_ROLE = 'Administrador';
$ACTIVE = 'config_ia';
$settings = ai_analysis_settings();

function cia_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Configuración de IA · CLEAR</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260716-ai-admin1">
  <style>
    .iaAdminGrid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:18px;align-items:start}
    .iaAdminPanel{background:var(--surface,#fff);border:1px solid var(--line-mid);border-top:3px solid var(--petrol);border-radius:14px;padding:20px}
    .iaAdminPanel h2{font-family:var(--font-head);font-size:22px;margin:0 0 6px;color:var(--text)}
    .iaAdminPanel__sub{font-size:13px;color:var(--text-mut);margin-bottom:18px}
    .iaAdminForm{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
    .iaAdminField{display:grid;gap:6px;min-width:0}
    .iaAdminField--wide{grid-column:1/-1}
    .iaAdminField label{font-size:12px;font-weight:800;color:var(--text-soft)}
    .iaAdminField input,.iaAdminField select{width:100%;min-width:0;padding:11px 12px;border:1px solid var(--line-mid);border-radius:9px;background:var(--surface,#fff);color:var(--text);font:inherit}
    .iaAdminHint{font-size:11px;color:var(--text-mut);line-height:1.45}
    .iaAdminActions{grid-column:1/-1;display:flex;gap:9px;flex-wrap:wrap;padding-top:3px}
    .iaAdminProviderCards{display:grid;gap:10px;margin-top:14px}
    .iaAdminProviderCard{border:1px solid var(--line-mid);border-radius:11px;padding:12px;background:var(--surface-soft,#f7fafb)}
    .iaAdminProviderCard b{display:block;color:var(--petrol);margin-bottom:4px}.iaAdminProviderCard span{font-size:12px;color:var(--text-mut);line-height:1.4}
    .iaAdminSecurity{margin-top:14px;background:var(--petrol-soft);border:1px solid var(--petrol-line);border-radius:10px;padding:13px;font-size:12px;color:var(--text-soft);line-height:1.45}
    .iaStatus{display:none;margin-bottom:14px}.iaStatus.is-visible{display:block}
    .iaKeyState{display:flex;align-items:center;gap:7px;font-size:11px;color:var(--text-mut)}
    .iaKeyDot{width:8px;height:8px;border-radius:50%;background:#aab5bc}.iaKeyDot.ok{background:#0ea56b}
    @media(max-width:950px){.iaAdminGrid{grid-template-columns:1fr}}
    @media(max-width:650px){.iaAdminForm{grid-template-columns:1fr}.iaAdminField--wide,.iaAdminActions{grid-column:auto}}
  </style>
</head>
<body>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page__head">
      <div>
        <h1 class="page__title">Configuración de Inteligencia Artificial</h1>
        <div class="page__sub">Administración de proveedor, credenciales y ejecución diaria</div>
      </div>
      <div class="page__live"><span class="dot"></span>Solo administradores</div>
    </div>

    <div id="iaAdminStatus" class="status iaStatus"></div>

    <div class="iaAdminGrid">
      <section class="iaAdminPanel">
        <h2>Proveedor y conexión</h2>
        <div class="iaAdminPanel__sub">La configuración se aplica al diagnóstico semanal y a las ejecuciones automáticas.</div>

        <form id="iaAdminForm" class="iaAdminForm" autocomplete="off">
          <input type="hidden" name="action" value="save_settings">

          <div class="iaAdminField">
            <label for="AI_ENABLED">Estado</label>
            <select id="AI_ENABLED" name="AI_ENABLED">
              <option value="1" <?php echo $settings['enabled'] ? 'selected' : ''; ?>>Activo</option>
              <option value="0" <?php echo !$settings['enabled'] ? 'selected' : ''; ?>>Desactivado</option>
            </select>
          </div>

          <div class="iaAdminField">
            <label for="AI_PROVIDER">Proveedor de IA</label>
            <select id="AI_PROVIDER" name="AI_PROVIDER">
              <option value="openai" <?php echo $settings['provider']==='openai'?'selected':''; ?>>OpenAI</option>
              <option value="gemini" <?php echo $settings['provider']==='gemini'?'selected':''; ?>>Google Gemini</option>
              <option value="anthropic" <?php echo $settings['provider']==='anthropic'?'selected':''; ?>>Anthropic Claude</option>
              <option value="openai_compatible" <?php echo $settings['provider']==='openai_compatible'?'selected':''; ?>>Compatible con OpenAI</option>
              <option value="custom" <?php echo $settings['provider']==='custom'?'selected':''; ?>>IA local / personalizada</option>
            </select>
          </div>

          <div class="iaAdminField iaAdminField--wide">
            <label for="AI_ENDPOINT">Endpoint</label>
            <input id="AI_ENDPOINT" name="AI_ENDPOINT" value="<?php echo cia_h($settings['endpoint']); ?>" spellcheck="false">
            <div class="iaAdminHint" id="endpointHint"></div>
          </div>

          <div class="iaAdminField">
            <label for="AI_MODEL">Modelo</label>
            <input id="AI_MODEL" name="AI_MODEL" value="<?php echo cia_h($settings['model']); ?>" spellcheck="false">
          </div>

          <div class="iaAdminField">
            <label for="AI_RUN_HOUR">Hora diaria</label>
            <input id="AI_RUN_HOUR" type="number" min="0" max="23" name="AI_RUN_HOUR" value="<?php echo cia_h($settings['hour']); ?>">
          </div>

          <div class="iaAdminField iaAdminField--wide">
            <label for="AI_API_KEY">API key</label>
            <input id="AI_API_KEY" type="password" name="AI_API_KEY" value="" placeholder="Dejar vacío para conservar la clave actual" autocomplete="new-password">
            <div class="iaKeyState"><span class="iaKeyDot <?php echo $settings['api_key_configured']?'ok':''; ?>"></span><?php echo $settings['api_key_configured'] ? 'Hay una clave configurada. Solo se reemplaza si ingresás una nueva.' : 'Todavía no hay una clave configurada.'; ?></div>
          </div>

          <div class="iaAdminField iaAdminField--wide" id="customAuthField">
            <label for="AI_AUTH_HEADER">Encabezado de autenticación personalizado</label>
            <input id="AI_AUTH_HEADER" name="AI_AUTH_HEADER" value="<?php echo cia_h($settings['auth_header']); ?>" placeholder="Ej. Authorization: Bearer {API_KEY}">
            <div class="iaAdminHint">Solo se usa para IA local/personalizada. Podés usar <b>{API_KEY}</b> como marcador.</div>
          </div>

          <div class="iaAdminActions">
            <button class="btn primary" type="submit">Guardar configuración</button>
            <button class="btn" type="button" id="testAiConnection">Probar conexión</button>
            <button class="btn" type="button" id="runAiNow">Generar diagnóstico ahora</button>
          </div>
        </form>

        <div class="iaAdminSecurity">Los operadores pueden consultar los diagnósticos, pero no ver ni modificar el proveedor, el endpoint, el modelo ni la API key. La clave nunca se devuelve completa al navegador.</div>
      </section>

      <aside class="iaAdminPanel">
        <h2>Formatos compatibles</h2>
        <div class="iaAdminPanel__sub">El sistema adapta automáticamente la solicitud y la lectura de la respuesta.</div>
        <div class="iaAdminProviderCards">
          <div class="iaAdminProviderCard"><b>OpenAI</b><span>Usa Responses API con autenticación Bearer.</span></div>
          <div class="iaAdminProviderCard"><b>Google Gemini</b><span>Usa generateContent y autenticación mediante x-goog-api-key.</span></div>
          <div class="iaAdminProviderCard"><b>Anthropic Claude</b><span>Usa Messages API con x-api-key y anthropic-version.</span></div>
          <div class="iaAdminProviderCard"><b>Compatible con OpenAI</b><span>Para gateways o servidores que implementen formato Responses o Chat Completions.</span></div>
          <div class="iaAdminProviderCard"><b>IA local / personalizada</b><span>Permite endpoint propio y encabezado de autenticación configurable.</span></div>
        </div>
      </aside>
    </div>
  </main>
</div>
<script src="assets/js/app.js?v=20260716-ai-admin1"></script>
<script>
(function(){
  var form=document.getElementById('iaAdminForm');
  var provider=document.getElementById('AI_PROVIDER');
  var endpoint=document.getElementById('AI_ENDPOINT');
  var model=document.getElementById('AI_MODEL');
  var hint=document.getElementById('endpointHint');
  var customAuth=document.getElementById('customAuthField');
  var status=document.getElementById('iaAdminStatus');
  var defaults={
    openai:{endpoint:'https://api.openai.com/v1/responses',model:'gpt-5-mini',hint:'OpenAI Responses API.'},
    gemini:{endpoint:'https://generativelanguage.googleapis.com/v1beta/models/{MODEL}:generateContent',model:'gemini-2.5-flash',hint:'Usá {MODEL} en el endpoint; el sistema lo reemplaza por el modelo configurado.'},
    anthropic:{endpoint:'https://api.anthropic.com/v1/messages',model:'claude-sonnet-4-5',hint:'Anthropic Messages API.'},
    openai_compatible:{endpoint:'http://servidor-ia/v1/chat/completions',model:'modelo-local',hint:'Compatible con Responses o Chat Completions de OpenAI.'},
    custom:{endpoint:'http://servidor-ia/api/generate',model:'modelo-personalizado',hint:'Endpoint personalizado. Se enviará un JSON con model e input.'}
  };
  var previous=provider.value;
  function renderProvider(changeDefaults){
    var value=provider.value, cfg=defaults[value]||defaults.custom;
    if(changeDefaults){endpoint.value=cfg.endpoint;model.value=cfg.model;}
    hint.textContent=cfg.hint;
    customAuth.style.display=value==='custom'?'grid':'none';
    previous=value;
  }
  function show(message,ok){
    status.textContent=message;
    status.className='status iaStatus is-visible '+(ok?'ok':'error');
    window.scrollTo({top:0,behavior:'smooth'});
  }
  function post(action,extra){
    var fd=extra||new FormData();fd.set('action',action);
    return fetch('config_ia_api.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'})
      .then(function(r){return r.json().then(function(d){if(!r.ok||!d.ok)throw new Error(d.error||'Error');return d;});});
  }
  provider.addEventListener('change',function(){renderProvider(true);});
  renderProvider(false);
  form.addEventListener('submit',function(e){e.preventDefault();post('save_settings',new FormData(form)).then(function(d){show(d.message,true);document.getElementById('AI_API_KEY').value='';}).catch(function(e){show(e.message,false);});});
  document.getElementById('testAiConnection').addEventListener('click',function(){var fd=new FormData(form);show('Probando conexión con el proveedor…',true);post('test_connection',fd).then(function(d){show(d.message,true);}).catch(function(e){show(e.message,false);});});
  document.getElementById('runAiNow').addEventListener('click',function(){show('Generando diagnóstico…',true);post('run_now').then(function(d){show(d.message,true);}).catch(function(e){show(e.message,false);});});
})();
</script>
</body>
</html>
