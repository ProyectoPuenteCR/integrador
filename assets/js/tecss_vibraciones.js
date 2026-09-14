(function(){
  'use strict';
  var form=document.getElementById('vibFilterForm');
  var table=document.getElementById('vibTable');
  var modal=document.getElementById('vibModal');
  var chart=null;
  var columnKey='clear_tecss_vibraciones_columns_v1';
  var columnOrderKey='clear_tecss_vibraciones_column_order_v1';
  var draggedColumn='';
  var dragMoved=false;

  function esc(value){return String(value==null?'':value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
  function number(value,decimals){return value==null||value===''||isNaN(Number(value))?'—':Number(value).toLocaleString('es-AR',{minimumFractionDigits:decimals,maximumFractionDigits:decimals});}
  function dateTime(value){if(!value)return '—';var date=new Date(String(value).replace(' ','T'));return isNaN(date.getTime())?String(value):date.toLocaleString('es-AR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});}
  function inputDate(date){var year=date.getFullYear(),month=String(date.getMonth()+1).padStart(2,'0'),day=String(date.getDate()).padStart(2,'0');return year+'-'+month+'-'+day;}
  function sample(items,max){if(items.length<=max)return items.slice();var output=[],step=(items.length-1)/(max-1);for(var index=0;index<max;index++)output.push(items[Math.round(index*step)]);return output;}

  if(document.getElementById('vibReload'))document.getElementById('vibReload').addEventListener('click',function(){window.location.reload();});
  if(document.getElementById('vibPageSize'))document.getElementById('vibPageSize').addEventListener('change',function(){var page=form.querySelector('input[name="page"]');if(page)page.value='1';form.submit();});

  function loadColumnState(){try{var stored=JSON.parse(localStorage.getItem(columnKey)||'{}');return stored&&typeof stored==='object'?stored:{};}catch(error){return {};}}
  function saveColumnState(state){try{localStorage.setItem(columnKey,JSON.stringify(state));}catch(error){}}
  function applyColumnState(state){if(!table)return;Array.prototype.slice.call(table.querySelectorAll('[data-column]')).forEach(function(cell){var column=cell.getAttribute('data-column');var visible=column==='__REPORTE'||column==='POZO'||state[column]!==false;cell.style.display=visible?'':'none';});}
  function headingHeaders(){return table?Array.prototype.slice.call(table.querySelectorAll('thead tr.vib-heading-row th[data-column]')):[];}
  function currentColumnOrder(){return headingHeaders().map(function(header){return header.getAttribute('data-column');});}
  function loadColumnOrder(){try{var stored=JSON.parse(localStorage.getItem(columnOrderKey)||'[]');return Array.isArray(stored)?stored:[];}catch(error){return [];}}
  function saveColumnOrder(order){try{localStorage.setItem(columnOrderKey,JSON.stringify(order));}catch(error){}}
  function normalizeColumnOrder(saved){
    var current=currentColumnOrder(),result=[];
    if(current.indexOf('__REPORTE')!==-1)result.push('__REPORTE');
    saved.forEach(function(column){if(column!=='__REPORTE'&&current.indexOf(column)!==-1&&result.indexOf(column)===-1)result.push(column);});
    current.forEach(function(column){if(result.indexOf(column)===-1)result.push(column);});
    return result;
  }
  function applyColumnOrder(order){
    if(!table)return;
    Array.prototype.slice.call(table.querySelectorAll('tr')).forEach(function(row){
      var cells=Array.prototype.slice.call(row.children),byColumn={};
      cells.forEach(function(cell){var column=cell.getAttribute&&cell.getAttribute('data-column');if(column)byColumn[column]=cell;});
      order.forEach(function(column){if(byColumn[column])row.appendChild(byColumn[column]);});
      cells.forEach(function(cell){var column=cell.getAttribute&&cell.getAttribute('data-column');if(!column||order.indexOf(column)===-1)row.appendChild(cell);});
    });
  }
  function clearDragStyles(){headingHeaders().forEach(function(header){header.classList.remove('is-dragging','is-drag-over');header.setAttribute('aria-grabbed','false');});}
  function initColumnDragging(){
    headingHeaders().forEach(function(header){
      var column=header.getAttribute('data-column');
      if(column==='__REPORTE')return;
      header.setAttribute('draggable','true');
      header.setAttribute('aria-grabbed','false');
      header.setAttribute('title','Arrastrar para mover la columna');
      header.addEventListener('dragstart',function(event){draggedColumn=column;dragMoved=false;header.classList.add('is-dragging');header.setAttribute('aria-grabbed','true');if(event.dataTransfer){event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',column);}});
      header.addEventListener('dragover',function(event){if(!draggedColumn||draggedColumn===column)return;event.preventDefault();headingHeaders().forEach(function(item){item.classList.remove('is-drag-over');});header.classList.add('is-drag-over');if(event.dataTransfer)event.dataTransfer.dropEffect='move';});
      header.addEventListener('drop',function(event){
        if(!draggedColumn||draggedColumn===column)return;
        event.preventDefault();
        var order=currentColumnOrder().filter(function(item){return item!==draggedColumn;});
        var targetIndex=order.indexOf(column),rect=header.getBoundingClientRect();
        if(targetIndex<0)return;
        if(event.clientX>rect.left+(rect.width/2))targetIndex+=1;
        order.splice(targetIndex,0,draggedColumn);
        order=normalizeColumnOrder(order);
        applyColumnOrder(order);saveColumnOrder(order);dragMoved=true;clearDragStyles();
        window.setTimeout(function(){dragMoved=false;},120);
      });
      header.addEventListener('dragend',function(){draggedColumn='';clearDragStyles();});
    });
    table.addEventListener('click',function(event){if(dragMoved&&event.target.closest('.vib-sort')){event.preventDefault();event.stopPropagation();}},true);
  }
  function buildColumnMenu(){
    if(!table)return;
    var button=document.getElementById('vibColumnsBtn'),menu=document.getElementById('vibColumnsMenu');if(!button||!menu)return;
    var state=loadColumnState(),headers=headingHeaders().filter(function(th){return th.getAttribute('data-column')!=='__REPORTE';});
    menu.innerHTML=headers.map(function(th){var column=th.getAttribute('data-column'),label=(th.getAttribute('data-report-label')||th.textContent||column).trim();var checked=column==='POZO'||state[column]!==false;return '<label><input type="checkbox" data-vib-column="'+esc(column)+'" '+(checked?'checked':'')+' '+(column==='POZO'?'disabled':'')+'><span>'+esc(label)+'</span></label>';}).join('');
    applyColumnState(state);
    menu.addEventListener('change',function(event){var input=event.target.closest('[data-vib-column]');if(!input)return;state[input.getAttribute('data-vib-column')]=input.checked;saveColumnState(state);applyColumnState(state);});
    button.addEventListener('click',function(event){event.stopPropagation();menu.classList.toggle('is-open');});
    document.addEventListener('click',function(event){if(!menu.contains(event.target)&&event.target!==button)menu.classList.remove('is-open');});
  }
  if(table){var initialOrder=normalizeColumnOrder(loadColumnOrder());applyColumnOrder(initialOrder);saveColumnOrder(initialOrder);initColumnDragging();}
  buildColumnMenu();

  function submitGridFilter(){if(form)form.submit();}
  var gridPozo=document.getElementById('vibGridPozo'),gridPozoValue=document.getElementById('vibGridPozoValue');
  if(gridPozo&&gridPozoValue){
    gridPozo.addEventListener('keydown',function(event){if(event.key!=='Enter')return;event.preventDefault();gridPozoValue.value=gridPozo.value.trim();submitGridFilter();});
    gridPozo.addEventListener('search',function(){if(gridPozo.value===''){gridPozoValue.value='';submitGridFilter();}});
  }
  var gridBattery=document.getElementById('vibGridBateria');
  if(gridBattery&&form)gridBattery.addEventListener('change',function(){var toolbar=form.querySelector('select[name="bateria"]');if(toolbar)toolbar.value=gridBattery.value;submitGridFilter();});
  var gridState=document.getElementById('vibGridEstado');
  if(gridState&&form)gridState.addEventListener('change',function(){var toolbar=form.querySelector('select[name="estado"]');if(toolbar)toolbar.value=gridState.value;submitGridFilter();});

  var detailWell=document.getElementById('vibDetailWell'),detailFrom=document.getElementById('vibDetailFrom'),detailTo=document.getElementById('vibDetailTo'),detailStatus=document.getElementById('vibDetailStatus'),detailMetrics=document.getElementById('vibDetailMetrics'),detailEvents=document.getElementById('vibDetailEvents'),detailEmpty=document.getElementById('vibDetailEmpty');
  var chartCard=document.getElementById('vibChartCard'),chartMaximize=document.getElementById('vibChartMaximize'),chartExport=document.getElementById('vibChartExport'),chartReport=document.getElementById('vibChartReport'),chartRange=document.getElementById('vibChartRange');
  function setDetailStatus(message,isError){detailStatus.textContent=message||'';detailStatus.className='vib-modal__status'+(isError?' is-error':'');}
  function reportEncode(value){try{return btoa(unescape(encodeURIComponent(JSON.stringify(value))));}catch(error){return '';}}
  function reportKey(value){var hash=2166136261;for(var index=0;index<value.length;index++){hash^=value.charCodeAt(index);hash=Math.imul(hash,16777619);}return(hash>>>0).toString(16);}
  function chartFullscreenElement(){return document.fullscreenElement||document.webkitFullscreenElement||null;}
  function chartIsMaximized(){return chartFullscreenElement()===chartCard||!!(chartCard&&chartCard.classList.contains('is-maximized'));}
  function refreshChartSize(){if(chart)window.setTimeout(function(){chart.resize();},60);}
  function syncChartMaximize(){if(chartMaximize)chartMaximize.textContent=chartIsMaximized()?'Salir de pantalla completa':'Maximizar';refreshChartSize();}
  function leaveChartMaximized(){
    if(chartCard)chartCard.classList.remove('is-maximized');
    document.body.classList.remove('vib-chart-fullscreen-open');
    var active=chartFullscreenElement();
    if(active===chartCard){var exit=document.exitFullscreen||document.webkitExitFullscreen;if(exit)try{exit.call(document);}catch(error){}}
    syncChartMaximize();
  }
  function toggleChartMaximize(){
    if(!chartCard||!chart)return;
    if(chartIsMaximized()){leaveChartMaximized();return;}
    var request=chartCard.requestFullscreen||chartCard.webkitRequestFullscreen;
    if(request){
      try{var result=request.call(chartCard);if(result&&typeof result.catch==='function')result.catch(function(){chartCard.classList.add('is-maximized');document.body.classList.add('vib-chart-fullscreen-open');syncChartMaximize();});}
      catch(error){chartCard.classList.add('is-maximized');document.body.classList.add('vib-chart-fullscreen-open');syncChartMaximize();}
    }else{chartCard.classList.add('is-maximized');document.body.classList.add('vib-chart-fullscreen-open');syncChartMaximize();}
  }
  function safeFilename(value){return String(value||'TECSS').replace(/[^A-Za-z0-9_-]+/g,'_').replace(/^_+|_+$/g,'')||'TECSS';}
  function downloadChartPng(){
    if(!chart)return;
    var source=document.getElementById('vibDetailChart'),output=document.createElement('canvas');
    output.width=source.width;output.height=source.height;
    var context=output.getContext('2d');context.fillStyle='#ffffff';context.fillRect(0,0,output.width,output.height);context.drawImage(source,0,0);
    var link=document.createElement('a');link.download='Vibraciones_TECSS_'+safeFilename(detailWell.value)+'_'+detailFrom.value+'_'+detailTo.value+'.png';link.href=output.toDataURL('image/png',1);document.body.appendChild(link);link.click();link.remove();
  }
  function updateChartActions(points,state){
    var ready=!!chart&&points.length>0,stateLabel=state.ESTADO_ETIQUETA||state.ESTADO||'';
    if(chartMaximize)chartMaximize.disabled=!ready;
    if(chartExport)chartExport.disabled=!ready;
    if(chartRange)chartRange.textContent=detailWell.value+' · '+detailFrom.value+' al '+detailTo.value+(stateLabel?' · '+stateLabel:'');
    if(!chartReport)return;
    chartReport.disabled=!ready;
    if(!ready){chartReport.checked=false;return;}
    var labels=points.map(function(point){return dateTime(point.fecha);});
    var datasets=[{label:'VIBR',values:points.map(function(point){return point.vibr;})}];
    if(state.UMBRAL_ALERTA!=null)datasets.push({label:'Umbral alerta',values:points.map(function(){return state.UMBRAL_ALERTA;})});
    if(state.UMBRAL_CRITICO!=null)datasets.push({label:'Umbral crítico',values:points.map(function(){return state.UMBRAL_CRITICO;})});
    if(points.some(function(point){return point.gpm!=null;}))datasets.push({label:'GPM',values:points.map(function(point){return point.gpm;})});
    var identity=detailWell.value+'|'+detailFrom.value+'|'+detailTo.value;
    var payload={kind:'chart',chart_type:'line',unit:'VIBR / GPM',axis_label:'Fecha',labels:labels,datasets:datasets,context:'Pozo '+detailWell.value+' · '+detailFrom.value+' al '+detailTo.value+(stateLabel?' · Estado: '+stateLabel:''),group:'telemetry_tecss_vibraciones_chart',group_title:'Gráficos de Vibraciones TECSS'};
    chartReport.setAttribute('data-report-key','tecss-vibraciones-chart-'+reportKey(identity));
    chartReport.setAttribute('data-report-title','Tendencia de Vibraciones TECSS · '+detailWell.value);
    chartReport.setAttribute('data-report-payload',reportEncode(payload));
    if(window.CLEAR_NS_REPORT&&typeof window.CLEAR_NS_REPORT.refresh==='function')window.CLEAR_NS_REPORT.refresh().catch(function(){});
  }
  function openModal(well,state){
    var today=new Date(),from=new Date();from.setDate(today.getDate()-7);
    detailWell.value=well;detailFrom.value=inputDate(from);detailTo.value=inputDate(today);
    document.getElementById('vibModalTitle').textContent='Tendencia · '+well;
    document.getElementById('vibModalState').textContent=state||'';
    modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';loadDetail();
  }
  function closeModal(){leaveChartMaximized();modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');document.body.style.overflow='';}
  Array.prototype.slice.call(document.querySelectorAll('[data-vib-detail]')).forEach(function(button){button.addEventListener('click',function(){openModal(button.getAttribute('data-well'),button.getAttribute('data-state'));});});
  Array.prototype.slice.call(document.querySelectorAll('[data-vib-close]')).forEach(function(button){button.addEventListener('click',closeModal);});
  document.addEventListener('keydown',function(event){if(event.key!=='Escape'||!modal.classList.contains('is-open'))return;if(chartIsMaximized())leaveChartMaximized();else closeModal();});
  if(chartMaximize)chartMaximize.addEventListener('click',toggleChartMaximize);
  if(chartExport)chartExport.addEventListener('click',downloadChartPng);
  document.addEventListener('fullscreenchange',syncChartMaximize);
  document.addEventListener('webkitfullscreenchange',syncChartMaximize);

  function metric(label,value){return '<div class="vib-modal__metric"><span>'+esc(label)+'</span><b>'+esc(value)+'</b></div>';}
  function drawMetrics(state){
    detailMetrics.innerHTML=metric('Estado',state.ESTADO_ETIQUETA||state.ESTADO||'—')+metric('VIBR actual',number(state.VIBR_ACTUAL,1))+metric('GPM actual',number(state.GPM_ACTUAL,1))+metric('Mediana',number(state.VIBR_MEDIANA,1))+metric('Umbral alerta',number(state.UMBRAL_ALERTA,1))+metric('Umbral crítico',number(state.UMBRAL_CRITICO,1))+metric('Alertas 48h',Number(state.ALERTAS_48H||0).toLocaleString('es-AR'))+metric('Excesos 48h',Number(state.EXCESOS_48H||0).toLocaleString('es-AR'))+metric('Severidad',number(state.SCORE_SEVERIDAD,1))+metric('Exceso promedio',number(state.EXCESO_PROMEDIO_PCT,1)+' %')+metric('Exceso máximo',number(state.EXCESO_MAXIMO_PCT,1)+' %')+metric('Última alerta',dateTime(state.ULTIMA_ALERTA));
  }
  function drawEvents(events){
    if(!events.length){detailEvents.innerHTML='<div class="vib-events-empty">Sin alertas ni picos detectados en el período.</div>';return;}
    detailEvents.innerHTML=events.map(function(event){var level=String(event.nivel||'').toLowerCase();return '<div class="vib-event"><span>'+esc(dateTime(event.fecha))+'</span><span class="vib-event__level is-'+esc(level)+'">'+esc(event.nivel||'Evento')+'</span><span>'+esc(event.tipo||'')+'</span><b>'+esc(number(event.valor,1))+' / '+esc(number(event.umbral,1))+'</b></div>';}).join('');
  }
  function drawChart(series,state){
    if(chart){chart.destroy();chart=null;}detailEmpty.classList.toggle('is-visible',series.length===0);if(!series.length){updateChartActions([],state||{});return;}
    var points=sample(series,1200),styles=getComputedStyle(document.documentElement),textColor=styles.getPropertyValue('--text-soft').trim()||'#46586a',gridColor=styles.getPropertyValue('--line').trim()||'#eef3f6';
    var labels=points.map(function(point){return dateTime(point.fecha);}),datasets=[{label:'VIBR',data:points.map(function(point){return point.vibr;}),borderColor:'#2f80a3',backgroundColor:'rgba(47,128,163,.10)',borderWidth:2,pointRadius:points.length>160?0:2,tension:.15,spanGaps:true,yAxisID:'y'}];
    if(state.UMBRAL_ALERTA!=null)datasets.push({label:'Umbral alerta',data:points.map(function(){return state.UMBRAL_ALERTA;}),borderColor:'#d97706',borderWidth:1.5,borderDash:[6,5],pointRadius:0,spanGaps:true,yAxisID:'y'});
    if(state.UMBRAL_CRITICO!=null)datasets.push({label:'Umbral crítico',data:points.map(function(){return state.UMBRAL_CRITICO;}),borderColor:'#c53030',borderWidth:1.5,borderDash:[6,5],pointRadius:0,spanGaps:true,yAxisID:'y'});
    if(points.some(function(point){return point.gpm!=null;}))datasets.push({label:'GPM',data:points.map(function(point){return point.gpm;}),borderColor:'#7b61a8',borderWidth:1.5,pointRadius:0,tension:.15,spanGaps:true,yAxisID:'y1'});
    chart=new Chart(document.getElementById('vibDetailChart'),{type:'line',data:{labels:labels,datasets:datasets},options:{responsive:true,maintainAspectRatio:false,animation:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'top',labels:{color:textColor,boxWidth:18,usePointStyle:true}}},scales:{x:{ticks:{color:textColor,maxTicksLimit:9,maxRotation:0},grid:{display:false}},y:{position:'left',beginAtZero:true,title:{display:true,text:'VIBR',color:textColor},ticks:{color:textColor},grid:{color:gridColor}},y1:{position:'right',beginAtZero:true,title:{display:true,text:'GPM',color:textColor},ticks:{color:textColor},grid:{drawOnChartArea:false}}}}});
    updateChartActions(points,state);
  }
  function loadDetail(){
    if(!detailWell.value||!detailFrom.value||!detailTo.value){setDetailStatus('Completá el rango de fechas.',true);return;}if(detailFrom.value>detailTo.value){setDetailStatus('La fecha Desde no puede ser posterior a Hasta.',true);return;}
    setDetailStatus('Consultando datos procesados…',false);detailMetrics.innerHTML='';detailEvents.innerHTML='';if(chartMaximize)chartMaximize.disabled=true;if(chartExport)chartExport.disabled=true;if(chartReport)chartReport.disabled=true;
    fetch('tecss_vibraciones_api.php?'+new URLSearchParams({pozo:detailWell.value,desde:detailFrom.value,hasta:detailTo.value}).toString(),{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(response){return response.json().then(function(data){if(!response.ok||!data.ok)throw new Error(data.error||'No se pudo consultar el detalle.');return data;});})
      .then(function(data){var series=Array.isArray(data.series)?data.series:[],events=Array.isArray(data.events)?data.events:[],state=data.state||{};drawMetrics(state);drawEvents(events);drawChart(series,state);setDetailStatus(series.length?'Consulta completada: '+series.length.toLocaleString('es-AR')+' registros.':'No hay datos en el período.',false);})
      .catch(function(error){drawMetrics({});drawEvents([]);drawChart([],{});setDetailStatus(error.message||'No se pudo consultar el detalle.',true);});
  }
  document.getElementById('vibDetailForm').addEventListener('submit',function(event){event.preventDefault();loadDetail();});
})();
