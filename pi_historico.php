<?php
/* =============================================================
   CLEAR PLATAFORMA — pi_historico.php
   Buscador de tags + gráfico histórico desde PI Web API.
   La lógica de búsqueda/gráfico corre en el navegador (XHR a PI),
   igual que en el PHPRunner original. Reestilizada al tema nuevo.
============================================================= */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/appconfig.php';
require_once __DIR__ . '/includes/icons.php';

auth_require(); permissions_require_menu('pi_historico');

$cfg = require __DIR__ . '/config.php';
$APP_USER = auth_user();
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Operador';
$ACTIVE = 'pi_historico';
$EMBEDDED = isset($_GET['embedded']) && $_GET['embedded'] === '1';
$initialTag = trim((string)($_GET['tag'] ?? ''));
$initialQuery = trim((string)($_GET['query'] ?? ''));
$initialAlarmTag = trim((string)($_GET['alarm_tag'] ?? $initialTag));

// Config de PI desde la tabla CLEAR_CONFIG (con respaldo a config.php)
$pi = pi_config();
$PI_BASE  = $pi['base'];
$DS_WEBID = $pi['ds_webid'];
$PI_AUTH  = pi_auth_header();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PI Histórico · CLEAR Plataforma</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260714-22">
  <script src="assets/js/chart.umd.js"></script>
  <script src="assets/js/chartjs-plugin-zoom.min.js"></script>
  <style>
    .pi-grid { display:grid; grid-template-columns: 360px 1fr; gap:20px; align-items:start; }
    @media (max-width:1000px){ .pi-grid{ grid-template-columns:1fr; } }
    .pi-card { background:#fff; border:1px solid var(--line-mid); border-top:3px solid var(--petrol); border-radius:var(--radius); padding:20px; }
    .pi-card h3 { font-family:var(--font-head); font-size:18px; font-weight:700; color:var(--text); margin-bottom:16px; letter-spacing:.5px; }
    .pi-field { margin-bottom:14px; position:relative; }
    .pi-field label { display:block; font-size:13px; color:var(--text-soft); margin-bottom:5px; font-weight:500; }
    .pi-field input, .pi-field select {
      width:100%; padding:10px 12px; font-size:14px;
      background:#f7fafc; border:1px solid var(--line-mid); border-radius:var(--radius-sm);
      color:var(--text); outline:none; font-family:var(--font-ui);
    }
    .pi-field input:focus, .pi-field select:focus { border-color:var(--petrol); box-shadow:0 0 0 3px var(--petrol-soft); }
    .btn-primary { width:100%; padding:11px; background:var(--petrol); color:#fff; border:none; border-radius:var(--radius-sm); font-size:14px; font-weight:600; cursor:pointer; }
    .btn-primary:hover { background:var(--petrol-dark); }
    .pi-selectedTag { margin:10px 0 12px; padding:12px 14px; border:1px solid rgba(21,182,126,.24); border-left:4px solid var(--green); border-radius:12px; background:var(--green-soft); }
    .pi-selectedTag__label { display:block; margin-bottom:4px; color:var(--green-tx); font-size:11px; font-weight:700; letter-spacing:.8px; text-transform:uppercase; }
    .pi-selectedTag__value { color:var(--green-tx); font-size:16px; font-weight:700; line-height:1.3; overflow-wrap:anywhere; }
    .btn-primary.btn-primary--green { background:var(--green); margin-bottom:12px; }
    .btn-primary.btn-primary--green:hover { filter:brightness(.94); }
    .pi-rangeCustom { margin-top:12px; padding:12px; border:1px solid var(--line-mid); border-radius:12px; background:#f8fbfc; }
    .pi-rangeCustom__title { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:9px; }
    .pi-rangeCustom__title strong { color:var(--petrol); font-size:12px; }
    .pi-rangeCustom__hint { margin-top:8px; color:var(--text-mut); font-size:10.5px; line-height:1.45; }
    .pi-rangeRow { display:grid; grid-template-columns:1fr 94px; gap:7px; margin-bottom:8px; }
    .pi-rangeRow:last-of-type { margin-bottom:0; }
    .pi-rangeRow label { display:block; margin:0; }
    .pi-rangeRow label span { display:block; margin-bottom:4px; color:var(--text-mut); font-size:9px; font-weight:700; letter-spacing:.65px; text-transform:uppercase; }
    .pi-rangeRow input { width:100%; padding:8px 9px; background:#fff; border:1px solid var(--line-mid); border-radius:8px; color:var(--text); font-family:var(--font-ui); font-size:12px; outline:none; }
    .pi-rangeRow input:focus { border-color:var(--petrol); box-shadow:0 0 0 2px var(--petrol-soft); }
    .pi-rangeClear { border:0; background:transparent; color:var(--text-mut); font-size:11px; cursor:pointer; padding:2px 0; }
    .pi-rangeClear:hover { color:var(--red); }
    .suggestions { display:none; position:absolute; top:100%; left:0; right:0; z-index:20; background:#fff; border:1px solid var(--line-mid); border-radius:0 0 var(--radius-sm) var(--radius-sm); max-height:280px; overflow-y:auto; box-shadow:0 8px 24px rgba(26,77,92,0.12); }
    .suggestions.visible { display:block; }
    .suggestion-count { padding:7px 12px; font-size:11px; color:var(--text-mut); background:#f7fafc; border-bottom:1px solid var(--line); }
    .suggestion-item { padding:9px 12px; font-size:13px; cursor:pointer; border-bottom:1px solid var(--line); color:var(--text); }
    .suggestion-item:hover { background:var(--petrol-soft); }
    .suggestion-item b { color:var(--petrol); }
    .status { margin-top:12px; font-size:13px; padding:9px 12px; border-radius:var(--radius-sm); display:none; }
    .status.ok    { display:block; background:var(--green-soft); color:var(--green-tx); }
    .status.error { display:block; background:var(--red-soft); color:var(--red-tx); }
    .status.warn  { display:block; background:var(--amber-soft); color:var(--amber-tx); }
    #similarWrap { display:none; margin-top:14px; }
    .pi-info { display:none; gap:24px; flex-wrap:wrap; align-items:center; margin-bottom:16px; padding:14px 18px; background:#f7fafc; border:1px solid var(--line-mid); border-radius:var(--radius-sm); }
    .pi-info .item { display:flex; flex-direction:column; gap:2px; }
    .pi-info .label { font-size:11px; color:var(--text-mut); text-transform:uppercase; letter-spacing:.5px; }
    .pi-info .value { font-size:15px; color:var(--petrol); font-weight:600; }
    .chart-wrap { background:#fff; border:1px solid var(--line-mid); border-radius:var(--radius); padding:18px; position:relative; }
    .chart-section-stack{display:flex;flex-direction:column;gap:14px}
    .chart-canvas-box { height:calc(100vh - 290px); min-height:460px; position:relative; }
    .alarm-chart-box{height:170px; min-height:170px; position:relative;}
    .alarm-chart-wrap{display:none; border-top:1px solid var(--line); padding-top:14px; margin-top:4px}
    .alarm-chart-wrap.visible{display:block}
    .alarm-chart-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}
    .alarm-chart-title strong{font-size:12px;color:#b42318;letter-spacing:.4px;text-transform:uppercase}
    .alarm-chart-title span{font-size:11px;color:var(--text-mut)}
    .alarm-chart-empty{display:none; padding:10px 12px; border:1px dashed rgba(239,68,68,.28); border-radius:10px; background:#fff8f7; color:#b42318; font-size:12px; margin-bottom:8px}
    .alarm-chart-empty.visible{display:block}
    .chart-toolbar { display:flex; align-items:center; justify-content:flex-end; gap:8px; margin-bottom:10px; }
    .chart-hint { font-size:11px; color:var(--text-mut); margin-right:auto; }
    .btn-zoom { display:inline-flex; align-items:center; gap:6px; padding:7px 13px; background:#fff; border:1px solid var(--line-mid); border-radius:var(--radius-sm); color:var(--text-soft); font-size:12.5px; cursor:pointer; transition:all .14s; }
    .btn-zoom:hover { border-color:var(--petrol); color:var(--petrol); }
    .btn-zoom svg { width:14px; height:14px; }
    .loader { display:none; }
    .loader.active { display:block; font-size:13px; color:var(--text-mut); padding:8px 0; }
    .alarmSeriesInfo{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 10px;padding:10px 12px;border:1px solid rgba(239,68,68,.2);border-radius:10px;background:#fff5f4;color:#b42318;font-size:12px}.alarmSeriesInfo b{color:#8f1d14}.alarmCommentModal__overlay{position:fixed;inset:0;z-index:201;background:rgba(12,35,44,.4)}.alarmCommentModal{position:fixed;z-index:202;left:50%;top:50%;width:min(520px,92vw);transform:translate(-50%,-50%);background:#fff;border:1px solid var(--line-mid);border-radius:16px;box-shadow:0 24px 60px rgba(12,35,44,.28);padding:20px}.alarmCommentModal h3{margin:0 0 6px;font-family:var(--font-head);color:var(--petrol);font-size:22px}.alarmCommentModal p{margin:0 0 14px;color:var(--text-soft);font-size:12px;line-height:1.5}.alarmCommentModal textarea{width:100%;min-height:120px;resize:vertical;border:1px solid var(--line-mid);border-radius:10px;padding:10px 12px;font:inherit}.alarmCommentModal__actions{display:flex;justify-content:flex-end;gap:10px;margin-top:12px}.alarmCommentModal__actions button{min-height:40px;padding:0 14px;border-radius:10px;cursor:pointer}.alarmCommentModal__cancel{border:1px solid var(--line-mid);background:#fff;color:var(--text)}.alarmCommentModal__save{border:0;background:var(--petrol);color:#fff;font-weight:700}.alarmCommentModal__meta{padding:9px 10px;margin-bottom:10px;border-radius:9px;background:#f7fafc;color:var(--text-soft);font-size:12px}.alarmCommentModal__status{margin-top:8px;font-size:12px;color:var(--red)}

    <?php if ($EMBEDDED): ?>
    body.pi-embedded { background:#fff; }
    .pi-embedded .app, .pi-embedded .main { min-height:100vh; }
    .pi-embedded .main { padding:0; }
    .pi-embedded .page__head { margin-bottom:16px; }
    .pi-embedded .pi-grid { grid-template-columns:300px 1fr; gap:16px; }
    .pi-embedded .pi-card, .pi-embedded .chart-wrap { border-radius:16px; }
    .pi-embedded .chart-canvas-box { height:calc(100vh - 300px); min-height:360px; }
    .pi-embedded .alarm-chart-box { height:150px; min-height:150px; }
    .pi-embedded .page__head { padding:0; }
    <?php endif; ?>
  </style>
</head>
<body class="<?php echo $EMBEDDED ? 'pi-embedded' : ''; ?>">
<?php if ($EMBEDDED): ?>
  <main class="main">
    <div class="page__head">
      <div>
        <h1 class="page__title">PI Histórico</h1>
        <div class="page__sub">Tendencia de tags desde PI Web API</div>
      </div>
      <div class="page__live"><span class="dot"></span>PI System</div>
    </div>

    <div class="pi-grid">
<?php else: ?>
<div class="app">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page__head">
      <div>
        <h1 class="page__title">PI Histórico</h1>
        <div class="page__sub">Tendencia de tags desde PI Web API</div>
      </div>
      <div class="page__live"><span class="dot"></span>PI System</div>
    </div>

    <div class="pi-grid">
<?php endif; ?>
      <!-- Panel de búsqueda -->
      <div class="pi-card">
        <h3>Buscar tag</h3>
        <div class="pi-field">
          <label>Nombre del tag (parcial)</label>
          <input type="text" id="tagInput" list="tags24hList" value="<?php echo h($initialTag); ?>" placeholder="Elegí de la lista o escribí…" autocomplete="off">
          <datalist id="tags24hList"></datalist>
          <div class="suggestions" id="suggestions"></div>
          <div id="tags24hInfo" style="font-size:11px;color:var(--text-mut);margin-top:5px"></div>
        </div>

        <div class="pi-selectedTag" id="selectedTagBox">
          <span class="pi-selectedTag__label">Tag seleccionado</span>
          <div class="pi-selectedTag__value" id="selectedTagValue"><?php echo h($initialTag !== '' ? $initialTag : 'Sin seleccionar'); ?></div>
        </div>

        <button class="btn-primary btn-primary--green" id="btnVer">Ver tag seleccionado</button>

        <div class="pi-field">
          <label>Rango rápido</label>
          <select id="rangeSelect">
            <option value="*-1m">Último minuto</option>
            <option value="*-10m">Últimos 10 minutos</option>
            <option value="*-30m">Últimos 30 minutos</option>
            <option value="*-1h">Última hora</option>
            <option value="*-8h" selected>Últimas 8 horas</option>
            <option value="*-1d">Último día</option>
            <option value="*-7d">Última semana</option>
            <option value="*-30d">Último mes</option>
          </select>
        </div>

        <div class="pi-rangeCustom">
          <div class="pi-rangeCustom__title">
            <strong>Rango personalizado</strong>
            <button type="button" class="pi-rangeClear" id="clearCustomRange">Limpiar fechas</button>
          </div>
          <div class="pi-rangeRow">
            <label for="customDateFrom">
              <span>Desde</span>
              <input type="date" id="customDateFrom" aria-label="Fecha desde">
            </label>
            <label for="customTimeFrom">
              <span>Hora</span>
              <input type="time" id="customTimeFrom" value="00:00" step="60" aria-label="Hora desde">
            </label>
          </div>
          <div class="pi-rangeRow">
            <label for="customDateTo">
              <span>Hasta</span>
              <input type="date" id="customDateTo" aria-label="Fecha hasta">
            </label>
            <label for="customTimeTo">
              <span>Hora</span>
              <input type="time" id="customTimeTo" value="23:59" step="60" aria-label="Hora hasta">
            </label>
          </div>
          <div class="pi-rangeCustom__hint">Si completás ambas fechas, este rango reemplaza al rango rápido seleccionado.</div>
        </div>

        <div class="status" id="status"></div>
        <div class="loader" id="loader">Cargando…</div>

        <div id="similarWrap">
          <label id="similarLabel" style="font-size:13px;color:var(--text-soft);display:block;margin-bottom:6px"></label>
          <select id="similarSelect" style="width:100%;padding:9px;border:1px solid var(--line-mid);border-radius:var(--radius-sm);margin-bottom:8px"></select>
          <button class="btn-primary" id="btnSimilar">Ver tag seleccionado</button>
        </div>
      </div>

      <!-- Gráfico -->
      <div>
        <div class="pi-info" id="tagInfo">
          <div class="item"><span class="label">Tag</span><span class="value" id="infoTag">-</span></div>
          <div class="item"><span class="label">Último valor</span><span class="value" id="infoValor">-</span></div>
          <div class="item"><span class="label">Unidad</span><span class="value" id="infoUnidad">-</span></div>
          <div class="item"><span class="label">Descripción</span><span class="value" id="infoDesc">-</span></div>
          <div class="item" style="margin-left:auto;justify-content:center">
            <button type="button" class="btn-export" id="btnExportPI">
              <?php echo icon('download'); ?> Exportar CSV
            </button>
          </div>
        </div>
        <div class="chart-wrap">
          <div class="chart-section-stack">
            <div>
              <div class="alarmSeriesInfo" id="alarmSeriesInfo" style="display:none"><span>Serie de alarma:</span><b id="alarmSeriesTag">-</b><span id="alarmSeriesCount"></span><span>Hacé clic sobre un evento rojo para comentar.</span></div>
              <div class="chart-toolbar" id="chartToolbar" style="display:none">
                <span class="chart-hint">Rueda del mouse para zoom · arrastrá para seleccionar · doble clic para acercar</span>
                <button type="button" class="btn-zoom" id="btnResetZoom">
                  <?php echo icon('search'); ?> Restablecer zoom
                </button>
              </div>
              <div class="chart-canvas-box">
                <canvas id="piCanvas"></canvas>
              </div>
            </div>
            <div class="alarm-chart-wrap" id="alarmChartWrap">
              <div class="alarm-chart-title">
                <strong>Gráfico de eventos / valor SQL</strong>
                <span id="alarmChartMeta">Visualización paralela del histórico SQL</span>
              </div>
              <div class="alarm-chart-empty" id="alarmChartEmpty">No se encontraron registros SQL para el rango seleccionado.</div>
              <div class="alarm-chart-box">
                <canvas id="alarmCanvas"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
<?php if (!$EMBEDDED): ?>
</div>
<?php endif; ?>
<div id="alarmCommentOverlay" class="alarmCommentModal__overlay" hidden></div>
<section id="alarmCommentModal" class="alarmCommentModal" hidden aria-labelledby="alarmCommentTitle">
  <h3 id="alarmCommentTitle">Comentario de alarma</h3>
  <div class="alarmCommentModal__meta" id="alarmCommentMeta"></div>
  <p>Este comentario aparecerá en el tooltip cuando pases el puntero sobre el evento.</p>
  <textarea id="alarmCommentText" maxlength="2000" placeholder="Escribí una observación sobre esta actuación..."></textarea>
  <div class="alarmCommentModal__status" id="alarmCommentStatus"></div>
  <div class="alarmCommentModal__actions"><button type="button" class="alarmCommentModal__cancel" id="alarmCommentCancel">Cancelar</button><button type="button" class="alarmCommentModal__save" id="alarmCommentSave">Guardar comentario</button></div>
</section>

<script>
// Registrar el plugin de zoom si está disponible (Chart.js v4)
try { if (window.ChartZoom) { Chart.register(window.ChartZoom); } } catch(e) {}
var PI_BASE  = <?php echo json_encode($PI_BASE); ?>;
var DS_WEBID = <?php echo json_encode($DS_WEBID); ?>;
// Con proxy: las llamadas van a pi_proxy.php (la credencial queda en el servidor).
var PROXY = 'pi_proxy.php?path=';
// Helper: arma la URL del proxy a partir de una ruta de PI (ej: '/dataservers/X/points?...')
function piURL(path){ return PROXY + encodeURIComponent(path); }
var searchTimer=null, sugerencias=[], tagSeleccionado=null, tagsSimilares=[], chartInstance=null, alarmChartInstance=null, datosGrafico=null;
var ALARM_TAG = <?php echo json_encode($initialAlarmTag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var alarmEvents=[], alarmEventByIndex={}, selectedAlarmEvent=null;
var INITIAL_TAG = <?php echo json_encode($initialTag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var INITIAL_QUERY = <?php echo json_encode($initialQuery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
function updateSelectedTagLabel(tag){ var el=document.getElementById('selectedTagValue'); if(el) el.textContent=(tag&&String(tag).trim()!=='')?String(tag).trim():'Sin seleccionar'; }

function ajaxGet(url, callback) {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', url, true);
  xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      if (xhr.status === 200) {
        try { callback(null, JSON.parse(xhr.responseText.replace(/^\uFEFF/, ''))); }
        catch(e) { callback('Error parseando respuesta: ' + e.message); }
      } else { callback('Error HTTP ' + xhr.status); }
    }
  };
  xhr.send();
}
function setStatus(msg, tipo) { var el=document.getElementById('status'); el.innerHTML=msg; el.className='status'+(tipo?' '+tipo:''); }
function cerrarSugerencias() { document.getElementById('suggestions').className='suggestions'; }
function highlightMatch(t,q){ var i=t.toLowerCase().indexOf(q.toLowerCase()); if(i<0)return t; return t.substring(0,i)+'<b>'+t.substring(i,i+q.length)+'</b>'+t.substring(i+q.length); }

function mostrarSugerencias(query) {
  var box=document.getElementById('suggestions');
  if(!sugerencias.length){cerrarSugerencias();return;}
  var html='<div class="suggestion-count">'+sugerencias.length+' resultado(s)</div>';
  for(var i=0;i<sugerencias.length;i++){
    var nombre=highlightMatch(sugerencias[i].Name,query);
    var desc=sugerencias[i].Descriptor?'<span style="color:var(--text-mut);font-size:11px;margin-left:8px">'+sugerencias[i].Descriptor+'</span>':'';
    html+='<div class="suggestion-item" data-idx="'+i+'">'+nombre+desc+'</div>';
  }
  box.innerHTML=html; box.className='suggestions visible';
  var items=box.getElementsByClassName('suggestion-item');
  for(var j=0;j<items.length;j++){ (function(idx){ items[idx].onclick=function(){seleccionarSugerencia(idx);}; })(j); }
}
function buscarTags(query, callback) {
  var url=piURL('/dataservers/'+DS_WEBID+'/points?nameFilter=*'+query+'*&maxCount=20');
  ajaxGet(url,function(err,data){
    if(err){
      cerrarSugerencias();
      if (callback) callback([]);
      return;
    }
    sugerencias=data.Items||[];
    if (!callback) mostrarSugerencias(query);
    if (callback) callback(sugerencias);
  });
}
function seleccionarSugerencia(idx){ var item=sugerencias[idx]; if(!item)return; tagSeleccionado=item; document.getElementById('tagInput').value=item.Name; updateSelectedTagLabel(item.Name); cerrarSugerencias(); cargarGrafico(); }
function onTagInput(){ var val=document.getElementById('tagInput').value; updateSelectedTagLabel(val); tagSeleccionado=null; clearTimeout(searchTimer); if(val.length<2){cerrarSugerencias();return;} searchTimer=setTimeout(function(){buscarTags(val);},350); }

function mostrarTagsSimilares(tags,query){
  tagsSimilares=tags; var sel=document.getElementById('similarSelect'); sel.innerHTML='';
  for(var i=0;i<tags.length;i++){ var o=document.createElement('option'); o.value=i; o.text=tags[i].Name+(tags[i].Descriptor?'  -  '+tags[i].Descriptor:''); sel.appendChild(o); }
  document.getElementById('similarLabel').innerHTML=tags.length+' tags similares a "'+query+'" — seleccioná uno:';
  document.getElementById('similarWrap').style.display='block';
}
function ocultarSimilares(){ document.getElementById('similarWrap').style.display='none'; tagsSimilares=[]; }
function cargarGraficoSimilar(){ var sel=document.getElementById('similarSelect'); var idx=sel.selectedIndex; if(idx<0||!tagsSimilares[idx]){setStatus('Seleccioná un tag de la lista.','error');return;} tagSeleccionado=tagsSimilares[idx]; document.getElementById('tagInput').value=tagSeleccionado.Name; updateSelectedTagLabel(tagSeleccionado.Name); ocultarSimilares(); cargarGrafico(); }

function hasNumericValues(values){
  if(!values||!values.length)return false;
  for(var i=0;i<values.length;i++){if(values[i]!==null&&typeof values[i]!=='undefined'&&!isNaN(Number(values[i])))return true;}
  return false;
}

function dibujarGrafico(labels,valores,tag,unidad){
  if(chartInstance){chartInstance.destroy();chartInstance=null;}
  var ctx=document.getElementById('piCanvas').getContext('2d');
  var hasPi=hasNumericValues(valores);
  chartInstance=new Chart(ctx,{
    type:'line',
    data:{labels:labels,datasets:[{
      label:tag,
      data:valores,
      borderColor:'#1a4d5c',
      backgroundColor:'rgba(26,77,92,0.08)',
      borderWidth:1.6,
      pointRadius:valores.length>200?0:2,
      pointHoverRadius:4,
      tension:0.1,
      fill:true,
      spanGaps:true,
      yAxisID:'y',
      seriesType:'pi'
    }]},
    options:{
      responsive:true,
      maintainAspectRatio:false,
      interaction:{mode:'nearest',axis:'x',intersect:false},
      plugins:{
        legend:{display:true,position:'top'},
        tooltip:{callbacks:{label:function(c){return c.parsed.y!==null?c.parsed.y.toFixed(2)+(unidad?' '+unidad:''):'N/A';}}},
        zoom:{
          pan:{enabled:true,mode:'x',modifierKey:'ctrl'},
          zoom:{wheel:{enabled:true},drag:{enabled:true,backgroundColor:'rgba(26,77,92,0.15)',borderColor:'#1a4d5c',borderWidth:1},pinch:{enabled:true},mode:'x'}
        }
      },
      scales:{
        x:{ticks:{color:'#8a99a8',maxRotation:45,autoSkip:true,maxTicksLimit:12,font:{size:11}},grid:{color:'rgba(26,77,92,0.06)'}},
        y:{position:'left',display:hasPi,ticks:{color:'#8a99a8',font:{size:11},callback:function(v){return Number(v).toFixed(1)+(unidad?' '+unidad:'');}},grid:{color:'rgba(26,77,92,0.06)'}}
      }
    }
  });
  document.getElementById('chartToolbar').style.display='flex';
  document.getElementById('btnResetZoom').onclick=function(){
    if(chartInstance)chartInstance.resetZoom();
    if(alarmChartInstance && typeof alarmChartInstance.resetZoom==='function')alarmChartInstance.resetZoom();
  };
}

function clearAlarmChart(){
  if(alarmChartInstance){alarmChartInstance.destroy();alarmChartInstance=null;}
  var wrap=document.getElementById('alarmChartWrap');
  var empty=document.getElementById('alarmChartEmpty');
  if(wrap)wrap.classList.remove('visible');
  if(empty)empty.classList.remove('visible');
}

function drawAlarmChart(labels,series,options){
  options=options||{};
  if(alarmChartInstance){alarmChartInstance.destroy();alarmChartInstance=null;}
  var wrap=document.getElementById('alarmChartWrap');
  var empty=document.getElementById('alarmChartEmpty');
  var meta=document.getElementById('alarmChartMeta');
  if(!wrap)return;
  wrap.classList.add('visible');
  if(meta)meta.textContent=options.meta||'Visualización paralela del histórico SQL';
  if(!series || !series.length || !hasNumericValues(series)){
    if(empty)empty.classList.add('visible');
  } else {
    if(empty)empty.classList.remove('visible');
  }
  var ctx=document.getElementById('alarmCanvas').getContext('2d');
  var numericMode=!!options.numericMode;
  var unit=options.unit||'';
  alarmChartInstance=new Chart(ctx,{
    type:'line',
    data:{labels:labels,datasets:[{
      label:numericMode?('Valor SQL'+(unit?' ('+unit+')':'')):'Eventos de alarma (SQL)',
      data:series,
      borderColor:'#ef3b30',
      backgroundColor:'rgba(239,59,48,.08)',
      borderWidth:2,
      pointRadius:series.length>160?2:3,
      pointHoverRadius:5,
      stepped:!numericMode,
      tension:numericMode?0.12:0,
      fill:false,
      spanGaps:true,
      yAxisID:'yAlarm',
      seriesType:'alarm'
    }]},
    options:{
      responsive:true,
      maintainAspectRatio:false,
      interaction:{mode:'nearest',axis:'x',intersect:false},
      onClick:function(evt,elements){
        if(!elements||!elements.length)return;
        var el=elements[0];
        var item=alarmEventByIndex[el.index];
        if(item)openAlarmComment(item);
      },
      plugins:{
        legend:{display:true,position:'top'},
        tooltip:{callbacks:{label:function(c){
          var item=alarmEventByIndex[c.dataIndex]||{};
          var valueText=numericMode?('Valor SQL: '+(item.numeric_value!==null&&typeof item.numeric_value!=='undefined'?Number(item.numeric_value).toFixed(2):c.parsed.y)+(unit?' '+unit:'')):'Evento SQL';
          var lines=[valueText];
          if(item.comment)lines.push('Comentario: '+item.comment);
          else lines.push('Sin comentario (clic para agregar)');
          return lines;
        }}},
        zoom:{pan:{enabled:true,mode:'x',modifierKey:'ctrl'},zoom:{wheel:{enabled:true},drag:{enabled:true,backgroundColor:'rgba(239,59,48,0.10)',borderColor:'#ef3b30',borderWidth:1},pinch:{enabled:true},mode:'x'}}
      },
      scales:{
        x:{ticks:{color:'#8a99a8',maxRotation:45,autoSkip:true,maxTicksLimit:12,font:{size:11}},grid:{color:'rgba(239,59,48,0.05)'}},
        yAlarm:numericMode?{
          position:'left',
          ticks:{color:'#ef3b30',font:{size:11},callback:function(v){return Number(v).toFixed(1)+(unit?' '+unit:'');}},
          grid:{color:'rgba(239,59,48,0.08)'}
        }:{
          position:'left',
          min:0,max:1.15,
          ticks:{stepSize:1,callback:function(v){return v===1?'Evento':''},color:'#ef3b30'},
          grid:{color:'rgba(239,59,48,0.08)'}
        }
      }
    }
  });
}

function parseNumericAlarmValue(item){
  if(item.numeric_value!==null && typeof item.numeric_value!=='undefined' && !isNaN(Number(item.numeric_value))) return Number(item.numeric_value);
  var raw=String(item.value==null?'':item.value).trim().replace(/\s+/g,'').replace(',','.');
  var match=raw.match(/[-+]?\d+(?:\.\d+)?/);
  if(match){
    item.numeric_value=Number(match[0]);
    if(!isNaN(item.numeric_value)) return item.numeric_value;
  }
  return null;
}

function fetchJsonSafe(url){
  return fetch(url,{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){
    return r.text().then(function(txt){
      var data=null;
      try{ data=JSON.parse(String(txt||'').replace(/^﻿/,'')); }
      catch(e){ throw new Error((String(txt||'').trim().slice(0,120)||'Respuesta no válida del servidor')); }
      if(!r.ok) throw new Error(data.error||'Error');
      return data;
    });
  });
}

function parseServerDate(value){
  if(value instanceof Date)return value;
  var text=String(value||'').trim();
  if(!text)return new Date(NaN);
  var dm=text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/);
  if(dm)return new Date(Number(dm[3]),Number(dm[2])-1,Number(dm[1]),Number(dm[4]||0),Number(dm[5]||0),Number(dm[6]||0));
  var sql=text.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
  if(sql)return new Date(Number(sql[1]),Number(sql[2])-1,Number(sql[3]),Number(sql[4]),Number(sql[5]),Number(sql[6]||0));
  return new Date(text);
}
function nearestTimestampIndex(timestamps,target){var best=-1,dist=Infinity,tt=parseServerDate(target).getTime();for(var i=0;i<timestamps.length;i++){var d=Math.abs(parseServerDate(timestamps[i]).getTime()-tt);if(d<dist){dist=d;best=i;}}return best;}
function makeAlarmDataset(series, numericMode, sqlUnit){return {label:numericMode?'Valor SQL'+(sqlUnit?' ('+sqlUnit+')':''):'Eventos de alarma (SQL)',data:series,borderColor:'#ef3b30',backgroundColor:'rgba(239,59,48,.06)',borderWidth:2,pointRadius:series.map(function(v){return v===null||typeof v==='undefined'?0:4;}),pointHoverRadius:7,stepped:!numericMode,tension:numericMode?0.12:0,fill:false,spanGaps:true,yAxisID:numericMode?'y':'yAlarm',sqlNumeric:!!numericMode};}
function showAlarmSeriesInfo(){var info=document.getElementById('alarmSeriesInfo');document.getElementById('alarmSeriesTag').textContent=ALARM_TAG||'-';document.getElementById('alarmSeriesCount').textContent='· '+alarmEvents.length+' registro(s) SQL';info.style.display='flex';}
function loadAlarmSeries(labels,timestamps,valores,tag,unidad){
  alarmEvents=[];alarmEventByIndex={};
  var info=document.getElementById('alarmSeriesInfo');
  clearAlarmChart();
  dibujarGrafico(labels,valores,tag,unidad);
  if(!ALARM_TAG){ if(info)info.style.display='none'; return; }

  var requestedRange=absoluteRangeForSql(getHistoricalRange());
  var url='alarm_events_api.php?'+new URLSearchParams({tag:ALARM_TAG,start:requestedRange.start,end:requestedRange.end}).toString();
  fetchJsonSafe(url).then(function(data){
    alarmEvents=(data.items||[]).slice().sort(function(a,b){return parseServerDate(a.timestamp)-parseServerDate(b.timestamp);});
    var hasNumeric=Number(data.numeric_count||0)>0;
    alarmEvents.forEach(function(item){
      var parsed=parseNumericAlarmValue(item);
      if(parsed!==null) hasNumeric=true;
    });
    showAlarmSeriesInfo();
    if(!alarmEvents.length){
      drawAlarmChart([],[],{numericMode:false,meta:'Sin registros SQL para el rango seleccionado'});
      return;
    }
    var sqlUnit='';
    var alarmLabels=[];
    var alarmSeries=[];
    alarmEventByIndex={};
    alarmEvents.forEach(function(item,idx){
      alarmLabels.push(formatChartLabel(item.timestamp));
      var numericValue=parseNumericAlarmValue(item);
      alarmSeries.push(hasNumeric && numericValue!==null ? numericValue : 1);
      if(!sqlUnit && item.unit) sqlUnit=item.unit;
      alarmEventByIndex[idx]=item;
    });
    drawAlarmChart(alarmLabels,alarmSeries,{numericMode:hasNumeric,unit:sqlUnit,meta:'Serie SQL paralela para '+ALARM_TAG});
  }).catch(function(error){
    if(info)info.style.display='none';
    clearAlarmChart();
    setStatus('PI disponible, pero no se pudieron cargar los valores SQL: '+(error.message||'Error de consulta.'),'warn');
  });
}

function absoluteRangeForSql(selectedRange){
  if(selectedRange.custom)return {start:selectedRange.start,end:selectedRange.end};
  var end=new Date();
  var start=new Date(end.getTime());
  var raw=String(selectedRange.start||'*-8h');
  var m=raw.match(/^\*-(\d+)([mhd])$/i);
  var amount=m?Number(m[1]):8;var unit=m?m[2].toLowerCase():'h';
  if(unit==='m')start.setMinutes(start.getMinutes()-amount);
  else if(unit==='d')start.setDate(start.getDate()-amount);
  else start.setHours(start.getHours()-amount);
  return {start:start.toISOString(),end:end.toISOString()};
}
function formatChartLabel(timestamp){var d=parseServerDate(timestamp);function p(n){return n<10?'0'+n:n;}return p(d.getDate())+'/'+p(d.getMonth()+1)+' '+p(d.getHours())+':'+p(d.getMinutes());}
function cargarSoloAlarmas(selectedRange,reason){
  document.getElementById('loader').className='loader active';
  clearAlarmChart();
  if(!ALARM_TAG){
    document.getElementById('loader').className='loader';
    setStatus(reason||'PI Web API no disponible y no se recibió un TAG de alarma para consultar SQL.','warn');
    return;
  }
  var range=absoluteRangeForSql(selectedRange||getHistoricalRange());
  var url='alarm_events_api.php?'+new URLSearchParams({tag:ALARM_TAG,start:range.start,end:range.end}).toString();
  fetchJsonSafe(url).then(function(data){
    document.getElementById('loader').className='loader';
    alarmEvents=(data.items||[]).slice().sort(function(a,b){return parseServerDate(a.timestamp)-parseServerDate(b.timestamp);});
    alarmEventByIndex={};
    var hasNumeric=Number(data.numeric_count||0)>0;
    alarmEvents.forEach(function(item){var parsed=parseNumericAlarmValue(item); if(parsed!==null) hasNumeric=true;});
    document.getElementById('infoTag').textContent=ALARM_TAG;
    document.getElementById('infoValor').textContent='PI no disponible';
    document.getElementById('infoUnidad').textContent='—';
    document.getElementById('infoDesc').textContent='Eventos obtenidos desde SQL Server';
    document.getElementById('tagInfo').style.display='flex';
    var emptyLabels=[]; var emptyValues=[];
    dibujarGrafico(emptyLabels,emptyValues,'PI Web API no disponible','');
    showAlarmSeriesInfo();
    if(!alarmEvents.length){
      drawAlarmChart([],[],{numericMode:false,meta:'Sin registros SQL para el rango seleccionado'});
    }else{
      var alarmLabels=[]; var alarmSeries=[]; var sqlUnit='';
      alarmEvents.forEach(function(item,idx){
        alarmLabels.push(formatChartLabel(item.timestamp));
        var numericValue=parseNumericAlarmValue(item);
        alarmSeries.push(hasNumeric && numericValue!==null ? numericValue : 1);
        if(!sqlUnit && item.unit) sqlUnit=item.unit;
        alarmEventByIndex[idx]=item;
      });
      drawAlarmChart(alarmLabels,alarmSeries,{numericMode:hasNumeric,unit:sqlUnit,meta:'Serie SQL paralela para '+ALARM_TAG});
    }
    datosGrafico={tag:ALARM_TAG,unidad:'',labels:[],valores:[],timestamps:[]};
    setStatus((reason?reason+' ':'')+'Se muestran '+alarmEvents.length+' evento(s) de alarma obtenidos desde SQL Server.','warn');
  }).catch(function(e){
    document.getElementById('loader').className='loader';
    setStatus((reason?reason+' ':'')+(e.message||'No se pudieron cargar los eventos SQL.'),'error');
  });
}
function openAlarmComment(item){selectedAlarmEvent=item;document.getElementById('alarmCommentMeta').textContent=(item.tag||ALARM_TAG)+' · '+(item.timestamp||'')+' · '+(item.value||'');document.getElementById('alarmCommentText').value=item.comment||'';document.getElementById('alarmCommentStatus').textContent='';document.getElementById('alarmCommentOverlay').hidden=false;document.getElementById('alarmCommentModal').hidden=false;document.getElementById('alarmCommentText').focus();}
function closeAlarmComment(){document.getElementById('alarmCommentOverlay').hidden=true;document.getElementById('alarmCommentModal').hidden=true;selectedAlarmEvent=null;}
function saveAlarmComment(){if(!selectedAlarmEvent)return;var txt=document.getElementById('alarmCommentText').value.trim();if(!txt){document.getElementById('alarmCommentStatus').textContent='Escribí un comentario.';return;}var data=new FormData();data.append('action','save_comment');data.append('event_key',selectedAlarmEvent.event_key);data.append('tag',selectedAlarmEvent.tag||ALARM_TAG);data.append('timestamp',selectedAlarmEvent.timestamp||'');data.append('value',selectedAlarmEvent.value||'');data.append('description',selectedAlarmEvent.description||'');data.append('comment',txt);fetch('alarm_events_api.php',{method:'POST',body:data,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json().then(function(d){if(!r.ok)throw new Error(d.error||'Error');return d;});}).then(function(d){selectedAlarmEvent.comment=txt;selectedAlarmEvent.user=d.user||'';selectedAlarmEvent.updated_at=d.saved_at||'';closeAlarmComment();if(chartInstance)chartInstance.update();}).catch(function(e){document.getElementById('alarmCommentStatus').textContent=e.message||'No se pudo guardar.';});}

function getHistoricalRange(){
  var fromDate=document.getElementById('customDateFrom').value;
  var fromTime=document.getElementById('customTimeFrom').value||'00:00';
  var toDate=document.getElementById('customDateTo').value;
  var toTime=document.getElementById('customTimeTo').value||'23:59';

  if((fromDate&&!toDate)||(!fromDate&&toDate)){
    return {error:'Para usar el rango personalizado completá las fechas Desde y Hasta.'};
  }
  if(fromDate&&toDate){
    var start=new Date(fromDate+'T'+fromTime+':00');
    var end=new Date(toDate+'T'+toTime+':59');
    if(isNaN(start.getTime())||isNaN(end.getTime())) return {error:'El rango personalizado no es válido.'};
    if(start.getTime()>end.getTime()) return {error:'La fecha Desde no puede ser posterior a la fecha Hasta.'};
    return {start:start.toISOString(),end:end.toISOString(),custom:true};
  }
  return {start:document.getElementById('rangeSelect').value,end:'*',custom:false};
}

function cargarHistorico(info){
  var selectedRange=getHistoricalRange();
  if(selectedRange.error){ setStatus(selectedRange.error,'error'); document.getElementById('loader').className='loader'; return; }
  setStatus('Obteniendo datos históricos…','');
  var dataUrl=piURL('/streams/'+info.webId+'/recorded?startTime='+selectedRange.start+'&endTime='+selectedRange.end+'&maxCount=1000');
  ajaxGet(dataUrl,function(err,data){
    document.getElementById('loader').className='loader';
    if(err){cargarSoloAlarmas(selectedRange,'PI Web API no disponible.');return;}
    if(!data.Items||!data.Items.length){cargarSoloAlarmas(selectedRange,'PI no devolvió datos para el rango seleccionado.');return;}
    var labels=[],valores=[],timestamps=[];
    for(var i=0;i<data.Items.length;i++){
      var d=new Date(data.Items[i].Timestamp);
      var txt=(d.getDate()<10?'0':'')+d.getDate()+'/'+(d.getMonth()<9?'0':'')+(d.getMonth()+1)+' '+(d.getHours()<10?'0':'')+d.getHours()+':'+(d.getMinutes()<10?'0':'')+d.getMinutes();
      labels.push(txt);
      timestamps.push(data.Items[i].Timestamp);
      var val=data.Items[i].Value, good=data.Items[i].Good;
      if(!good||val===null||typeof val==='object'){valores.push(null);}
      else{var num=parseFloat(val);valores.push(isNaN(num)?null:num);}
    }
    var tiene=false; for(var i=0;i<valores.length;i++){if(valores[i]!==null){tiene=true;break;}}
    if(!tiene){cargarSoloAlarmas(selectedRange,'El TAG de PI no tiene valores numéricos en el rango.');return;}
    var ultimo=null; for(var i=valores.length-1;i>=0;i--){if(valores[i]!==null){ultimo=valores[i];break;}}
    document.getElementById('infoTag').innerHTML=info.tag;
    document.getElementById('infoValor').innerHTML=ultimo!==null?ultimo.toFixed(2)+' '+info.unidad:'-';
    document.getElementById('infoUnidad').innerHTML=info.unidad||'-';
    document.getElementById('infoDesc').innerHTML=info.desc;
    document.getElementById('tagInfo').style.display='flex';
    // Guardar datos para exportar
    datosGrafico = { tag:info.tag, unidad:info.unidad||'', labels:labels, valores:valores, timestamps:timestamps };
    loadAlarmSeries(labels,timestamps,valores,info.tag,info.unidad);
    setStatus(data.Items.length+' puntos cargados para "'+info.tag+'".','ok');
  });
}

/* Exporta los puntos del gráfico actual a CSV (en el navegador). */
function exportarCSV(){
  if(!datosGrafico || !datosGrafico.labels.length){ setStatus('No hay datos para exportar. Cargá un gráfico primero.','error'); return; }
  var d = datosGrafico;
  var sep = ';';
  var lines = [];
  // Encabezado
  lines.push(['Fecha/hora', 'Tag', 'Valor', 'Unidad'].join(sep));
  for(var i=0;i<d.labels.length;i++){
    var ts = d.timestamps[i] ? new Date(d.timestamps[i]) : null;
    var fecha = ts ? formatFechaCSV(ts) : d.labels[i];
    var val = d.valores[i]===null ? '' : String(d.valores[i]).replace('.', ',');
    lines.push([fecha, d.tag, val, d.unidad].join(sep));
  }
  var csv = '\uFEFF' + lines.join('\r\n'); // BOM para Excel
  var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
  var url = URL.createObjectURL(blob);
  var a = document.createElement('a');
  var stamp = formatStamp(new Date());
  a.href = url;
  a.download = 'CLEAR_PI_' + d.tag.replace(/[^A-Za-z0-9_]/g,'_') + '_' + stamp + '.csv';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
function formatFechaCSV(d){
  function p(n){return n<10?'0'+n:n;}
  return p(d.getDate())+'/'+p(d.getMonth()+1)+'/'+d.getFullYear()+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());
}
function formatStamp(d){
  function p(n){return n<10?'0'+n:n;}
  return d.getFullYear()+p(d.getMonth()+1)+p(d.getDate())+'_'+p(d.getHours())+p(d.getMinutes())+p(d.getSeconds());
}

function cargarGrafico(){
  var tagInput=document.getElementById('tagInput').value.replace(/^\s+|\s+$/g,'');
  updateSelectedTagLabel(tagInput);
  if(!tagInput){setStatus('Ingresá parte del tag para buscar.','error');return;}
  setStatus('Buscando tag…','');
  document.getElementById('loader').className='loader active';
  document.getElementById('tagInfo').style.display='none';
  cerrarSugerencias();
  if(tagSeleccionado){ ocultarSimilares(); cargarHistorico({webId:tagSeleccionado.WebId,tag:tagSeleccionado.Name,unidad:tagSeleccionado.EngineeringUnits||'',desc:tagSeleccionado.Descriptor||'-'}); return; }

  if(!DS_WEBID){
    document.getElementById('loader').className='loader';
    cargarSoloAlarmas(getHistoricalRange(),'PI Web API no está configurada.');
    return;
  }

  var url=piURL('/dataservers/'+DS_WEBID+'/points?nameFilter=*'+tagInput+'*&maxCount=20');
  ajaxGetDetallado(url,function(err,data,status){
    document.getElementById('loader').className='loader';
    if(err){
      // Distinguir el tipo de error para que el usuario sepa qué arreglar
      if(status===0){
        setStatus('No se pudo conectar al proxy (pi_proxy.php). Verificá que el archivo esté en el servidor.','error');
      } else if(status===401){
        setStatus('Usuario o contraseña de PI incorrectos (401). Revisá la config en Conexión PI.','error');
      } else if(status===404){
        setStatus('El Data Server WebID no es válido (404). Verificá el WebID en Conexión PI.','error');
      } else if(status===502){
        setStatus('El servidor no pudo conectarse a PI Web API. Revisá la ruta de PI en Conexión PI.','error');
      } else {
        setStatus('Error al consultar PI (HTTP '+status+'). Revisá la configuración.','error');
      }
      cargarSoloAlarmas(getHistoricalRange(),'PI Web API temporalmente no disponible.');
      return;
    }
    if(!data.Items || !data.Items.length){
      cargarSoloAlarmas(getHistoricalRange(),'No se encontró el TAG en PI.');
      return;
    }
    if(data.Items.length===1){ ocultarSimilares(); document.getElementById('tagInput').value=data.Items[0].Name; updateSelectedTagLabel(data.Items[0].Name); cargarHistorico({webId:data.Items[0].WebId,tag:data.Items[0].Name,unidad:data.Items[0].EngineeringUnits||'',desc:data.Items[0].Descriptor||'-'}); }
    else{ setStatus('Se encontraron '+data.Items.length+' tags. Seleccioná uno.','warn'); mostrarTagsSimilares(data.Items,tagInput); }
  });
}

/* Igual que ajaxGet pero devuelve también el status HTTP, para diagnóstico. */
function ajaxGetDetallado(url, callback){
  var xhr=new XMLHttpRequest();
  xhr.open('GET', url, true);
  xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
  xhr.timeout=10000;
  xhr.onreadystatechange=function(){
    if(xhr.readyState===4){
      if(xhr.status===200){
        try{ callback(null, JSON.parse(xhr.responseText.replace(/^\uFEFF/,'')), 200); }
        catch(e){ callback('parse', null, 200); }
      } else {
        callback('http', null, xhr.status);
      }
    }
  };
  xhr.ontimeout=function(){ callback('timeout', null, 0); };
  xhr.send();
}

function inferRelatedQuery(tag){
  var clean=(tag||'').replace(/^\s+|\s+$/g,'');
  if(!clean) return '';
  var parts=clean.split('_');
  if(parts.length>1){ parts.pop(); var group=parts.join('_'); if(group) return group; }
  return clean;
}

function preloadInitialTag(){
  var tag=(INITIAL_TAG||'').replace(/^\s+|\s+$/g,'');
  var query=(INITIAL_QUERY||'').replace(/^\s+|\s+$/g,'');
  if(!query && tag) query=inferRelatedQuery(tag);
  if(!tag && !query) return;
  var input=document.getElementById('tagInput');
  if(tag) input.value=tag; else if(query) input.value=query;
  if(!DS_WEBID){cargarSoloAlarmas(getHistoricalRange(),'PI Web API no está configurada.');return;}

  var searchFor=query||tag;
  buscarTags(searchFor, function(items){
    if(!items || !items.length){
      if(tag){ input.value=tag; updateSelectedTagLabel(tag); cargarGrafico(); }
      return;
    }
    var exact=null;
    if(tag){
      for(var i=0;i<items.length;i++){
        if((items[i].Name||'').toUpperCase()===tag.toUpperCase()){ exact=items[i]; break; }
      }
    }
    if(items.length>1){
      mostrarTagsSimilares(items, searchFor);
      if(exact){
        var sel=document.getElementById('similarSelect');
        for(var j=0;j<items.length;j++){
          if((items[j].Name||'').toUpperCase()===exact.Name.toUpperCase()) { sel.selectedIndex=j; break; }
        }
      }
    }
    if(exact){
      tagSeleccionado=exact;
      input.value=exact.Name;
      updateSelectedTagLabel(exact.Name);
      cargarHistorico({webId:exact.WebId,tag:exact.Name,unidad:exact.EngineeringUnits||'',desc:exact.Descriptor||'-'});
    } else if(items.length===1){
      tagSeleccionado=items[0];
      input.value=items[0].Name;
      updateSelectedTagLabel(items[0].Name);
      cargarHistorico({webId:items[0].WebId,tag:items[0].Name,unidad:items[0].EngineeringUnits||'',desc:items[0].Descriptor||'-'});
    } else {
      setStatus('Se encontraron '+items.length+' tags relacionados. Seleccioná uno para graficar.','warn');
    }
  });
}

function syncCustomRange(){
  var fromDate=document.getElementById('customDateFrom');
  var toDate=document.getElementById('customDateTo');
  var fromTime=document.getElementById('customTimeFrom');
  var toTime=document.getElementById('customTimeTo');
  if(!fromDate||!toDate) return;
  fromDate.max=toDate.value||'';
  toDate.min=fromDate.value||'';
  var sameDay=fromDate.value&&toDate.value&&fromDate.value===toDate.value;
  if(sameDay){
    fromTime.max=toTime.value||'';
    toTime.min=fromTime.value||'';
  }else{
    fromTime.max='';
    toTime.min='';
  }
}

function clearCustomRange(){
  document.getElementById('customDateFrom').value='';
  document.getElementById('customDateTo').value='';
  document.getElementById('customTimeFrom').value='00:00';
  document.getElementById('customTimeTo').value='23:59';
  syncCustomRange();
}

window.onload=function(){
  document.getElementById('tagInput').onkeyup=onTagInput;
  updateSelectedTagLabel(document.getElementById('tagInput').value);
  document.getElementById('customDateFrom').onchange=syncCustomRange;
  document.getElementById('customDateTo').onchange=syncCustomRange;
  document.getElementById('customTimeFrom').onchange=syncCustomRange;
  document.getElementById('customTimeTo').onchange=syncCustomRange;
  document.getElementById('clearCustomRange').onclick=clearCustomRange;
  syncCustomRange();
  document.getElementById('btnVer').onclick=cargarGrafico;
  document.getElementById('btnSimilar').onclick=cargarGraficoSimilar;
  document.getElementById('btnExportPI').onclick=exportarCSV;
  document.onclick=function(e){ var t=e.target||e.srcElement; if(t&&t.id!=='tagInput'&&!/suggestion-item/.test(t.className))cerrarSugerencias(); };
  cargarTags24h();
  preloadInitialTag();
  document.getElementById('alarmCommentCancel').onclick=closeAlarmComment;
  document.getElementById('alarmCommentOverlay').onclick=closeAlarmComment;
  document.getElementById('alarmCommentSave').onclick=saveAlarmComment;
};

/* Carga los tags de las alarmas de últimas 24h en el combo (datalist). */
function cargarTags24h(){
  var xhr=new XMLHttpRequest();
  xhr.open('GET','pi_tags24h.php',true);
  xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
  xhr.onreadystatechange=function(){
    if(xhr.readyState===4 && xhr.status===200){
      try{
        var data=JSON.parse(xhr.responseText.replace(/^\uFEFF/,''));
        if(data.ok && data.tags && data.tags.length){
          var dl=document.getElementById('tags24hList');
          dl.innerHTML='';
          for(var i=0;i<data.tags.length;i++){
            var o=document.createElement('option');
            o.value=data.tags[i];
            dl.appendChild(o);
          }
          document.getElementById('tags24hInfo').textContent=
            '▾ '+data.tags.length+' tags de alarmas (últimas 24h) disponibles en la lista. También podés escribir otro.';
        } else {
          document.getElementById('tags24hInfo').textContent='(No se pudieron cargar los tags de 24h, pero podés escribir manualmente.)';
        }
      }catch(e){
        document.getElementById('tags24hInfo').textContent='(Escribí el tag manualmente.)';
      }
    }
  };
  xhr.send();
}
</script>
</body>
</html>
