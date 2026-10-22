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
  async function decorate({well,body,actions}){
    const token={};tokens.set(body,token);
    const chosen=selections.get(well)||new Map();selections.set(well,chosen);
    const button=element('button','tg-btn wh-open','Histórico');button.type='button';button.disabled=true;actions.prepend(button);
    const note=element('p','wh-catalog-status','Buscando variables con histórico en PI…');note.setAttribute('role','status');body.prepend(note);
    const fields=Array.from(body.querySelectorAll('[data-history-column]'));
    const update=()=>{button.textContent='Histórico ('+chosen.size+')';button.disabled=!chosen.size;};update();button.disabled=true;
    button.addEventListener('click',()=>open(well,Array.from(chosen.values()),button));
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
        });
      });
      note.textContent=available?'Seleccioná las variables que querés consultar. Los históricos se toman cada una hora.':'No se encontraron tags verificados para este pozo.';update();
    }catch(error){if(tokens.get(body)===token){note.textContent=error.message;note.classList.add('is-error');button.disabled=true;}}
  }

  const dialog=element('dialog','well-details wh-dialog');dialog.setAttribute('aria-labelledby','whTitle');
  dialog.innerHTML='<header class="well-details__head"><div><div class="well-details__eyebrow">Históricos PI · Monitoreo SCADA</div><h2 id="whTitle"></h2><p class="well-details__subtitle">Una muestra por hora · Fechas y horarios de Argentina</p></div><button type="button" class="tg-btn" data-wh-close aria-label="Cerrar históricos" autofocus>×</button></header><div class="well-details__body"><form class="wh-toolbar"><label>Desde<input type="datetime-local" name="from" required aria-label="Histórico desde"></label><label>Hasta<input type="datetime-local" name="to" required aria-label="Histórico hasta"></label><span class="wh-frequency">Frecuencia: 1 hora</span><button class="tg-btn" type="submit">Traer históricos</button><button class="tg-btn" type="button" data-wh-export disabled>Exportar Excel</button><button class="tg-btn" type="button" data-wh-report disabled>Agregar al reporte</button></form><p class="wh-status" role="status" aria-live="polite"></p><div class="wh-charts"></div></div><footer class="well-details__footer"><span>Valores interpolados cada hora · Máximo 31 días por consulta</span><button type="button" class="tg-btn" data-wh-close>Volver a la ficha</button></footer>';
  document.body.append(dialog);
  const form=dialog.querySelector('form'),from=form.elements.from,to=form.elements.to;
  const status=dialog.querySelector('.wh-status'),chartBox=dialog.querySelector('.wh-charts');
  const exportButton=dialog.querySelector('[data-wh-export]'),reportButton=dialog.querySelector('[data-wh-report]');
  reportButton.hidden=!cfg.wellHistoryReport;
  let context=null,result=null,charts=[],requestNumber=0,returnTo=null,controller=null;
  const destroy=()=>{charts.forEach(item=>item.chart?.destroy());charts=[];chartBox.replaceChildren();};
  function close(){controller?.abort();requestNumber++;dialog.close();if(returnTo?.isConnected)returnTo.focus({preventScroll:true});}
  dialog.querySelectorAll('[data-wh-close]').forEach(button=>button.addEventListener('click',close));
  dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
  dialog.addEventListener('click',event=>{const rect=dialog.getBoundingClientRect();if(event.target===dialog&&(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom))close();});
  dialog.addEventListener('keydown',event=>{
    if(event.key!=='Tab')return;
    const controls=Array.from(dialog.querySelectorAll('button,input,a[href]')).filter(node=>!node.disabled&&node.getClientRects().length);
    const first=controls[0],last=controls[controls.length-1];
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
  });
  function open(well,variables,opener){
    if(!variables.length||dialog.open)return;
    context={well,variables};returnTo=opener;result=null;destroy();
    const end=new Date();end.setMinutes(0,0,0);from.value=localDate(new Date(end.getTime()-86400000));to.value=localDate(end);
    dialog.querySelector('h2').textContent=well+' · '+variables.length+' variable'+(variables.length===1?'':'s');
    exportButton.disabled=reportButton.disabled=true;dialog.showModal();load();
  }
  form.addEventListener('submit',event=>{event.preventDefault();load();});
  async function load(){
    if(!form.reportValidity())return;
    controller?.abort();controller=new AbortController();const number=++requestNumber;
    result=null;destroy();exportButton.disabled=reportButton.disabled=true;
    status.textContent='Consultando PI Web API…';status.classList.remove('is-error');
    try{
      const data=await json('well_history_api.php',{method:'POST',headers:{'Content-Type':'application/json'},signal:controller.signal,body:JSON.stringify({well:context.well,columns:context.variables.map(item=>item.column),from:from.value,to:to.value})});
      if(number!==requestNumber||!dialog.open)return;
      result=data;
      if(typeof window.Chart!=='function')throw Error('No se pudo cargar el componente de gráficos. Recargá la página.');
      render(data.series);
      const usable=data.series.filter(series=>series.samples.length&&!series.error).length;
      const samples=data.series.reduce((count,series)=>count+series.samples.length,0);
      const bad=data.series.reduce((count,series)=>count+series.samples.filter(sample=>!sample.good||sample.questionable).length,0);
      status.textContent=usable+' de '+data.series.length+' históricos consultados · '+samples+' muestras'+(bad?' · '+bad+' sin calidad válida (se muestran como huecos)':'')+'.';
      exportButton.disabled=!samples;reportButton.disabled=!usable;
    }catch(error){if(number!==requestNumber||error.name==='AbortError')return;status.textContent=error.message;status.classList.add('is-error');}
  }
  function render(allSeries){
    const colors=['#1b687e','#bd6b19','#526cc6','#15956f','#a94c84','#558724','#b44739','#656178','#007e96','#896313','#8458ae','#387343'];
    const card=element('article','wh-chart');
    card.append(element('h3','','Tendencias del pozo'),element('p','wh-tag','Todas las variables en un gráfico · Escalas automáticas independientes por variable. La leyenda permite ocultar o mostrar cada tendencia.'));
    chartBox.append(card);
    const series=allSeries.filter(item=>!item.error&&item.samples.length);
    allSeries.forEach(item=>{
      const label=context.variables.find(variable=>variable.column===item.column)?.label||item.column;
      if(item.error||!item.samples.length)card.append(element('p',item.error?'wh-status is-error':'wh-tag',label+': '+(item.error||'Sin muestras en el período seleccionado.')));
    });
    if(!series.length)return;
    // Unir por instante real, no por índice: un punto ausente deja un hueco.
    const timestamps=Array.from(new Set(series.flatMap(item=>item.samples.map(sample=>sample.timestamp)))).sort((a,b)=>new Date(a)-new Date(b));
    const labels=timestamps.map(format),scales={x:{ticks:{color:'#47616c',maxTicksLimit:8,maxRotation:0},grid:{color:'#edf1f4'}}};
    const datasets=series.map((item,index)=>{
      const label=context.variables.find(variable=>variable.column===item.column)?.label||item.column;
      const numeric=!/digital|string/i.test(item.type)&&item.samples.every(sample=>sample.value===null||typeof sample.value==='number');
      const byTime=new Map(item.samples.map(sample=>[sample.timestamp,sample]));
      const aligned=timestamps.map(time=>byTime.get(time));
      const values=aligned.map(sample=>sample&&sample.good&&!sample.questionable?sample.value:null);
      const states=Array.from(new Set(values.filter(value=>value!==null).map(String)));
      const color=colors[index%colors.length],axis='y'+index;
      const title=label+(item.unit?' · '+item.unit:'');
      scales[axis]={type:numeric?'linear':'category',axis:'y',position:index%2?'right':'left',
        // En pantallas angostas o selecciones grandes, el detalle queda en la leyenda y el tooltip.
        display:window.innerWidth>=900&&series.length<=4,
        title:{display:true,text:title,color},ticks:{color,maxTicksLimit:6},grid:{drawOnChartArea:index===0,color:'#e5ecf0'}};
      if(!numeric)scales[axis].labels=states;
      return {label:title,data:values.map(value=>numeric?value:(value===null?null:String(value))),
        yAxisID:axis,borderColor:color,backgroundColor:color,borderWidth:2,pointRadius:2,
        spanGaps:false,stepped:!numeric||item.step,historySamples:aligned,historySeries:item};
    });
    const wrapper=element('div','wh-canvas'),canvas=element('canvas');
    canvas.setAttribute('role','img');canvas.setAttribute('aria-label','Tendencias de '+context.well+' · '+series.length+' variables');wrapper.append(canvas);card.append(wrapper);
    const chart=new Chart(canvas,{type:'line',data:{labels,datasets},options:{responsive:true,maintainAspectRatio:false,animation:false,color:'#24424f',
      interaction:{mode:'index',intersect:false},
      plugins:{legend:{display:true,position:'bottom',labels:{usePointStyle:true,boxWidth:10,padding:16}},
        tooltip:{callbacks:{afterLabel:item=>item.dataset.historySamples[item.dataIndex]?.substituted?'Dato sustituido':''}}},scales},
      plugins:[{id:'whWhite',beforeDraw(chart){const ctx=chart.ctx;ctx.save();ctx.globalCompositeOperation='destination-over';ctx.fillStyle='#fff';ctx.fillRect(0,0,chart.width,chart.height);ctx.restore();}}]});
    if(!datasets.some(dataset=>dataset.data.some(value=>value!==null)))card.append(element('p','wh-status','No hay muestras con calidad válida para dibujar.'));
    const variables=series.map(item=>{
      const label=context.variables.find(variable=>variable.column===item.column)?.label||item.column;
      return label+': '+item.tag+(item.derivation_label?' · '+item.derivation_label:'');
    });
    card.append(element('p','wh-tag',variables.join(' | ')));
    charts.push({chart,series,labels});
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
    const rows=[['Pozo','Variable','Tag PI','Fecha y hora (Argentina)','Fecha UTC','Valor','Unidad','Calidad buena','Cuestionable','Sustituido']];
    result.series.forEach(series=>series.samples.forEach(sample=>rows.push([result.well,context.variables.find(item=>item.column===series.column)?.label||series.column,series.tag,format(sample.timestamp),sample.timestamp,sample.value??'',series.unit,sample.good?'Sí':'No',sample.questionable?'Sí':'No',sample.substituted?'Sí':'No'])));
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
        datasets:datasets.map(dataset=>({label:dataset.label,values:dataset.data,unit:dataset.historySeries.unit,color:dataset.borderColor})),
        group_title:'Históricos · '+result.well,
        context:format(result.start)+' a '+format(result.end)+' · Frecuencia 1 hora · Escalas automáticas independientes · '+datasets.map(dataset=>dataset.historySeries.tag).join(' | '),
        scale:{auto:true,independent:true},image_data:item.chart.toBase64Image()}}];
      const response=await json('novedades_semanales_report_api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'batch',active:true,items})});
      status.textContent=response.message||'Históricos agregados al reporte.';
      document.querySelectorAll('[data-ns-report-count]').forEach(node=>{node.textContent=String(response.count);node.hidden=!response.count;});
    }catch(error){status.textContent=error.message;status.classList.add('is-error');}finally{reportButton.disabled=false;}
  });
  window.CLEAR_WELL_HISTORY={decorate};
})();
