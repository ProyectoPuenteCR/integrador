(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else { root.CLEAR_CARTAS_DIARIAS = api; api.mount(root.document, root); }
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';
  var stateLabels = {all:'Todos los estados',fresh:'Actualizadas',stale:'Desactualizadas',missing:'Sin datos',partial:'Parciales'};
  function fold(value) { return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim(); }
  function evaluate(items, state) {
    var well = fold(state.well), battery = fold(state.battery);
    var base = items.filter(function (item) { return (!well || fold(item.well).indexOf(well) !== -1) && (!battery || fold(item.battery) === battery); });
    var counts = {all:base.length,fresh:0,stale:0,missing:0,partial:0};
    base.forEach(function (item) { if (Object.prototype.hasOwnProperty.call(counts,item.status) && item.status !== 'all') counts[item.status]++; });
    return {counts:counts,visible:base.filter(function (item) { return state.status === 'all' || item.status === state.status; })};
  }
  function preparePrint(doc) {
    var previous=doc.getElementById('cdPrintPages');if(previous)previous.remove();
    var wells=Array.prototype.filter.call(doc.querySelectorAll('article.cd-well'),function(node){return !node.hidden&&!node.closest('.cd-battery').hidden;});
    var container=doc.createElement('div');container.id='cdPrintPages';container.className='cd-print-pages';
    var total=Math.max(1,Math.ceil(wells.length/4));
    for(var start=0;start<wells.length||start===0;start+=4){
      var page=doc.createElement('section');page.className='cd-print-page';
      var header=doc.createElement('header');header.className='cd-print-heading';
      var title=doc.createElement('strong');title.textContent='CLEAR · Cartas dinamométricas';
      var subtitle=doc.createElement('span');subtitle.textContent='Corte: '+doc.body.dataset.cdCut+' · '+(doc.getElementById('cdFilterCaption').textContent||'Todos los pozos');
      header.appendChild(title);header.appendChild(subtitle);page.appendChild(header);
      var grid=doc.createElement('div');grid.className='cd-print-grid';
      wells.slice(start,start+4).forEach(function(well){
        var copy=well.cloneNode(true);copy.removeAttribute('hidden');
        copy.querySelectorAll('.cd-pick').forEach(function(node){node.remove();});
        var battery=doc.createElement('span');battery.className='cd-print-battery';battery.textContent='Batería: '+well.dataset.cdBattery;
        copy.querySelector('h3').appendChild(battery);grid.appendChild(copy);
      });
      if(!wells.length){var empty=doc.createElement('p');empty.textContent='No hay pozos para los filtros seleccionados.';grid.appendChild(empty);}
      page.appendChild(grid);
      var footer=doc.createElement('footer');footer.textContent='Superficie y fondo · Estado de cartas al corte · Página '+(Math.floor(start/4)+1)+' de '+total;
      page.appendChild(footer);container.appendChild(page);
    }
    doc.body.appendChild(container);return container;
  }
  function mount(doc, win) {
    var form=doc.getElementById('cdFilters');if(!form)return;
    var wellInput=doc.getElementById('cdWellFilter'),batteryInput=doc.getElementById('cdBatteryFilter');
    var statusLine=doc.getElementById('cdReportStatus'),countNode=doc.getElementById('cdReportCount');
    var items=Array.prototype.map.call(doc.querySelectorAll('[data-cd-well]'),function(node){return {node:node,well:node.dataset.cdWell,battery:node.dataset.cdBattery,status:node.dataset.cdStatus};});
    var sections=Array.prototype.slice.call(doc.querySelectorAll('.cd-battery'));
    var buttons=Array.prototype.slice.call(doc.querySelectorAll('[data-cd-state]'));
    var checks=Array.prototype.slice.call(doc.querySelectorAll('[data-cd-report-pick]'));
    var state={well:'',battery:'',status:'all'};
    var options=doc.getElementById('cdWellOptions');
    items.map(function(item){return item.well;}).sort(function(a,b){return a.localeCompare(b,'es',{numeric:true});}).forEach(function(well){var option=doc.createElement('option');option.value=well;options.appendChild(option);});
    function apply() {
      state.well=wellInput.value;state.battery=batteryInput.value;
      var result=evaluate(items,state),visibleNodes=new Set(result.visible.map(function(item){return item.node;}));
      items.forEach(function(item){item.node.hidden=!visibleNodes.has(item.node);});
      sections.forEach(function(section){section.hidden=!Array.prototype.some.call(section.querySelectorAll('.cd-well'),function(node){return !node.hidden;});});
      buttons.forEach(function(button){var key=button.dataset.cdState;button.querySelector('[data-cd-count]').textContent=String(result.counts[key]);button.classList.toggle('is-active',key===state.status);button.setAttribute('aria-pressed',String(key===state.status));});
      doc.getElementById('cdVisibleCount').textContent='Mostrando '+result.visible.length+' de '+result.counts.all+' pozos';
      doc.getElementById('cdNoMatches').hidden=result.visible.length!==0;
      var description=[];if(state.battery)description.push('Batería: '+state.battery);if(state.well)description.push('Pozo: '+state.well);description.push(stateLabels[state.status]);
      doc.getElementById('cdFilterCaption').textContent=description.join(' · ');
    }
    wellInput.addEventListener('input',apply);batteryInput.addEventListener('change',apply);
    form.addEventListener('submit',function(event){event.preventDefault();apply();});
    buttons.forEach(function(button){button.addEventListener('click',function(){state.status=button.dataset.cdState;apply();});});
    doc.getElementById('cdClearFilters').addEventListener('click',function(){wellInput.value='';batteryInput.value='';state.status='all';apply();});
    apply();
    win.addEventListener('beforeprint',function(){preparePrint(doc);});
    win.addEventListener('afterprint',function(){var pages=doc.getElementById('cdPrintPages');if(pages)pages.remove();});
    function say(message,error){statusLine.textContent=message;statusLine.classList.toggle('is-error',!!error);}
    function updateCount(count){countNode.textContent=String(count||0);countNode.hidden=!(count>0);}
    function selected(input,active){input.checked=active;input.closest('.cd-well').classList.toggle('cd-selected',active);input.parentNode.querySelector('span').textContent=active?'Incluido en reporte':'Agregar al reporte';}
    async function json(url,options){
      var response=await win.fetch(url,Object.assign({credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}},options||{}));
      var data;try{data=await response.json();}catch(error){throw new Error('No se pudo leer la respuesta del reporte. Verificá que tu sesión siga abierta.');}
      if(!response.ok||!data.ok)throw new Error(data.error||'No se pudo actualizar el reporte.');return data;
    }
    function textFit(ctx,text,x,y,maxWidth){var value=String(text||'');while(value.length&&ctx.measureText(value).width>maxWidth)value=value.slice(0,-1);ctx.fillText(value===text?value:value+'…',x,y);}
    async function svgImage(svg){
      var clone=svg.cloneNode(true),originals=[svg].concat(Array.prototype.slice.call(svg.querySelectorAll('*'))),copies=[clone].concat(Array.prototype.slice.call(clone.querySelectorAll('*')));
      var properties=['fill','stroke','stroke-width','stroke-linecap','stroke-linejoin','font-family','font-size','font-weight','text-anchor','opacity'];
      originals.forEach(function(node,index){var style=win.getComputedStyle(node);properties.forEach(function(property){copies[index].style.setProperty(property,style.getPropertyValue(property));});});
      clone.removeAttribute('xmlns');clone.setAttribute('width','510');clone.setAttribute('height','239');
      var source=new win.XMLSerializer().serializeToString(clone),url=win.URL.createObjectURL(new win.Blob([source],{type:'image/svg+xml;charset=utf-8'}));
      try{return await new Promise(function(resolve,reject){var image=new win.Image();image.onload=function(){resolve(image);};image.onerror=function(){reject(new Error('No se pudo capturar una de las cartas.'));};image.src=url;});}finally{win.URL.revokeObjectURL(url);}
    }
    async function capture(row) {
      var canvas=doc.createElement('canvas');canvas.width=1200;canvas.height=474;var ctx=canvas.getContext('2d');
      if(!ctx)throw new Error('El navegador no permite capturar las cartas.');
      ctx.fillStyle='#ffffff';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.fillStyle='#16445d';ctx.font='600 23px Segoe UI, Arial';
      textFit(ctx,row.dataset.cdWell+' · Batería '+row.dataset.cdBattery,24,34,1150);
      ctx.font='14px Segoe UI, Arial';textFit(ctx,'Corte: '+doc.body.dataset.cdCut+' · '+stateLabels[row.dataset.cdStatus]+' · Comunicación: '+row.dataset.cdCommunication,24,58,1150);
      var panels=Array.prototype.slice.call(row.querySelectorAll('.cd-plot'));
      for(var i=0;i<panels.length;i++){
        var panel=panels[i],x=24+i*588;ctx.fillStyle='#16445d';ctx.font='600 16px Segoe UI, Arial';ctx.fillText(panel.querySelector('h4').textContent,x,94);
        ctx.fillStyle='#557083';ctx.font='13px Segoe UI, Arial';textFit(ctx,panel.querySelector('.cd-stamp').textContent,x,116,562);
        var note=panel.querySelector('.cd-note');if(note){ctx.fillStyle='#93450b';textFit(ctx,note.textContent,x,136,562);}
        var svg=panel.querySelector('svg');if(svg){var image=await svgImage(svg);ctx.drawImage(image,x,154,564,264);}else{ctx.fillStyle='#f2f5f8';ctx.fillRect(x,154,564,264);ctx.fillStyle='#557083';ctx.font='16px Segoe UI, Arial';ctx.fillText('Sin carta válida para mostrar',x+110,286);}
      }
      ctx.fillStyle='#557083';ctx.font='12px Segoe UI, Arial';ctx.fillText('CLEAR · Cartas dinamométricas · Monitoreo de Pozos',24,454);
      var data=canvas.toDataURL('image/png');if(data.indexOf('data:image/png;base64,')!==0)throw new Error('No se pudo generar la imagen del reporte.');return data;
    }
    var enabled=doc.body.dataset.cdReportEnabled==='1';
    checks.forEach(function(input){input.disabled=true;});
    checks.forEach(function(input){
      input.addEventListener('change',async function(){
        if(!enabled)return;var active=input.checked,row=input.closest('.cd-well');input.disabled=true;
        say((active?'Agregando':'Quitando')+' cartas de '+row.dataset.cdWell+'…');
        try{
          var dates=Array.prototype.map.call(row.querySelectorAll('.cd-stamp'),function(node){return node.textContent;});
          var payload={kind:'chart',chart_type:'line',group_title:'Cartas dinamométricas · '+row.dataset.cdBattery,labels:[],datasets:[],context:'Pozo '+row.dataset.cdWell+' · Batería '+row.dataset.cdBattery+' · Corte '+doc.body.dataset.cdCut+' · '+stateLabels[row.dataset.cdStatus]+' · Comunicación: '+row.dataset.cdCommunication+' · '+dates.join(' | ')};
          if(active)payload.image_data=await capture(row);
          var result=await json('novedades_semanales_report_api.php',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'toggle',active:active,key:row.dataset.cdReportKey,type:'chart',title:row.dataset.cdWell+' · Cartas dinamométricas',payload:payload})});
          selected(input,active);updateCount(result.count);say(row.dataset.cdWell+': '+(result.message||(active?'Agregado al reporte.':'Quitado del reporte.')));
        }catch(error){selected(input,!active);say(error.message,true);}finally{input.disabled=false;}
      });
    });
    if(enabled){json('novedades_semanales_report_api.php?action=list').then(function(result){var keys=new Set(result.keys||[]);checks.forEach(function(input){selected(input,keys.has(input.closest('.cd-well').dataset.cdReportKey));input.disabled=false;});updateCount(result.count);say('Marcá el check de un pozo para incluir sus cartas de superficie y fondo en el reporte.');}).catch(function(error){checks.forEach(function(input){input.disabled=false;});say(error.message+' Podés reintentar marcando el check del pozo.',true);});}
  }
  return {fold:fold,evaluate:evaluate,preparePrint:preparePrint,mount:mount};
});
