(function(){
  'use strict';
  var form=document.getElementById('sigmaHistoryForm'),well=document.getElementById('historyWell'),from=document.getElementById('historyFrom'),to=document.getElementById('historyTo'),apply=document.getElementById('historyApply'),status=document.getElementById('historyStatus'),rowsBox=document.getElementById('historyRows'),empty=document.getElementById('historyEmpty');
  var items=[],chart=null;
  function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
  function number(v,d){return v==null||isNaN(Number(v))?'—':Number(v).toLocaleString('es-AR',{minimumFractionDigits:d,maximumFractionDigits:d});}
  function date(v){var d=new Date(v);return isNaN(d.getTime())?String(v||'—'):d.toLocaleString('es-AR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});}
  function setStatus(message,type){status.textContent=message||'';status.className='sigma-history__status'+(message?' is-visible':'')+(type?' is-'+type:'');}
  function sample(source,max){if(source.length<=max)return source.slice();var out=[],step=(source.length-1)/(max-1);for(var i=0;i<max;i++)out.push(source[Math.round(i*step)]);return out;}
  function metrics(){
    var sigmaValues=items.map(function(x){return x.sigma_numeric;}).filter(function(x){return x!=null;});
    var excessValues=items.map(function(x){return x.excess_numeric;}).filter(function(x){return x!=null;});
    document.getElementById('historyCount').textContent=items.length.toLocaleString('es-AR');
    document.getElementById('historyLastSigma').textContent=sigmaValues.length?number(sigmaValues[sigmaValues.length-1],4):'—';
    document.getElementById('historyMaxSigma').textContent=sigmaValues.length?number(Math.max.apply(null,sigmaValues),4):'—';
    document.getElementById('historyMaxExcess').textContent=excessValues.length?number(Math.max.apply(null,excessValues),0):'—';
  }
  function drawTable(){rowsBox.innerHTML=items.slice().reverse().map(function(x){return '<tr><td>'+esc(date(x.capture))+'</td><td>'+esc(date(x.today))+'</td><td>'+esc(number(x.sigma_numeric,4))+'</td><td>'+esc(number(x.excess_numeric,0))+'</td><td>'+esc(x.battery)+'</td><td>'+esc(x.method)+'</td></tr>';}).join('');}
  function drawChart(){
    if(chart){chart.destroy();chart=null;}empty.classList.toggle('is-visible',items.length===0);if(!items.length)return;
    var points=sample(items,1000),labels=points.map(function(x){return date(x.capture);});
    chart=new Chart(document.getElementById('sigmaHistoryChart'),{type:'line',data:{labels:labels,datasets:[{label:'3Sigma',data:points.map(function(x){return x.sigma_numeric;}),borderColor:'#2f80a3',backgroundColor:'rgba(47,128,163,.12)',borderWidth:2,pointRadius:points.length>150?1:3,tension:.18,spanGaps:true,yAxisID:'y'},{label:'CONT_EXCESOS',data:points.map(function(x){return x.excess_numeric;}),borderColor:'#e9660a',backgroundColor:'rgba(233,102,10,.10)',borderWidth:2,pointRadius:points.length>150?1:3,tension:.18,spanGaps:true,yAxisID:'y1'}]},options:{responsive:true,maintainAspectRatio:false,animation:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'top'}},scales:{x:{ticks:{maxTicksLimit:9,maxRotation:0},grid:{display:false}},y:{position:'left',title:{display:true,text:'3Sigma'}},y1:{position:'right',beginAtZero:true,title:{display:true,text:'Excesos'},grid:{drawOnChartArea:false}}}}});
  }
  function load(){
    if(!well.value||!from.value||!to.value){setStatus('Completá el pozo y las fechas.','error');return;}if(from.value>to.value){setStatus('La fecha Desde no puede ser posterior a Hasta.','error');return;}
    apply.disabled=true;setStatus('Consultando el histórico del pozo…','loading');
    fetch('tecss_3sigma_historico_api.php?'+new URLSearchParams({pozo:well.value,desde:from.value,hasta:to.value}).toString(),{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.json().then(function(data){if(!r.ok||!data.ok)throw new Error(data.error||'No se pudo consultar el histórico.');return data;});})
      .then(function(data){items=Array.isArray(data.items)?data.items:[];metrics();drawTable();drawChart();document.getElementById('historyRange').textContent=from.value+' a '+to.value;setStatus(items.length?'Consulta completada: '+items.length+' registros.':'No hay registros en el período.',items.length?'ok':'');})
      .catch(function(error){items=[];metrics();drawTable();drawChart();setStatus(error.message||'No se pudo consultar el histórico.','error');})
      .finally(function(){apply.disabled=false;});
  }
  function exportExcel(){
    if(!items.length){setStatus('No hay registros para exportar.','error');return;}
    var html='<!doctype html><html><head><meta charset="utf-8"><style>table{border-collapse:collapse}th,td{border:1px solid #9fb2bd;padding:6px}th{background:#174f5e;color:#fff}</style></head><body><h2>Histórico 3Sigma · '+esc(well.value)+'</h2><p>Desde: '+esc(from.value)+' | Hasta: '+esc(to.value)+'</p><table><thead><tr><th>Captura</th><th>HOY</th><th>Pozo</th><th>Batería</th><th>3SIGMA</th><th>CONT_EXCESOS</th><th>Método</th></tr></thead><tbody>';
    items.forEach(function(x){html+='<tr><td>'+esc(date(x.capture))+'</td><td>'+esc(date(x.today))+'</td><td>'+esc(x.well)+'</td><td>'+esc(x.battery)+'</td><td>'+esc(number(x.sigma_numeric,4))+'</td><td>'+esc(number(x.excess_numeric,0))+'</td><td>'+esc(x.method)+'</td></tr>';});html+='</tbody></table></body></html>';
    var blob=new Blob(['\uFEFF'+html],{type:'application/vnd.ms-excel;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='Historico_3Sigma_'+well.value.replace(/[^A-Za-z0-9_-]+/g,'_')+'_'+from.value+'_'+to.value+'.xls';document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(url);},500);
  }
  form.addEventListener('submit',function(event){event.preventDefault();load();});document.getElementById('historyExcel').addEventListener('click',exportExcel);if(well.value)load();
})();
