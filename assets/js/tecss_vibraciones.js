(function(){
  'use strict';
  var form=document.getElementById('vibFilterForm');
  var table=document.getElementById('vibTable');
  var modal=document.getElementById('vibModal');
  var chart=null;
  var piData={sync:{available:false},events:[]};
  var detailRequest=0;
  var columnKey='clear_tecss_vibraciones_columns_v1';
  var columnOrderKey='clear_tecss_vibraciones_column_order_v1';
  var draggedColumn='';
  var dragMoved=false;

  function esc(value){return String(value==null?'':value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
  function number(value,decimals){return value==null||value===''||isNaN(Number(value))?'—':Number(value).toLocaleString('es-AR',{minimumFractionDigits:decimals,maximumFractionDigits:decimals});}
  function dateTime(value){if(!value)return '—';var date=new Date(instant(value));return isNaN(date.getTime())?String(value):date.toLocaleString('es-AR',{timeZone:'America/Argentina/Buenos_Aires',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});}
  function inputDate(date){var year=date.getFullYear(),month=String(date.getMonth()+1).padStart(2,'0'),day=String(date.getDate()).padStart(2,'0');return year+'-'+month+'-'+day;}
  function sample(items,max){if(items.length<=max)return items.slice();var output=[],step=(items.length-1)/(max-1);for(var index=0;index<max;index++)output.push(items[Math.round(index*step)]);return output;}
  // SQL almacena hora argentina. Evitar que el huso del navegador cambie el evento.
  function instant(value){if(!value)return NaN;var text=String(value).replace(' ','T');if(!/(Z|[+-]\d\d:\d\d)$/.test(text))text+='-03:00';return Date.parse(text);}
  function piEnd(event){return instant(event.end||((piData.sync||{}).through));}
  function piSegments(){
    return (piData.events||[]).filter(function(event){return event.threshold_start!=null;}).map(function(event){return {label:'PI · umbral al inicio · '+dateTime(event.start),start:instant(event.start),end:piEnd(event),value:event.threshold_start};}).filter(function(segment){return isFinite(segment.start)&&isFinite(segment.end);});
  }

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
    if(state.UMBRAL_ALERTA!=null)datasets.push({label:'CLEAR · umbral alerta',values:points.map(function(){return state.UMBRAL_ALERTA;})});
    if(state.UMBRAL_CRITICO!=null)datasets.push({label:'CLEAR · umbral crítico',values:points.map(function(){return state.UMBRAL_CRITICO;})});
    piSegments().forEach(function(segment){datasets.push({label:segment.label,values:points.map(function(point){var time=instant(point.fecha);return time>=segment.start&&time<=segment.end?segment.value:null;})});});
    if(points.some(function(point){return point.gpm!=null;}))datasets.push({label:'GPM',values:points.map(function(point){return point.gpm;})});
    var identity=detailWell.value+'|'+detailFrom.value+'|'+detailTo.value;
    var payload={kind:'chart',chart_type:'line',unit:'VIBR / GPM',axis_label:'Fecha',labels:labels,datasets:datasets,context:'Pozo '+detailWell.value+' · '+detailFrom.value+' al '+detailTo.value+(stateLabel?' · Estado CLEAR: '+stateLabel:'')+' · PI: '+((piData.sync||{}).synced_at||'sin sincronizar')+' · Umbral PI al inicio de cada evento; no representa su evolución completa.',group:'telemetry_tecss_vibraciones_chart',group_title:'Gráficos de Vibraciones TECSS'};
    chartReport.setAttribute('data-report-key','tecss-vibraciones-chart-'+reportKey(identity));
    chartReport.setAttribute('data-report-title','Tendencia de Vibraciones TECSS · '+detailWell.value);
    chartReport.setAttribute('data-report-payload',reportEncode(payload));
    if(window.CLEAR_NS_REPORT&&typeof window.CLEAR_NS_REPORT.refresh==='function')window.CLEAR_NS_REPORT.refresh().catch(function(){});
  }
  function openModal(well,state){
    var today=new Date(),from=new Date();from.setDate(today.getDate()-7);
    detailWell.value=well;detailFrom.value=inputDate(from);detailTo.value=inputDate(today);
    document.getElementById('vibModalTitle').textContent='Tendencia · '+well;
    document.getElementById('vibModalState').textContent='Estadístico CLEAR: '+(state||'sin datos');
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
  function drawPi(data){
    piData=data||{sync:{available:false},events:[]};
    var sync=piData.sync||{},events=piData.events||[],status=document.getElementById('vibPiStatus'),list=document.getElementById('vibPiEvents');
    status.className=(!sync.available||sync.stale||!sync.covers_range)?'is-warning':'';
    if(!sync.available){status.textContent=sync.reason||'Sin sincronizar PI. No se puede determinar si hubo eventos.';list.innerHTML='';return;}
    var notes=['Sincronizado: '+dateTime(sync.synced_at),'Datos hasta: '+dateTime(sync.through)];
    if(sync.stale)notes.push('Pendiente de actualizar: los eventos abiertos pueden haber cambiado.');
    if(!sync.covers_range)notes.push('La sincronización no cubre todo el período solicitado.');
    if(piData.truncated)notes.push('Se muestran los últimos 1000 eventos; acortá el período.');
    notes.push('Valores PI multiplicados por 1000. El umbral del gráfico es el del inicio de cada evento.');
    status.textContent=notes.join(' · ');
    if(!events.length){list.innerHTML='<div class="vib-events-empty">'+(sync.covers_range&&!sync.stale?'Sin eventos PI en el período sincronizado.':'No hay eventos en la copia disponible; la cobertura no permite confirmar su ausencia.')+'</div>';return;}
    list.innerHTML=events.map(function(event){
      var duration=event.end?Math.max(0,(instant(event.end)-instant(event.start))/60000):null;
      var notification=event.notification_at?'Envío registrado por PI: '+dateTime(event.notification_at)+' (no confirma recepción).':(event.annotations_read?'Sin evidencia de envío reconocida en las anotaciones; no confirma que no se haya enviado.':'Registro de envío no disponible.');
      return '<article class="vib-pi-event"><strong>'+esc(event.end?'Evento PI cerrado':(sync.stale?'Abierto en la última sincronización':'Evento PI abierto'))+'</strong> · '+esc(dateTime(event.start))+' → '+esc(event.end?dateTime(event.end):'sin cierre registrado')+(duration==null?'':' · '+Math.floor(duration/60)+' h '+Math.floor(duration%60)+' min')+'<small>Al inicio: vibración <b>'+esc(number(event.vibration_start,3))+'</b> / umbral <b>'+esc(number(event.threshold_start,3))+'</b> · '+esc(notification)+'</small></article>';
    }).join('');
  }
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
    function values(field){return points.map(function(point){return {x:instant(point.fecha),y:point[field]};});}
    function constant(value){return points.map(function(point){return {x:instant(point.fecha),y:value};});}
    var datasets=[{label:'VIBR',data:values('vibr'),borderColor:'#2f80a3',borderWidth:2,pointRadius:points.length>160?0:2,tension:.15,spanGaps:false,yAxisID:'y'}];
    if(state.UMBRAL_ALERTA!=null)datasets.push({label:'CLEAR · umbral alerta',data:constant(state.UMBRAL_ALERTA),borderColor:'#d97706',borderWidth:1.5,borderDash:[6,5],pointRadius:0,yAxisID:'y'});
    if(state.UMBRAL_CRITICO!=null)datasets.push({label:'CLEAR · umbral crítico',data:constant(state.UMBRAL_CRITICO),borderColor:'#c53030',borderWidth:1.5,borderDash:[6,5],pointRadius:0,yAxisID:'y'});
    // Segmentos separados: no extender el umbral inicial a períodos sin evidencia PI.
    var segments=piSegments(),piPoints=[];
    segments.sort(function(a,b){return a.start-b.start;}).forEach(function(segment){piPoints.push({x:segment.start,y:segment.value},{x:segment.end,y:segment.value},{x:segment.end,y:null});});
    if(piPoints.length)datasets.push({label:'PI · umbral al inicio del evento',data:piPoints,borderColor:'#7650b1',borderWidth:2,borderDash:[3,3],pointRadius:0,spanGaps:false,yAxisID:'y'});
    if(points.some(function(point){return point.gpm!=null;}))datasets.push({label:'GPM',data:values('gpm'),borderColor:'#718096',borderWidth:1.5,pointRadius:0,spanGaps:false,yAxisID:'y1'});
    var ranges={id:'piEventRanges',beforeDatasetsDraw:function(instance){
      var area=instance.chartArea,x=instance.scales.x,ctx=instance.ctx;ctx.save();ctx.fillStyle='rgba(118,80,177,.10)';
      (piData.events||[]).forEach(function(event){var left=Math.max(area.left,x.getPixelForValue(instant(event.start))),right=Math.min(area.right,x.getPixelForValue(piEnd(event)));if(right>left)ctx.fillRect(left,area.top,right-left,area.bottom-area.top);});ctx.restore();
    }};
    chart=new Chart(document.getElementById('vibDetailChart'),{type:'line',plugins:[ranges],data:{datasets:datasets},options:{responsive:true,maintainAspectRatio:false,animation:false,interaction:{mode:'nearest',axis:'x',intersect:false},plugins:{tooltip:{callbacks:{title:function(items){return items.length?dateTime(new Date(items[0].parsed.x).toISOString()):'';}}},legend:{position:'top',labels:{color:textColor,boxWidth:18,usePointStyle:true}}},scales:{x:{type:'linear',min:instant(points[0].fecha),max:instant(points[points.length-1].fecha),ticks:{color:textColor,maxTicksLimit:7,maxRotation:0,callback:function(value){return dateTime(new Date(value).toISOString());}},grid:{display:false}},y:{position:'left',beginAtZero:true,title:{display:true,text:'VIBR · escala ×1000 de PI',color:textColor},ticks:{color:textColor},grid:{color:gridColor}},y1:{position:'right',beginAtZero:true,title:{display:true,text:'GPM',color:textColor},ticks:{color:textColor},grid:{drawOnChartArea:false}}}}});
    updateChartActions(points,state);
  }
  function loadDetail(){
    if(!detailWell.value||!detailFrom.value||!detailTo.value){setDetailStatus('Completá el rango de fechas.',true);return;}if(detailFrom.value>detailTo.value){setDetailStatus('La fecha Desde no puede ser posterior a Hasta.',true);return;}
    var request=++detailRequest;
    setDetailStatus('Consultando datos procesados…',false);detailMetrics.innerHTML='';detailEvents.innerHTML='';drawPi({sync:{available:false,reason:'Consultando eventos PI…'},events:[]});if(chart){chart.destroy();chart=null;}if(chartMaximize)chartMaximize.disabled=true;if(chartExport)chartExport.disabled=true;if(chartReport)chartReport.disabled=true;
    fetch('tecss_vibraciones_api.php?'+new URLSearchParams({pozo:detailWell.value,desde:detailFrom.value,hasta:detailTo.value}).toString(),{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(response){return response.json().then(function(data){if(!response.ok||!data.ok)throw new Error(data.error||'No se pudo consultar el detalle.');return data;});})
      .then(function(data){if(request!==detailRequest)return;var series=Array.isArray(data.series)?data.series:[],events=Array.isArray(data.events)?data.events:[],state=data.state||{};drawPi(data.pi);drawMetrics(state);drawEvents(events);drawChart(series,state);setDetailStatus(series.length?'Consulta completada: '+series.length.toLocaleString('es-AR')+' registros.':'No hay datos en el período.',false);})
      .catch(function(error){if(request!==detailRequest)return;drawPi({sync:{available:false,reason:'No se pudo consultar PI.'},events:[]});drawMetrics({});detailEvents.innerHTML='';drawChart([],{});setDetailStatus(error.message||'No se pudo consultar el detalle.',true);});
  }
  document.getElementById('vibDetailForm').addEventListener('submit',function(event){event.preventDefault();loadDetail();});
})();
