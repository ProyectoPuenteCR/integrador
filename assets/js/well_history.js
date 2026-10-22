(function(){
  'use strict';
  const cfg=window.CLEAR_TELEMETRY_GRID||{};
  if(cfg.key!=='monitoreo_pozos'||!cfg.wellHistory)return;
  const cache=new Map(),selections=new Map(),tokens=new WeakMap();
  const timezone='America/Argentina/Buenos_Aires';
  const dateFormat=new Intl.DateTimeFormat('es-AR',{timeZone:timezone,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'});
  const localDate=date=>{
    const parts=Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:timezone,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(date).map(part=>[part.type,part.value]));
    return parts.year+'-'+parts.month+'-'+parts.day+'T'+parts.hour+':'+parts.minute;
  };
  const format=value=>dateFormat.format(new Date(value));
  const detailFormat=new Intl.DateTimeFormat('es-AR',{timeZone:timezone,day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'});
  const detailed=value=>detailFormat.format(new Date(value));
  const numberFormat=new Intl.NumberFormat('es-AR',{maximumFractionDigits:6});
  const frequencyLabel=seconds=>numberFormat.format(seconds/60)+' min';
  const element=(tag,className,text)=>{const node=document.createElement(tag);if(className)node.className=className;if(text!==undefined)node.textContent=text;return node;};
  async function json(url,options){
    const response=await fetch(url,{credentials:'same-origin',...options});
    let data;try{data=await response.json();}catch(error){throw Error('No se pudo leer la respuesta. Verificá la sesión de CLEAR.');}
    if(!response.ok||!data.ok)throw Error(data.error||'No se pudo completar la consulta.');return data;
  }
  function catalog(well){
    if(!cache.has(well))cache.set(well,json('well_history_api.php?well='+encodeURIComponent(well)).catch(error=>{cache.delete(well);throw error;}));
    return cache.get(well);
  }
  async function decorate({well,body,actions,historyHost,cardsHost}){
    detach();
    const token={};tokens.set(body,token);
    if(historyHost)mount(well,historyHost,cardsHost);
    const chosen=selections.get(well)||new Map();selections.set(well,chosen);
    const button=element('button','tg-btn wh-open','Histórico');button.type='button';button.disabled=true;actions.prepend(button);
    const note=element('p','wh-catalog-status','Buscando variables con histórico en PI…');note.setAttribute('role','status');body.prepend(note);
    const fields=Array.from(body.querySelectorAll('[data-history-column]'));
    const update=()=>{button.textContent='Histórico ('+chosen.size+')';button.disabled=!chosen.size;};update();button.disabled=true;
    button.addEventListener('click',()=>open(well,Array.from(chosen.values()),button));
    if(inlineHost)context={well,variables:Array.from(chosen.values())};
    try{
      const data=await catalog(well);if(tokens.get(body)!==token)return;
      for(const column of chosen.keys())if(!data.columns[column]?.available)chosen.delete(column);
      let available=0;
      fields.forEach(field=>{
        const column=field.dataset.historyColumn,point=data.columns[column];if(!point)return;
        const label=field.querySelector('dt').textContent;
        const control=element('label','wh-pick');const check=element('input');check.type='checkbox';check.disabled=!point.available;check.checked=!!point.available&&chosen.has(column);
        check.setAttribute('aria-label','Seleccionar '+label+' para histórico');
        control.title=point.available?point.tag+(point.unit?' · '+point.unit:'')+(point.derivation_label?' · '+point.derivation_label:''):point.reason;
        control.append(check,element('span','',point.available?'Histórico':'Sin histórico verificado'));
        field.append(control);
        if(point.available)available++;
        check.addEventListener('change',()=>{
          if(check.checked&&chosen.size>=12){check.checked=false;note.textContent='Podés comparar hasta 12 variables por consulta.';return;}
          if(check.checked)chosen.set(column,{column,label});else chosen.delete(column);update();
          if(inlineHost){context.variables=Array.from(chosen.values());invalidateSelection();}
        });
      });
      note.textContent=available?'Seleccioná las variables que querés consultar. Podés elegir la frecuencia desde 30 segundos.':'No se encontraron tags verificados para este pozo.';update();
      if(inlineHost&&context?.well===well){context.variables=Array.from(chosen.values());invalidateSelection();}
    }catch(error){if(tokens.get(body)===token){note.textContent=error.message;note.classList.add('is-error');button.disabled=true;}}
  }

  const dialog=element('dialog','well-details wh-dialog');dialog.setAttribute('aria-labelledby','whTitle');
  dialog.innerHTML='<header class="well-details__head"><div><div class="well-details__eyebrow">Históricos PI · Monitoreo SCADA</div><h2 id="whTitle"></h2><p class="well-details__subtitle">Frecuencia configurable · Fechas y horarios de Argentina</p></div><div class="wh-head-actions"><button type="button" class="tg-btn" data-wh-maximize aria-label="Maximizar históricos" aria-pressed="false" title="Maximizar">⛶</button><button type="button" class="tg-btn" data-wh-close aria-label="Cerrar históricos" autofocus>×</button></div></header><div class="well-details__body"><form class="wh-toolbar"><label>Desde<input type="datetime-local" name="from" required aria-label="Histórico desde"></label><label>Hasta<input type="datetime-local" name="to" required aria-label="Histórico hasta"></label><label>Frecuencia (minutos)<input type="number" name="intervalMinutes" value="60" min="0.5" max="1440" step="0.5" required aria-label="Frecuencia en minutos" list="whIntervals"><datalist id="whIntervals"><option value="0.5">0,5 minutos</option><option value="1">1 minuto</option><option value="5">5 minutos</option><option value="15">15 minutos</option><option value="60">60 minutos</option></datalist></label><button class="tg-btn" type="submit">Traer históricos</button><button class="tg-btn" type="button" data-wh-export disabled>Exportar Excel</button><button class="tg-btn" type="button" data-wh-report disabled>Agregar al reporte</button></form><p class="wh-status" role="status" aria-live="polite"></p><div class="wh-charts"></div></div><footer class="well-details__footer"><span>Mínimo 0,5 minutos · Máximo 31 días · Hasta 60.000 muestras por consulta</span><button type="button" class="tg-btn" data-wh-close>Volver a la ficha</button></footer>';
  document.body.append(dialog);
  const form=dialog.querySelector('form'),from=form.elements.from,to=form.elements.to,frequency=form.elements.intervalMinutes;
  const status=dialog.querySelector('.wh-status'),chartBox=dialog.querySelector('.wh-charts');
  const exportButton=dialog.querySelector('[data-wh-export]'),reportButton=dialog.querySelector('[data-wh-report]');
  reportButton.hidden=!cfg.wellHistoryReport;
  let context=null,result=null,charts=[],requestNumber=0,returnTo=null,controller=null,inlineHost=null,cardsController=null,cardCharts=[];
  const destroy=()=>{charts.forEach(item=>item.chart?.destroy());charts=[];chartBox.replaceChildren();};
  const maximizeButton=dialog.querySelector('[data-wh-maximize]');
  function maximize(enabled){
    dialog.classList.toggle('is-maximized',enabled);
    maximizeButton.setAttribute('aria-pressed',String(enabled));
    maximizeButton.setAttribute('aria-label',enabled?'Restaurar tamaño':'Maximizar históricos');
    maximizeButton.title=enabled?'Restaurar tamaño':'Maximizar';
    maximizeButton.textContent=enabled?'❐':'⛶';
    requestAnimationFrame(()=>charts.forEach(item=>item.chart.resize()));
  }
  maximizeButton.addEventListener('click',()=>maximize(!dialog.classList.contains('is-maximized')));
  function close(){controller?.abort();requestNumber++;dialog.close();maximize(false);if(returnTo?.isConnected)returnTo.focus({preventScroll:true});}
  dialog.querySelectorAll('[data-wh-close]').forEach(button=>button.addEventListener('click',close));
  dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
  dialog.addEventListener('click',event=>{const rect=dialog.getBoundingClientRect();if(event.target===dialog&&(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom))close();});
  dialog.addEventListener('keydown',event=>{
    if(event.key!=='Tab')return;
    const controls=Array.from(dialog.querySelectorAll('button,input,select,a[href]')).filter(node=>!node.disabled&&node.getClientRects().length);
    const first=controls[0],last=controls[controls.length-1];
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
  });
  const home=dialog.querySelector('.well-details__body');
  function active(){return inlineHost?!!inlineHost.closest('dialog')?.open:dialog.open;}
  function detach(){
    controller?.abort();cardsController?.abort();requestNumber++;destroy();cardCharts.forEach(chart=>chart.destroy());cardCharts=[];
    if(inlineHost){home.append(form,status,chartBox);inlineHost=null;}context=null;result=null;
  }
  function invalidateSelection(){controller?.abort();requestNumber++;result=null;destroy();exportButton.disabled=reportButton.disabled=true;status.textContent=context.variables.length?'Selección: '+context.variables.length+' variables. Presioná Histórico o Traer históricos.':'Seleccioná variables con los checkbox de abajo y presioná Histórico.';}
  function mount(well,host,cardsHost){
    inlineHost=host;host.classList.add('wh-inline');host.append(form,status,chartBox);
    const end=new Date();end.setMinutes(0,0,0);from.value=localDate(new Date(end.getTime()-86400000));to.value=localDate(end);
    context={well,variables:[]};exportButton.disabled=reportButton.disabled=true;
    status.textContent='Seleccioná variables con los checkbox de abajo y presioná Histórico.';
    if(cardsHost)mountCards(well,cardsHost);
  }
  function open(well,variables,opener){
    if(!variables.length||(!inlineHost&&dialog.open))return;
    if(inlineHost){context={well,variables};load();inlineHost.scrollIntoView({block:'start',behavior:'smooth'});return;}
    context={well,variables};returnTo=opener;result=null;destroy();
    const end=new Date();end.setMinutes(0,0,0);from.value=localDate(new Date(end.getTime()-86400000));to.value=localDate(end);
    dialog.querySelector('h2').textContent=well+' · '+variables.length+' variable'+(variables.length===1?'':'s');
    exportButton.disabled=reportButton.disabled=true;dialog.showModal();load();
  }
  form.addEventListener('submit',event=>{event.preventDefault();load();});
  async function load(){
    if(!context?.variables.length){status.textContent='Seleccioná al menos una variable con los checkbox.';return;}
    if(!form.reportValidity())return;
    controller?.abort();controller=new AbortController();const number=++requestNumber;
    result=null;destroy();exportButton.disabled=reportButton.disabled=true;
    status.textContent='Consultando PI Web API…';status.classList.remove('is-error');
    try{
      const data=await json('well_history_api.php',{method:'POST',headers:{'Content-Type':'application/json'},signal:controller.signal,body:JSON.stringify({well:context.well,columns:context.variables.map(item=>item.column),from:from.value,to:to.value,intervalSeconds:Math.round(Number(frequency.value)*60)})});
      if(number!==requestNumber||!active())return;
      result=data;
      if(typeof window.Chart!=='function')throw Error('No se pudo cargar el componente de gráficos. Recargá la página.');
      render(data.series);
      const usable=data.series.filter(series=>series.samples.length&&!series.error).length;
      const samples=data.series.reduce((count,series)=>count+series.samples.length,0);
      const bad=data.series.reduce((count,series)=>count+series.samples.filter(sample=>!sample.good||sample.questionable).length,0);
      status.textContent='Frecuencia: '+frequencyLabel(data.intervalSeconds||Math.round(Number(frequency.value)*60))+' · '+usable+' de '+data.series.length+' históricos consultados · '+samples+' muestras'+(bad?' · '+bad+' sin calidad válida (se muestran como huecos)':'')+'.';
      exportButton.disabled=!samples;reportButton.disabled=!usable;
    }catch(error){if(number!==requestNumber||error.name==='AbortError')return;status.textContent=error.message;status.classList.add('is-error');}
  }
  function render(allSeries){
    const colors=['#53d6a4','#ffc857','#b8a1ef','#f57ca7','#5fc9f8','#ff9473','#c5df72','#96a9ff','#eb9ae3','#5ce0dc','#e9b486','#d3dce5'];
    const card=element('article','wh-chart');
    card.append(element('h3','','Tendencias del pozo'),element('p','wh-tag','Una escala común de mínimo a máximo · Mové el cursor o la barra para consultar valores. Tocá una tendencia para ocultarla o mostrarla.'));
    chartBox.append(card);
    const series=allSeries.filter(item=>!item.error&&item.samples.length);
    allSeries.forEach(item=>{
      const label=context.variables.find(variable=>variable.column===item.column)?.label||item.column;
      if(item.error||!item.samples.length)card.append(element('p',item.error?'wh-status is-error':'wh-tag',label+': '+(item.error||'Sin muestras en el período seleccionado.')));
    });
    if(!series.length)return;
    const timestamps=Array.from(new Set(series.flatMap(item=>item.samples.map(sample=>sample.timestamp)))).sort((a,b)=>new Date(a)-new Date(b));
    const labels=timestamps.map(detailed);
    const datasets=series.map((item,index)=>{
      const label=context.variables.find(variable=>variable.column===item.column)?.label||item.column;
      const alignedMap=new Map(item.samples.map(sample=>[sample.timestamp,sample]));
      const aligned=timestamps.map(time=>alignedMap.get(time));
      const values=aligned.map(sample=>{
        if(!sample||!sample.good||sample.questionable)return null;
        const value=sample.plot_value??(typeof sample.value==='number'?sample.value:null);
        return typeof value==='number'&&Number.isFinite(value)?value:null;
      });
      return {label:label+(item.unit?' · '+item.unit:''),data:values,yAxisID:'y',borderColor:colors[index%colors.length],
        backgroundColor:colors[index%colors.length],pointBackgroundColor:colors[index%colors.length],
        borderWidth:2,pointRadius:values.length>300?0:2,pointHoverRadius:4,spanGaps:false,
        stepped:/digital|string/i.test(item.type)||item.step,historySamples:aligned,historySeries:item};
    });
    const legend=element('div','wh-legend');legend.setAttribute('aria-label','Tendencias visibles');card.append(legend);
    const wrapper=element('div','wh-canvas'),canvas=element('canvas');
    canvas.dataset.clearChartLocal='true';canvas.setAttribute('role','img');canvas.setAttribute('aria-label','Tendencias de '+context.well+' · '+series.length+' variables');wrapper.append(canvas);card.append(wrapper);
    const tip=element('div','wh-tooltip');tip.hidden=true;wrapper.append(tip);
    const scrub=element('label','wh-scrub'),scrubTitle=element('span','','Recorrer el histórico'),slider=element('input');
    slider.type='range';slider.min='0';slider.max=String(timestamps.length-1);slider.step='1';slider.value='0';slider.setAttribute('aria-label','Recorrer valores del histórico');
    const time=element('output','wh-inspected-time');scrub.append(scrubTitle,slider,time);card.append(scrub);
    const table=element('table','wh-values');table.innerHTML='<thead><tr><th>Variable</th><th>Valor en el instante</th><th>Unidad / calidad</th></tr></thead>';
    const tbody=element('tbody');table.append(tbody);card.append(table);
    const rows=datasets.map(dataset=>{
      const row=element('tr'),name=element('th','',dataset.label),value=element('td'),quality=element('td');name.scope='row';name.style.color=dataset.borderColor;row.append(name,value,quality);tbody.append(row);return {row,value,quality};
    });
    const sampleText=sample=>!sample?'Sin muestra':sample.value===null?'Sin dato':typeof sample.value==='number'?numberFormat.format(sample.value):String(sample.value);
    let chart;
    function inspect(index,showTooltip=false){
      index=Math.max(0,Math.min(timestamps.length-1,Math.round(index)));slider.value=String(index);slider.setAttribute('aria-valuetext',labels[index]);time.textContent=labels[index];
      tip.replaceChildren(element('strong','',labels[index]));
      datasets.forEach((dataset,di)=>{
        const visible=chart.isDatasetVisible(di),sample=dataset.historySamples[index],text=sampleText(sample);
        rows[di].row.hidden=!visible;rows[di].value.textContent=text;
        rows[di].quality.textContent=[dataset.historySeries.unit,sample?(sample.good&&!sample.questionable?'Buena':'Sin calidad válida'):'Sin muestra',sample?.substituted?'Sustituido':''].filter(Boolean).join(' · ');
        if(visible){const line=element('div','',dataset.label+': '+text+(sample&&(!sample.good||sample.questionable)?' · Sin calidad válida':''));line.style.color=dataset.borderColor;tip.append(line);}
      });
      chart.$whIndex=index;tip.hidden=!showTooltip;
      if(showTooltip){const pixel=chart.scales.x.getPixelForValue(index);tip.style.left=Math.max(8,Math.min(wrapper.clientWidth-tip.offsetWidth-8,pixel+12))+'px';tip.style.top='18px';}
      chart.draw();
    }
    function toggle(index){chart.setDatasetVisibility(index,!chart.isDatasetVisible(index));chart.update('none');syncLegend();inspect(Number(slider.value));}
    const buttons=datasets.map((dataset,index)=>{
      const button=element('button','wh-series');button.type='button';button.style.setProperty('--series-color',dataset.borderColor);
      button.append(element('span','wh-dot'),document.createTextNode(dataset.label));button.addEventListener('click',()=>toggle(index));legend.append(button);return button;
    });
    const showAll=element('button','wh-show-all','Mostrar todas');showAll.type='button';legend.append(showAll);
    const syncLegend=()=>buttons.forEach((button,index)=>{button.setAttribute('aria-pressed',String(chart.isDatasetVisible(index)));button.classList.toggle('is-hidden',!chart.isDatasetVisible(index));});
    showAll.addEventListener('click',()=>{datasets.forEach((dataset,index)=>chart.setDatasetVisibility(index,true));chart.update('none');syncLegend();inspect(Number(slider.value));});
    const crosshair={id:'whCrosshair',afterDraw(chart){if(chart.$whIndex===undefined)return;const {ctx,chartArea,scales}=chart,x=scales.x.getPixelForValue(chart.$whIndex);ctx.save();ctx.strokeStyle='#a9bbc8';ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(x,chartArea.top);ctx.lineTo(x,chartArea.bottom);ctx.stroke();chart.data.datasets.forEach((dataset,index)=>{if(!chart.isDatasetVisible(index)||dataset.data[chart.$whIndex]===null)return;const y=scales.y.getPixelForValue(dataset.data[chart.$whIndex]);ctx.beginPath();ctx.arc(x,y,4,0,2*Math.PI);ctx.fillStyle=dataset.borderColor;ctx.fill();ctx.strokeStyle='#e3edf4';ctx.stroke();});ctx.restore();}};
    chart=new Chart(canvas,{type:'line',data:{labels,datasets},options:{responsive:true,maintainAspectRatio:false,animation:false,color:'#c9d9e4',
      interaction:{mode:'index',intersect:false,axis:'x'},
      onHover(event){if(event.x>=chart.chartArea.left&&event.x<=chart.chartArea.right)inspect(chart.scales.x.getValueForPixel(event.x),true);},
      onClick(event){const active=chart.getElementsAtEventForMode(event,'nearest',{intersect:true},false);if(active.length)toggle(active[0].datasetIndex);},
      plugins:{legend:{display:false},tooltip:{enabled:false},clearGlobalValues:false,datalabels:{display:false}},
      scales:{x:{ticks:{color:'#9bb1c0',maxTicksLimit:8,maxRotation:0,callback(value){return labels[value]?.replace(/,? \d{2}:\d{2}:\d{2}$/,match=>match.slice(0,-3));}},grid:{color:'#293e4b'}},
        y:{type:'linear',position:'left',ticks:{color:'#9bb1c0',maxTicksLimit:7},grid:{color:'#293e4b'}}}},
      plugins:[crosshair,{id:'whDark',beforeDraw(chart){const ctx=chart.ctx;ctx.save();ctx.globalCompositeOperation='destination-over';ctx.fillStyle='#15232d';ctx.fillRect(0,0,chart.width,chart.height);ctx.restore();}}]});
    slider.addEventListener('input',()=>inspect(Number(slider.value),true));
    canvas.addEventListener('mouseleave',()=>{tip.hidden=true;});
    syncLegend();inspect(0);
    if(!datasets.some(dataset=>dataset.data.some(value=>value!==null)))card.append(element('p','wh-status','No hay muestras numéricas con calidad válida para dibujar. Los estados se conservan en la tabla.'));
    card.append(element('p','wh-tag','Los estados digitales usan su código PI en la escala; el cursor muestra su descripción. Las variables conservan sus unidades originales.'));
    charts.push({chart,series,labels});
  }

  function mountCards(well,host){
    const heading=element('h3','','Cartas dinamométricas'),controls=element('form','wd-card-controls');
    const atLabel=element('label','','Hasta'),at=element('input');at.type='datetime-local';at.required=true;at.value=localDate(new Date());at.setAttribute('aria-label','Cartas hasta');atLabel.append(at);
    const windowLabel=element('label','','Ventana (segundos)'),windowInput=element('input');windowInput.type='number';windowInput.min='5';windowInput.max='300';windowInput.value='60';windowInput.required=true;windowInput.setAttribute('aria-label','Ventana de las cartas');windowLabel.append(windowInput);
    const get=element('button','tg-btn','Consultar cartas');get.type='submit';controls.append(atLabel,windowLabel,get);host.append(heading,controls);
    const cards=['superficie','fondo'].map(kind=>{
      const card=element('section','wd-card'),title=element('h3','','Carta de '+kind),note=element('p','wh-tag',kind==='superficie'?'X: ZT:SUP · Y: WT:SUP':'X: ZT:FONDO · Y: WT:FONDO');
      const message=element('p','wh-status','Presioná Consultar cartas para buscar datos reales de PI.'),plot=element('div','wd-card-plot');card.append(title,note,message,plot);host.append(card);return {kind,message,plot};
    });
    controls.addEventListener('submit',async event=>{
      event.preventDefault();if(!controls.reportValidity())return;
      cardsController?.abort();const pending=new AbortController();cardsController=pending;get.disabled=true;
      cardCharts.forEach(chart=>chart.destroy());cardCharts=[];cards.forEach(card=>{card.plot.replaceChildren();card.message.textContent='Consultando PI…';});
      try{
        const data=await json('well_history_api.php',{method:'POST',signal:pending.signal,headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'cards',well,at:at.value,windowSeconds:Number(windowInput.value)})});
        if(pending!==cardsController||!active())return;
        cards.forEach((card,index)=>{
          const series=data.cards.find(item=>item.kind===card.kind);
          if(!series||series.error){card.message.textContent=series?.error||'Sin datos de PI.';return;}
          card.message.textContent=series.points.filter(point=>point.y!==null).length+' pares válidos · '+detailed(data.start)+' a '+detailed(data.end)+' · Ventana temporal, sin identificación automática de ciclo.';
          const canvas=element('canvas');canvas.dataset.clearChartLocal='true';canvas.setAttribute('aria-label','Carta de '+card.kind+' de '+well);canvas.setAttribute('role','img');card.plot.append(canvas);
          const color=index?'#b2e879':'#5fc9f8';
          const chart=new Chart(canvas,{type:'scatter',data:{datasets:[{label:'Carta de '+card.kind,data:series.points,borderColor:color,backgroundColor:color,showLine:true,spanGaps:false,pointRadius:0,borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,animation:false,color:'#b7cbd8',plugins:{legend:{display:false},clearGlobalValues:false,tooltip:{callbacks:{label:item=>detailed(series.points[item.dataIndex].timestamp)+' · X: '+item.parsed.x+' · Y: '+item.parsed.y}}},scales:{x:{type:'linear',ticks:{color:'#b7cbd8'},title:{color:'#b7cbd8',display:true,text:'Posición'+(series.xUnit?' ('+series.xUnit+')':'')},grid:{color:'#293e4b'}},y:{type:'linear',ticks:{color:'#b7cbd8'},title:{color:'#b7cbd8',display:true,text:'Carga'+(series.yUnit?' ('+series.yUnit+')':'')},grid:{color:'#293e4b'}}}}});cardCharts.push(chart);
        });
      }catch(error){if(error.name!=='AbortError'&&pending===cardsController)cards.forEach(card=>card.message.textContent=error.message);}
      finally{if(pending===cardsController)get.disabled=false;}
    });
  }

  // XLSX sin dependencias externas: ZIP sin compresión y celdas tipadas.
  const xml=value=>String(value??'').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g,'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  function workbook(rows){
    const sheet='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'+rows.map((row,index)=>'<row r="'+(index+1)+'">'+row.map(value=>typeof value==='number'&&Number.isFinite(value)?'<c><v>'+value+'</v></c>':'<c t="inlineStr"><is><t xml:space="preserve">'+xml(value)+'</t></is></c>').join('')+'</row>').join('')+'</sheetData></worksheet>';
    const files={
      '[Content_Types].xml':'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
      '_rels/.rels':'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
      'xl/workbook.xml':'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Históricos" sheetId="1" r:id="rId1"/></sheets></workbook>',
      'xl/_rels/workbook.xml.rels':'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
      'xl/worksheets/sheet1.xml':sheet
    };
    const encoder=new TextEncoder(),parts=[],directory=[];let offset=0,centralSize=0;
    const header=size=>new Uint8Array(size);
    for(const [name,content] of Object.entries(files)){
      const filename=encoder.encode(name),data=encoder.encode(content);let crc=0xffffffff;
      for(const byte of data){crc^=byte;for(let bit=0;bit<8;bit++)crc=(crc>>>1)^((crc&1)?0xedb88320:0);}crc=(crc^0xffffffff)>>>0;
      const local=header(30),view=new DataView(local.buffer);view.setUint32(0,0x04034b50,true);view.setUint16(4,20,true);view.setUint16(6,0x800,true);view.setUint16(12,33,true);view.setUint32(14,crc,true);view.setUint32(18,data.length,true);view.setUint32(22,data.length,true);view.setUint16(26,filename.length,true);
      parts.push(local,filename,data);
      const central=header(46),cv=new DataView(central.buffer);cv.setUint32(0,0x02014b50,true);cv.setUint16(4,20,true);cv.setUint16(6,20,true);cv.setUint16(8,0x800,true);cv.setUint16(14,33,true);cv.setUint32(16,crc,true);cv.setUint32(20,data.length,true);cv.setUint32(24,data.length,true);cv.setUint16(28,filename.length,true);cv.setUint32(42,offset,true);directory.push(central,filename);centralSize+=central.length+filename.length;offset+=local.length+filename.length+data.length;
    }
    const end=header(22),ev=new DataView(end.buffer);ev.setUint32(0,0x06054b50,true);ev.setUint16(8,Object.keys(files).length,true);ev.setUint16(10,Object.keys(files).length,true);ev.setUint32(12,centralSize,true);ev.setUint32(16,offset,true);
    return new Blob([...parts,...directory,end],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
  }
  exportButton.addEventListener('click',()=>{
    if(!result)return;
    const rows=[['Pozo','Variable','Tag PI','Fecha y hora (Argentina)','Fecha UTC','Frecuencia (minutos)','Valor','Unidad','Calidad buena','Cuestionable','Sustituido']];
    result.series.forEach(series=>series.samples.forEach(sample=>rows.push([result.well,context.variables.find(item=>item.column===series.column)?.label||series.column,series.tag,detailed(sample.timestamp),sample.timestamp,(result.intervalSeconds||3600)/60,sample.value??'',series.unit,sample.good?'Sí':'No',sample.questionable?'Sí':'No',sample.substituted?'Sí':'No'])));
    const url=URL.createObjectURL(workbook(rows)),link=element('a');link.href=url;link.download='Historicos_'+result.well.replace(/[^\w.-]/g,'_')+'.xlsx';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  });
  reportButton.addEventListener('click',async()=>{
    if(!result||!charts.length)return;
    reportButton.disabled=true;
    try{
      const item=charts[0];
      const datasets=item.chart.data.datasets.filter((dataset,index)=>item.chart.isDatasetVisible(index));
      if(!datasets.length){status.textContent='Mostrá al menos una tendencia antes de agregar al reporte.';return;}
      const items=[{key:result.combinedReportKey,type:'chart',title:result.well+' · Tendencias',payload:{
        kind:'chart',chart_type:'line',axis_label:'Fecha (Argentina)',labels:item.labels,
        datasets:datasets.map(dataset=>({label:dataset.label,values:dataset.historySamples.map(sample=>sample&&sample.good&&!sample.questionable?sample.value:null),unit:dataset.historySeries.unit,color:dataset.borderColor})),
        group_title:'Históricos · '+result.well,
        context:detailed(result.start)+' a '+detailed(result.end)+' · Frecuencia '+frequencyLabel(result.intervalSeconds||3600)+' · Escala común automática · '+datasets.map(dataset=>dataset.historySeries.tag).join(' | '),
        scale:{auto:true,independent:false},image_data:item.chart.toBase64Image()}}];
      const response=await json('novedades_semanales_report_api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'batch',active:true,items})});
      status.textContent=response.message||'Históricos agregados al reporte.';
      document.querySelectorAll('[data-ns-report-count]').forEach(node=>{node.textContent=String(response.count);node.hidden=!response.count;});
    }catch(error){status.textContent=error.message;status.classList.add('is-error');}finally{reportButton.disabled=false;}
  });
  window.CLEAR_WELL_HISTORY={decorate,detach};
})();
