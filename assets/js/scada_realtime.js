(function(){
  'use strict';

  var page=window.CLEAR_SCADA_PAGE||{};
  var state={
    bootstrap:null,items:[],rendered:[],selected:null,selectedPi:null,range:'24h',chart:null,
    historyRows:[],lastResponse:null,sortKey:'tag',sortDir:'asc',requestId:0,tagRequestId:0,loading:false,timer:null
  };

  var el=function(id){return document.getElementById(id);};
  var filters={zone:el('filterZone'),battery:el('filterBattery'),node:el('filterNode'),area:el('filterArea'),quality:el('filterQuality'),tag:el('filterTag'),search:el('filterSearch')};

  function escapeHtml(value){return String(value===null||value===undefined?'':value).replace(/[&<>'"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c];});}
  function normalizeTag(value){return String(value||'').replace(/\u00a0/g,' ').replace(/\ufeff/g,'').trim().replace(/^["']+|["']+$/g,'').trim();}
  function pointMatches(name,tag){var point=normalizeTag(name).toUpperCase(),wanted=normalizeTag(tag).toUpperCase();return !!wanted&&(point===wanted||point==='LHC_'+wanted||point.endsWith('_'+wanted));}
  function fetchJson(url,options){return fetch(url,options||{}).then(function(response){return response.json().catch(function(){return {};}).then(function(data){if(!response.ok||data.ok===false)throw new Error(data.error||('Error HTTP '+response.status));return data;});});}
  function showStatus(message,type){var box=el('scadaStatus');if(!message){box.hidden=true;box.textContent='';box.className='scadaNotice';return;}box.hidden=false;box.textContent=message;box.className='scadaNotice '+(type?'is-'+type:'');}
  function option(value,label){var item=document.createElement('option');item.value=value;item.textContent=label||value;return item;}
  function fillSelect(select,values,allLabel,keep){var current=keep?select.value:'';select.innerHTML='';select.appendChild(option('',allLabel));values.forEach(function(value){select.appendChild(option(value,value));});if(current&&values.indexOf(current)>=0)select.value=current;}
  function number(value,digits){var n=Number(value);if(!isFinite(n))return String(value||'—');return new Intl.NumberFormat('es-AR',{maximumFractionDigits:digits===undefined?4:digits}).format(n);}
  function formatDate(value,withSeconds){if(!value)return '—';var d=new Date(value);if(isNaN(d.getTime()))return String(value);return d.toLocaleString('es-AR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:withSeconds===false?undefined:'2-digit'});}
  function formatTime(value){if(!value)return '—';var d=new Date(value);if(isNaN(d.getTime()))return '—';return d.toLocaleTimeString('es-AR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});}
  function ageLabel(value){if(!value)return 'Sin fecha de datos';var seconds=Math.max(0,Math.round((Date.now()-new Date(value).getTime())/1000));if(seconds<60)return 'hace '+seconds+' s';if(seconds<3600)return 'hace '+Math.round(seconds/60)+' min';return 'hace '+Math.round(seconds/3600)+' h';}
  function localInputDate(date){var pad=function(n){return String(n).padStart(2,'0');};return date.getFullYear()+'-'+pad(date.getMonth()+1)+'-'+pad(date.getDate())+'T'+pad(date.getHours())+':'+pad(date.getMinutes());}

  function updateBatteryOptions(){
    if(!state.bootstrap)return;
    var zone=filters.zone.value;
    var batteries=state.bootstrap.batteries.filter(function(item){return !zone||item.zone===zone;});
    var names=[];batteries.forEach(function(item){if(names.indexOf(item.battery)<0)names.push(item.battery);});
    fillSelect(filters.battery,names,'Todas',true);
    if(!filters.battery.value&&names.length)filters.battery.value=names[0];
    renderBatteries(batteries);
    updateTagOptions();
  }

  function updateTagOptions(){
    if(!state.bootstrap||!filters.tag)return;
    var current=filters.tag.value,id=++state.tagRequestId;
    if(!filters.battery.value){
      fillSelect(filters.tag,[],'Elegí una batería');
      filters.tag.disabled=true;
      return;
    }
    filters.tag.disabled=true;
    fillSelect(filters.tag,[],'Cargando TAGs…');
    var params=new URLSearchParams({action:'tags',zone:filters.zone.value,battery:filters.battery.value,node:filters.node.value,area:filters.area.value});
    fetchJson('scada_realtime_api.php?'+params.toString(),{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(data){
        if(id!==state.tagRequestId)return;
        var tags=data.tags||[];
        fillSelect(filters.tag,tags,data.truncated?'Primeros 2.500 TAGs':'Todos');
        if(current&&tags.indexOf(current)>=0)filters.tag.value=current;
        filters.tag.disabled=false;
      })
      .catch(function(error){
        if(id!==state.tagRequestId)return;
        fillSelect(filters.tag,[],'No disponible');
        filters.tag.disabled=false;
        showStatus('Los valores pueden consultarse, pero falló el combo TAG: '+error.message,'warn');
      });
  }

  function renderBatteries(items){
    var strip=el('batteryStrip');
    if(!items.length){strip.innerHTML='<div class="batteryEmpty">No hay baterías para la zona seleccionada.</div>';return;}
    strip.innerHTML=items.map(function(item){
      var active=filters.battery.value===item.battery?' is-active':'';
      return '<button type="button" class="batteryCard'+active+'" data-battery="'+escapeHtml(item.battery)+'">'+
        '<span class="batteryCard__icon">▥</span><span><b>'+escapeHtml(item.battery)+'</b><small>'+number(item.variables,0)+' variables · <span class="batteryCard__quality">'+number(item.quality_percent,1)+'% Good</span></small><small class="batteryCard__alarms">'+number(item.alarm_count||0,0)+' alarmas</small></span></button>';
    }).join('');
  }

  function loadBootstrap(){
    if(!page.ready)return;
    showStatus('Cargando configuración de SCADA Real time…','');
    fetchJson('scada_realtime_api.php?action=bootstrap',{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(data){
        state.bootstrap=data;
        fillSelect(filters.zone,data.filters.zones||[],'Todas');
        fillSelect(filters.node,data.filters.nodes||[],'Todos');
        fillSelect(filters.area,data.filters.areas||[],'Todos');
        fillSelect(filters.quality,data.filters.qualities||[],'Todas');
        if((data.filters.zones||[]).length)filters.zone.value=data.filters.zones[0];
        updateBatteryOptions();
        el('refreshLabel').textContent='Cada '+data.settings.refresh_seconds+' s';
        el('alarmWindowLabel').textContent='Campana: últimas '+data.settings.alarm_window_hours+' h';
        el('metricAlarmLabel').textContent='Alarmas últimas '+data.settings.alarm_window_hours+' h';
        showStatus('','');
        startTimer(data.settings.refresh_seconds);
        loadValues();
      })
      .catch(function(error){setOffline(error.message);});
  }

  function startTimer(seconds){
    if(state.timer)clearInterval(state.timer);
    state.timer=setInterval(function(){if(!document.hidden)loadValues(true);},Math.max(5,Number(seconds)||10)*1000);
  }

  function setOffline(message){
    var live=el('scadaLive');live.classList.add('is-error');live.querySelector('b').textContent='Sin conexión';
    showStatus(message||'No se pudieron actualizar los valores SCADA.','error');
  }
  function setOnline(){var live=el('scadaLive');live.classList.remove('is-error');live.querySelector('b').textContent='En línea';}

  function loadValues(silent){
    if(!page.ready||state.loading)return;
    state.loading=true;var id=++state.requestId;el('refreshNow').classList.add('is-loading');
    if(!silent)showStatus('Actualizando valores actuales…','');
    var params=new URLSearchParams({action:'values',zone:filters.zone.value,battery:filters.battery.value,node:filters.node.value,area:filters.area.value,quality:filters.quality.value,tag:filters.tag.value,search:filters.search.value,limit:'1000'});
    fetchJson('scada_realtime_api.php?'+params.toString(),{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(data){if(id!==state.requestId)return;state.items=data.items||[];state.lastResponse=data;renderValues(data);setOnline();showStatus('','');})
      .catch(function(error){if(id===state.requestId)setOffline(error.message);})
      .finally(function(){if(id===state.requestId){state.loading=false;el('refreshNow').classList.remove('is-loading');}});
  }

  function statusClass(item){if(item.is_stale)return 'is-warn';if(item.is_good)return 'is-good';return 'is-bad';}
  function qualityClass(item){if(item.is_stale)return 'is-warn';if(item.is_good)return '';return 'is-bad';}
  function bellSvg(){return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a2.4 2.4 0 0 0 2.25-1.55h-4.5A2.4 2.4 0 0 0 12 22Zm7-5.2-1.7-2.1V9.5a5.35 5.35 0 0 0-4.15-5.2V3a1.15 1.15 0 0 0-2.3 0v1.3A5.35 5.35 0 0 0 6.7 9.5v5.2L5 16.8V18h14v-1.2Z"/></svg>';}
  function alarmButton(item){
    if(!(item.alarm_count>0))return '';
    var end=new Date(),start=new Date(end.getTime()-((state.bootstrap&&state.bootstrap.settings.alarm_window_hours)||24)*3600000);
    return '<button type="button" class="alarmBell" data-sql-history="true" data-sql-tag="'+escapeHtml(item.alarm_tag||item.tag)+'" data-sql-start="'+escapeHtml(localInputDate(start))+'" data-sql-end="'+escapeHtml(localInputDate(end))+'" title="Ver '+item.alarm_count+' alarmas SQL y comentarios">'+bellSvg()+'<span>'+number(item.alarm_count,0)+'</span></button>';
  }
  function sortValue(item,key){
    if(key==='status')return item.is_stale?1:(item.is_good?0:2);
    if(key==='value'&&item.numeric_value!==null&&item.numeric_value!==undefined)return Number(item.numeric_value);
    if(key==='quality')return item.is_stale?'Stale':(item.quality||'');
    return String(item[key]===null||item[key]===undefined?'':item[key]).toLocaleUpperCase('es-AR');
  }
  function sortedItems(){
    var direction=state.sortDir==='desc'?-1:1,key=state.sortKey;
    return state.items.slice().sort(function(a,b){var av=sortValue(a,key),bv=sortValue(b,key);if(typeof av==='number'&&typeof bv==='number')return (av-bv)*direction;return String(av).localeCompare(String(bv),'es',{numeric:true,sensitivity:'base'})*direction;});
  }
  function updateSortHeaders(){
    Array.prototype.forEach.call(document.querySelectorAll('[data-sort-column]'),function(th){var active=th.getAttribute('data-sort-column')===state.sortKey;th.setAttribute('aria-sort',active?(state.sortDir==='asc'?'ascending':'descending'):'none');var mark=th.querySelector('.scadaSort span');if(mark)mark.textContent=active?(state.sortDir==='asc'?'▲':'▼'):'';});
  }

  function renderValues(data){
    var body=el('valuesBody'),metrics=data.metrics||{};
    el('metricVariables').textContent=number(metrics.total||0,0);
    el('metricShown').textContent=(metrics.shown||0)+' visibles en la grilla';
    el('metricGood').textContent=number(metrics.good||0,0);
    el('metricGoodPct').textContent=(metrics.total?number((metrics.good*100)/metrics.total,1):'0')+'% del filtro actual';
    el('metricBad').textContent=number(metrics.without_communication||0,0);
    el('metricAlarms').textContent=number(metrics.alarms||0,0);
    el('metricTime').textContent=formatDate(metrics.latest,false);
    el('metricAge').textContent=ageLabel(metrics.latest);
    el('valuesTitle').textContent='Valores actuales'+(filters.battery.value?' · '+filters.battery.value:'');
    updateSortHeaders();
    if(!state.items.length){state.rendered=[];body.innerHTML='<tr><td colspan="7" class="scadaEmpty">No hay variables para los filtros seleccionados.</td></tr>';return;}
    state.rendered=sortedItems();
    body.innerHTML=state.rendered.map(function(item,index){
      var bell=alarmButton(item),unlinked=normalizeTag(item.pi_webid)===''?' is-unlinked':'',tagTitle=unlinked?'Sin WebId vinculado':'Seleccionar histórico PI';
      var selected=state.selected&&state.selected.tag===item.tag?' class="is-selected"':'';
      return '<tr data-index="'+index+'"'+selected+'><td><div class="scadaStateCell"><span class="scadaStatusDot '+statusClass(item)+'" title="'+escapeHtml(item.is_stale?'Dato vencido':item.quality)+'"></span>'+bell+'</div></td><td><div class="scadaTagCell"><button type="button" class="scadaTagButton'+unlinked+'" data-history="1" title="'+tagTitle+'">'+escapeHtml(item.tag)+'</button></div></td><td>'+escapeHtml(item.description||'—')+'</td><td>'+escapeHtml(item.value||'—')+'</td><td>'+escapeHtml(item.unit||'—')+'</td><td><span class="qualityPill '+qualityClass(item)+'">'+escapeHtml(item.is_stale?'Stale':(item.quality||'Sin dato'))+'</span></td><td><button type="button" class="historyButton" data-history="1" aria-label="Ver histórico PI" title="Ver histórico PI">⌁</button></td></tr>';
    }).join('');
    if(state.selected){var refreshed=state.items.find(function(item){return item.tag===state.selected.tag;});if(refreshed){state.selected=refreshed;updateSelectedCurrent();}}
  }

  function selectItem(item){
    state.selected=item;state.selectedPi=null;
    clearChart('Resolviendo punto PI…');
    Array.prototype.forEach.call(el('valuesBody').querySelectorAll('tr'),function(row){row.classList.toggle('is-selected',state.rendered[Number(row.getAttribute('data-index'))]===item);});
    updateSelectedCurrent();
    el('historyTitle').textContent=item.description||item.tag;
    el('historySubtitle').textContent=item.tag+' · Fuente: PI Web API';
    el('detailTag').textContent=item.tag;el('detailDescription').textContent=item.description||'—';el('detailUnit').textContent=item.unit||'—';el('detailNode').textContent=item.node||'—';
    var vision=el('piVisionButton');if(item.pi_vision_url){vision.href=item.pi_vision_url;vision.hidden=false;}else{vision.hidden=true;vision.removeAttribute('href');}
    resolvePoint(item).then(function(point){if(!state.selected||state.selected.tag!==item.tag)return;state.selectedPi=point;if(!state.selected.unit&&point.EngineeringUnits){state.selected.unit=point.EngineeringUnits;updateSelectedCurrent();el('detailUnit').textContent=point.EngineeringUnits;}el('piLinkState').textContent='Punto PI vinculado';el('piLinkState').className='piLinkState is-linked';el('detailPiPoint').textContent=point.Name||item.pi_point||item.tag;loadHistory();}).catch(function(error){if(!state.selected||state.selected.tag!==item.tag)return;state.selectedPi=null;el('piLinkState').textContent='Punto PI no vinculado';el('piLinkState').className='piLinkState is-error';el('detailPiPoint').textContent='—';clearChart(error.message||'No se encontró el punto en PI.');});
  }

  function updateSelectedCurrent(){if(!state.selected)return;var value=state.selected.value||'—';el('historyCurrent').textContent=value+(state.selected.unit?' '+state.selected.unit:'');el('historyCurrentAt').textContent=formatDate(state.selected.opc_time);}
  function piProxy(path){return fetchJson('pi_proxy.php?path='+encodeURIComponent(path),{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}});}
  function resolvePoint(item){
    var existingWebId=normalizeTag(item.pi_webid);
    if(existingWebId)return Promise.resolve({WebId:existingWebId,Name:normalizeTag(item.pi_point||item.tag),EngineeringUnits:item.unit||'',Descriptor:item.description||''});
    var pointName=normalizeTag(item.pi_point||item.tag);
    var localPath='scada_realtime_api.php?action=resolve_pi&tag='+encodeURIComponent(pointName);
    return fetchJson(localPath,{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(data){if(!data.point||!data.point.WebId)throw new Error('PI_Points_Stage no devolvió WebId.');return data.point;})
      .catch(function(){
        var ds=state.bootstrap&&state.bootstrap.pi?state.bootstrap.pi.ds_webid:'';
        if(!ds)throw new Error('El TAG no está en PI_Points_Stage y falta configurar el Data Server WebID.');
        var path='/dataservers/'+encodeURIComponent(ds)+'/points?nameFilter='+encodeURIComponent('*'+pointName+'*')+'&maxCount=50&selectedFields=Items.WebId;Items.Name;Items.Descriptor;Items.EngineeringUnits;Items.Path';
        return piProxy(path).then(function(data){var items=Array.isArray(data.Items)?data.Items:[];var exact=items.find(function(point){return pointMatches(point.Name,pointName);});if(!exact)throw new Error('El TAG no tiene un punto PI vinculado.');exact.Name=normalizeTag(exact.Name);return exact;});
      });
  }

  function rangeQuery(){
    if(state.range==='custom'){
      var from=el('historyFrom').value,to=el('historyTo').value;if(!from||!to||from>to)throw new Error('Completá un rango personalizado válido.');
      return {start:new Date(from).toISOString(),end:new Date(to).toISOString(),interval:'10m'};
    }
    var map={"1h":['*-1h','1m'],"8h":['*-8h','5m'],"24h":['*-24h','10m'],"7d":['*-7d','1h']};var current=map[state.range]||map['24h'];return {start:current[0],end:'*',interval:current[1]};
  }

  function loadHistory(){
    if(!state.selectedPi||!state.selectedPi.WebId)return;
    var query;try{query=rangeQuery();}catch(error){clearChart(error.message);return;}
    var mode=el('historyMode').value;
    var path='/streams/'+encodeURIComponent(state.selectedPi.WebId)+'/'+mode+'?startTime='+encodeURIComponent(query.start)+'&endTime='+encodeURIComponent(query.end)+'&maxCount=2000&selectedFields=Items.Timestamp;Items.Value;Items.Good;Items.Questionable;Items.Substituted;UnitsAbbreviation';
    if(mode==='interpolated')path+='&interval='+encodeURIComponent(query.interval);
    el('historyEmpty').textContent='Consultando PI Web API…';el('historyEmpty').style.display='grid';
    piProxy(path).then(function(data){var items=Array.isArray(data.Items)?data.Items:[];drawHistory(items);}).catch(function(error){clearChart(error.message||'No se pudo consultar PI Web API.');});
  }

  function piNumber(value){if(value&&typeof value==='object'){if(value.Value!==undefined)value=value.Value;else if(value.Name!==undefined)value=value.Name;}if(typeof value==='string')value=value.replace(',','.');var n=Number(value);return isFinite(n)?n:null;}
  function drawHistory(rawItems){
    var points=rawItems.map(function(item){return {time:item.Timestamp,value:piNumber(item.Value),good:item.Good!==false&&!item.Questionable};}).filter(function(item){return item.value!==null&&item.time;});
    if(!points.length){clearChart('PI no devolvió valores numéricos para este rango.');return;}
    state.historyRows=points;el('exportHistoryExcel').disabled=false;
    var values=points.map(function(item){return item.value;});var sum=values.reduce(function(a,b){return a+b;},0);var unit=(state.selectedPi.EngineeringUnits||state.selected.unit||'');
    el('historyMin').textContent=number(Math.min.apply(Math,values),4)+(unit?' '+unit:'');el('historyAvg').textContent=number(sum/values.length,4)+(unit?' '+unit:'');el('historyMax').textContent=number(Math.max.apply(Math,values),4)+(unit?' '+unit:'');
    el('detailPiTime').textContent=formatDate(points[points.length-1].time);if(!state.selected.unit&&unit)el('detailUnit').textContent=unit;
    if(state.chart)state.chart.destroy();
    state.chart=new Chart(el('historyChart').getContext('2d'),{type:'line',data:{labels:points.map(function(item){return new Date(item.time).toLocaleString('es-AR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});}),datasets:[{label:state.selected.tag,data:values,borderColor:'#0b91a6',backgroundColor:'rgba(35,190,202,.16)',fill:true,tension:.25,pointRadius:points.length>180?0:2,pointHoverRadius:4,borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,animation:false,interaction:{mode:'index',intersect:false},plugins:{legend:{display:false},tooltip:{callbacks:{label:function(context){return number(context.parsed.y,4)+(unit?' '+unit:'');}}}},scales:{x:{ticks:{maxTicksLimit:7,maxRotation:0},grid:{color:'rgba(120,150,165,.12)'}},y:{ticks:{callback:function(value){return number(value,3);}},title:{display:!!unit,text:unit},grid:{color:'rgba(120,150,165,.16)'}}}}});
    el('historyEmpty').style.display='none';
  }
  function clearChart(message){state.historyRows=[];el('exportHistoryExcel').disabled=true;if(state.chart){state.chart.destroy();state.chart=null;}el('historyMin').textContent='—';el('historyAvg').textContent='—';el('historyMax').textContent='—';el('detailPiTime').textContent='—';el('historyEmpty').textContent=message||'Sin datos.';el('historyEmpty').style.display='grid';}

  function safeFile(value){return normalizeTag(value).replace(/[^A-Za-z0-9_-]+/g,'_')||'TAG';}
  function exportHistoryExcel(){
    if(!state.selected||!state.selectedPi||!state.historyRows.length)return;
    var unit=state.selectedPi.EngineeringUnits||state.selected.unit||'',point=state.selectedPi.Name||state.selected.pi_point||state.selected.tag;
    var values=state.historyRows.map(function(item){return item.value;}),sum=values.reduce(function(a,b){return a+b;},0);
    var html='<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:Arial;color:#17313b}table{border-collapse:collapse}th,td{border:1px solid #9fb2bd;padding:6px 8px}th{background:#174f5e;color:#fff}.summary td:first-child{font-weight:bold;background:#eef5f7}</style></head><body><h2>CLEAR · Histórico PI</h2>'+
      '<table class="summary"><tr><td>TAG SCADA</td><td>'+escapeHtml(state.selected.tag)+'</td></tr><tr><td>Punto PI</td><td>'+escapeHtml(point)+'</td></tr><tr><td>Descripción</td><td>'+escapeHtml(state.selected.description||'')+'</td></tr><tr><td>Unidad</td><td>'+escapeHtml(unit)+'</td></tr><tr><td>Modo</td><td>'+escapeHtml(el('historyMode').value)+'</td></tr><tr><td>Mínimo</td><td>'+escapeHtml(Math.min.apply(Math,values))+'</td></tr><tr><td>Promedio</td><td>'+escapeHtml(sum/values.length)+'</td></tr><tr><td>Máximo</td><td>'+escapeHtml(Math.max.apply(Math,values))+'</td></tr></table><br><table><thead><tr><th>Fecha/hora</th><th>TAG SCADA</th><th>Punto PI</th><th>Valor</th><th>Unidad</th><th>Calidad</th></tr></thead><tbody>';
    state.historyRows.forEach(function(item){html+='<tr><td>'+escapeHtml(formatDate(item.time))+'</td><td>'+escapeHtml(state.selected.tag)+'</td><td>'+escapeHtml(point)+'</td><td>'+escapeHtml(item.value)+'</td><td>'+escapeHtml(unit)+'</td><td>'+(item.good?'Good':'Questionable')+'</td></tr>';});
    html+='</tbody></table></body></html>';
    var blob=new Blob(['\uFEFF'+html],{type:'application/vnd.ms-excel;charset=utf-8;'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='CLEAR_PI_'+safeFile(state.selected.tag)+'_'+new Date().toISOString().slice(0,10)+'.xls';document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(url);},500);
  }

  el('valuesBody').addEventListener('click',function(event){
    if(event.target.closest('[data-sql-history="true"]'))return;
    var row=event.target.closest('tr[data-index]');if(!row)return;var item=state.rendered[Number(row.getAttribute('data-index'))];if(item)selectItem(item);
  });
  el('batteryStrip').addEventListener('click',function(event){var card=event.target.closest('[data-battery]');if(!card)return;filters.tag.value='';filters.battery.value=card.getAttribute('data-battery');renderBatteries(state.bootstrap.batteries.filter(function(item){return !filters.zone.value||item.zone===filters.zone.value;}));updateTagOptions();loadValues();});
  filters.zone.addEventListener('change',function(){filters.tag.value='';updateBatteryOptions();loadValues();});
  [filters.battery,filters.node,filters.area,filters.quality].forEach(function(select){select.addEventListener('change',function(){if(select!==filters.quality)filters.tag.value='';if(select===filters.battery)renderBatteries(state.bootstrap.batteries.filter(function(item){return !filters.zone.value||item.zone===filters.zone.value;}));if(select!==filters.quality)updateTagOptions();loadValues();});});
  filters.tag.addEventListener('change',function(){loadValues();});
  var searchTimer;filters.search.addEventListener('input',function(){clearTimeout(searchTimer);searchTimer=setTimeout(function(){loadValues();},350);});
  el('refreshNow').addEventListener('click',function(){loadValues();});
  Array.prototype.forEach.call(document.querySelectorAll('[data-sort]'),function(button){button.addEventListener('click',function(){var key=button.getAttribute('data-sort');if(state.sortKey===key)state.sortDir=state.sortDir==='asc'?'desc':'asc';else{state.sortKey=key;state.sortDir='asc';}if(state.lastResponse)renderValues(state.lastResponse);});});
  Array.prototype.forEach.call(document.querySelectorAll('[data-range]'),function(button){button.addEventListener('click',function(){state.range=button.getAttribute('data-range');Array.prototype.forEach.call(document.querySelectorAll('[data-range]'),function(item){item.classList.toggle('is-active',item===button);});el('customRange').hidden=state.range!=='custom';if(state.range!=='custom')loadHistory();});});
  el('historyMode').addEventListener('change',loadHistory);el('applyCustomRange').addEventListener('click',loadHistory);el('exportHistoryExcel').addEventListener('click',exportHistoryExcel);
  document.addEventListener('visibilitychange',function(){if(!document.hidden)loadValues(true);});

  var now=new Date(),yesterday=new Date(now.getTime()-86400000);el('historyFrom').value=localInputDate(yesterday);el('historyTo').value=localInputDate(now);
  if(page.ready)loadBootstrap();
})();
