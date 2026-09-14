<?php
/* =============================================================
   pi_webid.php — Ayuda a encontrar el WebID del Data Server de PI.
   Solo para administradores. Borralo cuando ya tengas el WebID.
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/appconfig.php';
require_once __DIR__ . '/includes/icons.php';

auth_require_admin();

$pi = pi_config();
$PI_BASE = $pi['base'];
$PI_AUTH = pi_auth_header();
$ACTIVE = '';
$APP_USER = auth_user();
$APP_ROLE = 'Administrador';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Buscar WebID · CLEAR</title>
  <link rel="stylesheet" href="assets/css/app.css">
  <style>
    .wid-card{max-width:720px;background:#fff;border:1px solid var(--line-mid);border-top:3px solid var(--petrol);border-radius:var(--radius);padding:24px;margin:20px}
    .wid-card h2{font-family:var(--font-head);color:var(--text);margin-bottom:8px}
    .wid-item{background:#f7fafc;border:1px solid var(--line-mid);border-radius:8px;padding:14px;margin-bottom:12px}
    .wid-item .name{font-weight:600;color:var(--petrol);font-size:15px;margin-bottom:6px}
    .wid-item .webid{font-family:monospace;font-size:13px;color:var(--text);word-break:break-all;background:#fff;padding:8px;border:1px solid var(--line);border-radius:6px}
    .wid-status{padding:11px 14px;border-radius:8px;margin:12px 0;font-size:14px}
    .wid-status.err{background:var(--red-soft);color:var(--red-tx)}
    .wid-status.ok{background:var(--green-soft);color:var(--green-tx)}
    .copybtn{margin-top:8px;padding:7px 14px;background:var(--petrol);color:#fff;border:none;border-radius:7px;font-size:13px;cursor:pointer}
  </style>
</head>
<body>
<div class="wid-card">
  <h2>Buscar WebID del Data Server</h2>
  <p style="color:var(--text-mut);font-size:14px;margin-bottom:16px">
    Esta herramienta consulta tu PI Web API y lista los Data Servers disponibles con su WebID.
    Copiá el WebID del servidor correcto y pegalo en "Conexión PI Web API".
  </p>
  <div id="resultado"><div class="wid-status">Consultando <?php echo h($PI_BASE); ?>/dataservers …</div></div>
  <a href="config_pi.php" style="color:var(--petrol);font-size:14px">← Volver a Conexión PI</a>
</div>

<script>
var PI_BASE = <?php echo json_encode($PI_BASE); ?>;
var AUTH = <?php echo json_encode($PI_AUTH); ?>;

var xhr = new XMLHttpRequest();
xhr.open('GET', 'pi_proxy.php?path=' + encodeURIComponent('/dataservers'), true);
xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
xhr.timeout = 10000;
xhr.onreadystatechange = function(){
  if(xhr.readyState===4){
    var cont = document.getElementById('resultado');
    if(xhr.status===200){
      try{
        var data = JSON.parse(xhr.responseText.replace(/^\uFEFF/,''));
        if(data.Items && data.Items.length){
          var html = '<div class="wid-status ok">✓ Se encontraron '+data.Items.length+' Data Server(s). Copiá el WebID del que uses:</div>';
          for(var i=0;i<data.Items.length;i++){
            var it = data.Items[i];
            html += '<div class="wid-item"><div class="name">'+(it.Name||'(sin nombre)')+'</div>'+
                    '<div class="webid" id="wid'+i+'">'+it.WebId+'</div>'+
                    '<button class="copybtn" onclick="copiar(\'wid'+i+'\')">Copiar WebID</button></div>';
          }
          cont.innerHTML = html;
        } else {
          cont.innerHTML = '<div class="wid-status err">La respuesta no tiene Data Servers.</div>';
        }
      }catch(e){
        cont.innerHTML = '<div class="wid-status err">Error al leer la respuesta: '+e.message+'</div>';
      }
    } else if(xhr.status===0){
      cont.innerHTML = '<div class="wid-status err">No se pudo conectar. Abrí <a href="'+PI_BASE+'/dataservers" target="_blank">'+PI_BASE+'/dataservers</a> en otra pestaña, aceptá el certificado, y recargá esta página.</div>';
    } else if(xhr.status===401){
      cont.innerHTML = '<div class="wid-status err">Usuario o contraseña de PI incorrectos (401). Revisá Conexión PI.</div>';
    } else {
      cont.innerHTML = '<div class="wid-status err">Error HTTP '+xhr.status+'.</div>';
    }
  }
};
xhr.ontimeout = function(){ document.getElementById('resultado').innerHTML = '<div class="wid-status err">Tiempo de espera agotado.</div>'; };
xhr.send();

function copiar(id){
  var txt = document.getElementById(id).textContent;
  navigator.clipboard.writeText(txt).then(function(){ alert('WebID copiado:\n'+txt); });
}
</script>
</body>
</html>
