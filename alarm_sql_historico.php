<?php
/* Histórico de alarmas exclusivo de SQL Server (dbo.FIXALARMS). */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';

auth_require();
$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['tz'] ?? 'UTC');

$embedded = isset($_GET['embedded']) && $_GET['embedded'] === '1';
$canComment = permissions_can('comments.create');
$tag = trim((string)($_GET['tag'] ?? ''));
$now = new DateTimeImmutable('now');
$from = $now->sub(new DateInterval('P7D'));

function sql_history_bound($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    try { return new DateTimeImmutable($value); } catch (Exception $e) { return null; }
}
$requestedFrom = sql_history_bound($_GET['start'] ?? '');
$requestedTo = sql_history_bound($_GET['end'] ?? '');
if ($requestedFrom && $requestedTo && $requestedFrom <= $requestedTo) {
    $from = $requestedFrom;
    $now = $requestedTo;
}

function sql_history_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Histórico SQL de alarmas</title>
  <link rel="stylesheet" href="assets/css/app.css?v=20260807-sql-history">
  <link rel="stylesheet" href="assets/css/chart_preferences.css?v=20260826-chartprefs-2">
  <style>
    :root{--sql-petrol:#174f5e;--sql-blue:#2f80a3;--sql-line:#d7e1e6;--sql-soft:#f3f7f9;--sql-text:#17313b;--sql-muted:#667b84;--sql-red:#d74435}
    *{box-sizing:border-box}body{margin:0;background:#eef3f5;color:var(--sql-text);font-family:Inter,Segoe UI,Arial,sans-serif}.sqlPage{max-width:1500px;margin:0 auto;padding:18px}.sqlPage.is-embedded{max-width:none;padding:14px}
    .sqlHead{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:13px}.sqlHead__eyebrow{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--sql-muted)}.sqlHead h1{margin:3px 0 2px;color:var(--sql-petrol);font-size:25px;line-height:1.15}.sqlHead p{margin:0;color:var(--sql-muted);font-size:12px}.sqlSource{padding:8px 11px;border:1px solid #bdd6df;border-radius:9px;background:#e9f5f8;color:var(--sql-petrol);font-size:11px;font-weight:800;white-space:nowrap}
    .sqlToolbar{display:grid;grid-template-columns:minmax(230px,1.2fr) minmax(205px,.8fr) minmax(205px,.8fr) auto auto auto;gap:9px;align-items:end;padding:13px;margin-bottom:13px;border:1px solid var(--sql-line);border-radius:13px;background:#fff;box-shadow:0 7px 20px rgba(23,79,94,.06)}.sqlField label{display:block;margin:0 0 5px;color:var(--sql-muted);font-size:10px;font-weight:800;letter-spacing:.7px;text-transform:uppercase}.sqlField input{width:100%;height:40px;border:1px solid var(--sql-line);border-radius:9px;padding:0 11px;background:#fff;color:var(--sql-text);font:inherit;outline:0}.sqlField input:focus{border-color:var(--sql-blue);box-shadow:0 0 0 3px rgba(47,128,163,.12)}
    .sqlButton{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 14px;border:1px solid var(--sql-line);border-radius:9px;background:#fff;color:var(--sql-petrol);font:inherit;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap}.sqlButton--primary{border-color:var(--sql-petrol);background:var(--sql-petrol);color:#fff}.sqlButton:disabled{opacity:.55;cursor:wait}
    .sqlStatus{display:none;margin-bottom:12px;padding:10px 12px;border-radius:9px;font-size:12px}.sqlStatus.is-visible{display:block}.sqlStatus.is-loading{background:#eaf5f8;color:var(--sql-petrol)}.sqlStatus.is-error{background:#fff0ee;color:#a82e24}.sqlStatus.is-ok{background:#edf8f2;color:#25724c}
    .sqlCards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}.sqlCard{padding:12px 14px;border:1px solid var(--sql-line);border-radius:11px;background:#fff}.sqlCard span{display:block;color:var(--sql-muted);font-size:9px;font-weight:800;letter-spacing:.7px;text-transform:uppercase}.sqlCard b{display:block;margin-top:3px;color:var(--sql-petrol);font-size:20px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sqlCard small{display:block;margin-top:2px;color:var(--sql-muted);font-size:10px}
    .sqlPanel{padding:14px;border:1px solid var(--sql-line);border-radius:13px;background:#fff;box-shadow:0 7px 20px rgba(23,79,94,.05)}.sqlPanel__head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:9px}.sqlPanel__head h2{margin:0;color:var(--sql-petrol);font-size:16px}.sqlPanel__head span{color:var(--sql-muted);font-size:11px}.sqlChartBox{position:relative;height:330px}.sqlEmpty{display:none;position:absolute;inset:0;align-items:center;justify-content:center;color:var(--sql-muted);font-size:13px}.sqlEmpty.is-visible{display:flex}
    .sqlTablePanel{margin-top:12px}.sqlTableScroll{max-height:310px;overflow:auto;border:1px solid var(--sql-line);border-radius:10px}.sqlTable{width:100%;border-collapse:collapse;font-size:11px}.sqlTable th{position:sticky;top:0;z-index:1;padding:8px 9px;background:var(--sql-petrol);color:#fff;text-align:left;white-space:nowrap}.sqlTable td{padding:7px 9px;border-bottom:1px solid #e7edef;vertical-align:top}.sqlTable tr:nth-child(even) td{background:#f8fafb}.sqlTable td:nth-child(1),.sqlTable td:nth-child(3){white-space:nowrap}.sqlTableNote{margin:8px 1px 0;color:var(--sql-muted);font-size:10px}.sqlComment{max-width:310px;white-space:normal}.sqlComment small{display:block;margin-top:3px;color:var(--sql-muted)}.sqlCommentBtn{padding:6px 9px;border:1px solid #bdd6df;border-radius:7px;background:#e9f5f8;color:var(--sql-petrol);font:inherit;font-weight:800;white-space:nowrap}
    .sqlCommentModal{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:18px;background:rgba(8,26,34,.58)}.sqlCommentModal[hidden]{display:none}.sqlCommentDialog{width:min(620px,100%);padding:18px;border:1px solid var(--sql-line);border-radius:14px;background:#fff;box-shadow:0 24px 80px rgba(9,35,45,.28)}.sqlCommentDialog__head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:13px}.sqlCommentDialog__head h3{margin:0;color:var(--sql-petrol);font-size:19px}.sqlCommentDialog__head p{margin:3px 0 0;color:var(--sql-muted);font-size:11px}.sqlCommentClose{width:34px;height:34px;border:1px solid var(--sql-line);border-radius:8px;background:#fff;color:var(--sql-muted);font-size:20px}.sqlCommentDialog label{display:grid;gap:5px;color:var(--sql-muted);font-size:11px;font-weight:800}.sqlCommentDialog textarea{width:100%;min-height:130px;padding:10px;border:1px solid var(--sql-line);border-radius:9px;resize:vertical;font:inherit;color:var(--sql-text)}.sqlCommentActions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}.sqlCommentMeta{margin-top:8px;color:var(--sql-muted);font-size:10px}
    @media(max-width:1080px){.sqlToolbar{grid-template-columns:1fr 1fr 1fr}.sqlCards{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:680px){.sqlPage,.sqlPage.is-embedded{padding:9px}.sqlHead{display:block}.sqlSource{display:inline-block;margin-top:8px}.sqlToolbar{grid-template-columns:1fr}.sqlCards{grid-template-columns:1fr 1fr}.sqlChartBox{height:285px}}
    @media print{@page{size:landscape;margin:9mm}body{background:#fff}.sqlPage{max-width:none;padding:0}.sqlToolbar,.sqlStatus,.sqlSource,.sqlButton,.sqlTableNote,.sqlCommentBtn,.sqlCommentModal{display:none!important}.sqlHead{margin-bottom:8px}.sqlCards{grid-template-columns:repeat(4,1fr)}.sqlCard,.sqlPanel{box-shadow:none}.sqlChartBox{height:125mm}.sqlTableScroll{max-height:none;overflow:visible}.sqlTable th{position:static}.sqlTablePanel{break-before:page}}
  </style>
</head>
<body>
<main class="sqlPage<?php echo $embedded ? ' is-embedded' : ''; ?>">
  <header class="sqlHead">
    <div><div class="sqlHead__eyebrow">Análisis histórico</div><h1>Histórico SQL de alarmas</h1><p>La tendencia se construye exclusivamente con los registros almacenados en SQL Server.</p></div>
    <div class="sqlSource">Fuente: dbo.FIXALARMS · solo lectura</div>
  </header>

  <form class="sqlToolbar" id="sqlHistoryForm">
    <div class="sqlField"><label for="sqlTag">TAG</label><input id="sqlTag" type="text" value="<?php echo sql_history_h($tag); ?>" autocomplete="off" required></div>
    <div class="sqlField"><label for="sqlFrom">Desde</label><input id="sqlFrom" type="datetime-local" value="<?php echo $from->format('Y-m-d\TH:i'); ?>" required></div>
    <div class="sqlField"><label for="sqlTo">Hasta</label><input id="sqlTo" type="datetime-local" value="<?php echo $now->format('Y-m-d\TH:i'); ?>" required></div>
    <button class="sqlButton sqlButton--primary" id="sqlApply" type="submit">Aplicar</button>
    <button class="sqlButton" id="sqlExcel" type="button">Exportar Excel</button>
    <button class="sqlButton" id="sqlPrint" type="button">Imprimir / PDF</button>
  </form>

  <div class="sqlStatus" id="sqlStatus" role="status"></div>

  <section class="sqlCards">
    <article class="sqlCard"><span>TAG analizado</span><b id="metricTag">—</b><small>Identificador en SQL</small></article>
    <article class="sqlCard"><span>Eventos encontrados</span><b id="metricCount">0</b><small>Dentro del rango aplicado</small></article>
    <article class="sqlCard"><span>Primera alarma</span><b id="metricFirst">—</b><small>Registro más antiguo</small></article>
    <article class="sqlCard"><span>Última alarma</span><b id="metricLast">—</b><small>Registro más reciente</small></article>
  </section>

  <section class="sqlPanel">
    <div class="sqlPanel__head"><h2>Tendencia registrada en SQL</h2><span id="chartMode">Esperando consulta</span></div>
    <div class="sqlChartBox"><canvas id="sqlHistoryChart"></canvas><div class="sqlEmpty" id="sqlEmpty">No hay alarmas para el TAG y rango seleccionados.</div></div>
  </section>

  <section class="sqlPanel sqlTablePanel">
    <div class="sqlPanel__head"><h2>Detalle de eventos</h2><span id="tableCount">0 registros</span></div>
    <div class="sqlTableScroll"><table class="sqlTable"><thead><tr><th>Fecha/hora</th><th>TAG</th><th>Valor</th><th>Estado</th><th>Prioridad</th><th>Descripción</th><th>Comentario</th><th>Acción</th></tr></thead><tbody id="sqlRows"></tbody></table></div>
    <p class="sqlTableNote" id="sqlTableNote">La tabla muestra hasta 1.000 eventos; la exportación incluye todos los registros recuperados.</p>
  </section>
</main>

<div class="sqlCommentModal" id="sqlCommentModal" hidden>
  <section class="sqlCommentDialog" role="dialog" aria-modal="true" aria-labelledby="sqlCommentTitle">
    <div class="sqlCommentDialog__head"><div><h3 id="sqlCommentTitle">Comentario de la alarma</h3><p id="sqlCommentEvent">—</p></div><button type="button" class="sqlCommentClose" id="sqlCommentClose" aria-label="Cerrar">×</button></div>
    <label>Comentario<textarea id="sqlCommentText" maxlength="4000" placeholder="Escribí el diagnóstico, la acción realizada o la novedad operativa…"></textarea></label>
    <div class="sqlCommentMeta" id="sqlCommentMeta"></div>
    <div class="sqlCommentActions"><button type="button" class="sqlButton" id="sqlCommentCancel">Cancelar</button><button type="button" class="sqlButton sqlButton--primary" id="sqlCommentSave">Guardar comentario</button></div>
  </section>
</div>

<script src="assets/js/chart.umd.js"></script>
<script src="assets/js/chart_preferences.js?v=20260826-chartprefs-2"></script>
<script>
(function(){
  'use strict';
  var form=document.getElementById('sqlHistoryForm');
  var tagInput=document.getElementById('sqlTag');
  var fromInput=document.getElementById('sqlFrom');
  var toInput=document.getElementById('sqlTo');
  var applyButton=document.getElementById('sqlApply');
  var statusBox=document.getElementById('sqlStatus');
  var emptyBox=document.getElementById('sqlEmpty');
  var rowsBox=document.getElementById('sqlRows');
  var chart=null;
  var items=[];
  var canComment=<?php echo $canComment ? 'true' : 'false'; ?>;
  var selectedCommentItem=null;

  function escapeHtml(value){return String(value==null?'':value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
  function setStatus(message,type){statusBox.textContent=message||'';statusBox.className='sqlStatus'+(message?' is-visible':'')+(type?' is-'+type:'');}
  function dateLabel(value){var d=new Date(value);return isNaN(d.getTime())?String(value||''):d.toLocaleString('es-AR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'});}
  function shortDate(value){var d=new Date(value);return isNaN(d.getTime())?'—':d.toLocaleString('es-AR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});}
  function safeFile(value){return String(value||'alarma').replace(/[^A-Za-z0-9_-]+/g,'_').slice(0,90);}
  function sampled(source,max){if(source.length<=max)return source.slice();var out=[],step=(source.length-1)/(max-1);for(var i=0;i<max;i++)out.push(source[Math.round(i*step)]);return out;}

  function updateMetrics(){
    document.getElementById('metricTag').textContent=tagInput.value.trim()||'—';
    document.getElementById('metricCount').textContent=items.length.toLocaleString('es-AR');
    document.getElementById('metricFirst').textContent=items.length?shortDate(items[0].timestamp):'—';
    document.getElementById('metricLast').textContent=items.length?shortDate(items[items.length-1].timestamp):'—';
  }
  function drawTable(){
    var visible=items.slice(-1000).reverse();
    rowsBox.innerHTML=visible.map(function(item){var meta=item.user?'<small>'+escapeHtml(item.user)+(item.updated_at?' · '+escapeHtml(dateLabel(item.updated_at)):'')+'</small>':'';var action=canComment?'<button type="button" class="sqlCommentBtn" data-comment-key="'+escapeHtml(item.event_key)+'">'+(item.comment?'Editar':'Comentar')+'</button>':'—';return '<tr><td>'+escapeHtml(dateLabel(item.timestamp))+'</td><td>'+escapeHtml(item.tag)+'</td><td>'+escapeHtml(item.value)+'</td><td>'+escapeHtml(item.status)+'</td><td>'+escapeHtml(item.priority)+'</td><td>'+escapeHtml(item.description)+'</td><td class="sqlComment">'+escapeHtml(item.comment||'—')+meta+'</td><td>'+action+'</td></tr>';}).join('');
    document.getElementById('tableCount').textContent=items.length.toLocaleString('es-AR')+' registros';
    document.getElementById('sqlTableNote').style.display=items.length>1000?'block':'none';
  }
  function drawChart(){
    if(chart){chart.destroy();chart=null;}
    emptyBox.classList.toggle('is-visible',items.length===0);
    if(!items.length){document.getElementById('chartMode').textContent='Sin datos';return;}
    var chartItems=sampled(items,1200);
    var numeric=items.some(function(item){return item.numeric_value!==null&&item.numeric_value!==undefined;});
    var labels=chartItems.map(function(item){return dateLabel(item.timestamp);});
    var values=chartItems.map(function(item){return numeric?(item.numeric_value===null?null:Number(item.numeric_value)):1;});
    document.getElementById('chartMode').textContent=numeric?'Valor almacenado por evento':'Línea temporal de ocurrencias';
    chart=new Chart(document.getElementById('sqlHistoryChart'),{
      type:'line',
      data:{labels:labels,datasets:[{label:numeric?'Valor SQL':'Ocurrencia',data:values,borderColor:'#2f80a3',backgroundColor:'rgba(47,128,163,.14)',pointBackgroundColor:'#d74435',pointBorderColor:'#fff',pointRadius:chartItems.length>300?1.5:3,pointHoverRadius:5,borderWidth:2,tension:.18,spanGaps:true,fill:numeric}]},
      options:{responsive:true,maintainAspectRatio:false,animation:false,interaction:{mode:'nearest',intersect:false},plugins:{legend:{display:false},tooltip:{callbacks:{title:function(ctx){return ctx.length?dateLabel(chartItems[ctx[0].dataIndex].timestamp):'';},label:function(ctx){var item=chartItems[ctx.dataIndex];return ['Valor: '+(item.value||'—'),'Estado: '+(item.status||'—'),'Prioridad: '+(item.priority||'—')];},afterBody:function(ctx){var item=ctx.length?chartItems[ctx[0].dataIndex]:null;return item&&item.comment?'Comentario: '+item.comment:'';}}},scales:{x:{ticks:{maxTicksLimit:10,maxRotation:0},grid:{display:false}},y:{display:numeric,beginAtZero:false,title:{display:numeric,text:'Valor registrado'}}}}}
    });
    window.dispatchEvent(new CustomEvent('clear-charts-ready'));
  }
  function load(){
    var tag=tagInput.value.trim(),from=fromInput.value,to=toInput.value;
    if(!tag||!from||!to){setStatus('Completá TAG, Desde y Hasta.','error');return;}
    if(from>to){setStatus('La fecha Desde no puede ser posterior a Hasta.','error');return;}
    applyButton.disabled=true;setStatus('Consultando dbo.FIXALARMS…','loading');
    var params=new URLSearchParams({action:'list',tag:tag,start:from,end:to,comment_scope:'event'});
    fetch('alarm_events_api.php?'+params.toString(),{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(response){return response.json().then(function(data){if(!response.ok)throw new Error(data.error||'No se pudo consultar SQL.');return data;});})
      .then(function(data){items=Array.isArray(data.items)?data.items:[];updateMetrics();drawChart();drawTable();setStatus(items.length?'Consulta SQL completada: '+items.length.toLocaleString('es-AR')+' eventos.':'La consulta se completó sin eventos para ese rango.',items.length?'ok':'');})
      .catch(function(error){items=[];updateMetrics();drawChart();drawTable();setStatus(error.message||'No se pudo consultar SQL.','error');})
      .finally(function(){applyButton.disabled=false;});
  }
  function exportExcel(){
    if(!items.length){setStatus('No hay eventos para exportar.','error');return;}
    var html='<!doctype html><html><head><meta charset="utf-8"><style>table{border-collapse:collapse}th,td{border:1px solid #9fb2bd;padding:6px 8px}th{background:#174f5e;color:#fff}</style></head><body><h2>Histórico SQL de alarmas</h2><p>Fuente: dbo.FIXALARMS</p><p>TAG: '+escapeHtml(tagInput.value.trim())+' | Desde: '+escapeHtml(fromInput.value)+' | Hasta: '+escapeHtml(toInput.value)+'</p><table><thead><tr><th>Fecha/hora</th><th>TAG</th><th>Valor</th><th>Unidad</th><th>Estado</th><th>Prioridad</th><th>Descripción</th><th>Comentario</th></tr></thead><tbody>';
    items.forEach(function(item){html+='<tr><td>'+escapeHtml(dateLabel(item.timestamp))+'</td><td>'+escapeHtml(item.tag)+'</td><td>'+escapeHtml(item.value)+'</td><td>'+escapeHtml(item.unit)+'</td><td>'+escapeHtml(item.status)+'</td><td>'+escapeHtml(item.priority)+'</td><td>'+escapeHtml(item.description)+'</td><td>'+escapeHtml(item.comment)+'</td></tr>';});
    html+='</tbody></table></body></html>';
    var blob=new Blob(['\uFEFF'+html],{type:'application/vnd.ms-excel;charset=utf-8;'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='Historico_SQL_'+safeFile(tagInput.value)+'_'+new Date().toISOString().slice(0,10)+'.xls';document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(url);},500);
  }

  function openComment(item){
    if(!canComment||!item)return;
    selectedCommentItem=item;
    document.getElementById('sqlCommentEvent').textContent=item.tag+' · '+dateLabel(item.timestamp)+' · '+(item.value||item.status||'Alarma');
    document.getElementById('sqlCommentText').value=item.comment||'';
    document.getElementById('sqlCommentMeta').textContent=item.user?'Última edición: '+item.user+(item.updated_at?' · '+dateLabel(item.updated_at):''):'';
    document.getElementById('sqlCommentModal').hidden=false;
    setTimeout(function(){document.getElementById('sqlCommentText').focus();},30);
  }
  function closeComment(){document.getElementById('sqlCommentModal').hidden=true;selectedCommentItem=null;}
  function saveComment(){
    if(!selectedCommentItem)return;
    var text=document.getElementById('sqlCommentText').value.trim();
    if(!text){setStatus('Escribí un comentario antes de guardar.','error');return;}
    var button=document.getElementById('sqlCommentSave');button.disabled=true;
    var fd=new FormData();fd.append('action','save_event_comment');fd.append('event_key',selectedCommentItem.event_key||'');fd.append('tag',selectedCommentItem.tag||tagInput.value.trim());fd.append('timestamp',selectedCommentItem.timestamp||'');fd.append('comment',text);
    fetch('alarm_events_api.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(response){return response.json().then(function(data){if(!response.ok||data.ok===false)throw new Error(data.error||'No se pudo guardar el comentario.');return data;});})
      .then(function(data){selectedCommentItem.comment=text;selectedCommentItem.user=data.user||'';selectedCommentItem.updated_at=data.saved_at||'';drawTable();drawChart();closeComment();setStatus(data.message||'Comentario guardado.','ok');})
      .catch(function(error){setStatus(error.message||'No se pudo guardar el comentario.','error');})
      .finally(function(){button.disabled=false;});
  }

  form.addEventListener('submit',function(event){event.preventDefault();load();});
  document.getElementById('sqlExcel').addEventListener('click',exportExcel);
  document.getElementById('sqlPrint').addEventListener('click',function(){if(!items.length){setStatus('No hay eventos para imprimir.','error');return;}window.print();});
  rowsBox.addEventListener('click',function(event){var button=event.target.closest('[data-comment-key]');if(!button)return;var key=button.getAttribute('data-comment-key');openComment(items.find(function(item){return item.event_key===key;}));});
  document.getElementById('sqlCommentClose').addEventListener('click',closeComment);
  document.getElementById('sqlCommentCancel').addEventListener('click',closeComment);
  document.getElementById('sqlCommentSave').addEventListener('click',saveComment);
  document.getElementById('sqlCommentModal').addEventListener('click',function(event){if(event.target===event.currentTarget)closeComment();});
  document.addEventListener('keydown',function(event){if(event.key==='Escape'&&!document.getElementById('sqlCommentModal').hidden)closeComment();});
  updateMetrics();
  if(tagInput.value.trim())load();
})();
</script>
</body>
</html>
