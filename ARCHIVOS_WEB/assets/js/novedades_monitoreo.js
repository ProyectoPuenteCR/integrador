(function(root){
  'use strict';
  var states=['MANUAL','AUTOMATICO','HOA'], unknown='SIN CLASIFICAR';
  var colors={MANUAL:'#d6a13f',AUTOMATICO:'#249875',HOA:'#8064b2','SIN CLASIFICAR':'#a0adb8'};
  function text(v){return v==null?'':String(v).trim();}
  function label(v){return v==='AUTOMATICO'?'AUTOMÁTICO':v;}
  function matches(row,filters,search){
    if(search&&!Object.keys(row).some(function(k){return text(row[k]).toLocaleLowerCase('es').includes(text(search).toLocaleLowerCase('es'));}))return false;
    return filters.every(function(f){return f.exact?text(row[f.field])===f.value:text(row[f.field]).toLocaleLowerCase('es').includes(text(f.value).toLocaleLowerCase('es'));});
  }
  function groups(rows,field){var map=new Map();rows.forEach(function(r){var v=text(r[field]);map.set(v,(map.get(v)||0)+1);});return Array.from(map,function(pair){return{value:pair[0],label:pair[0]||'Sin dato',count:pair[1]};}).sort(function(a,b){return a.label.localeCompare(b.label,'es',{numeric:true});});}
  function counts(rows){var result={MANUAL:0,AUTOMATICO:0,HOA:0,'SIN CLASIFICAR':0};rows.forEach(function(row){var state=Object.prototype.hasOwnProperty.call(result,row.ESTADO)?row.ESTADO:unknown;result[state]++;});return result;}
  function timeline(history,weeks,filters){
    var present=new Set(history.map(function(r){return r.SEMANA_DESDE;}));
    var buckets=new Map();history.filter(function(row){return matches(row,filters);}).forEach(function(row){if(!buckets.has(row.SEMANA_DESDE))buckets.set(row.SEMANA_DESDE,[]);buckets.get(row.SEMANA_DESDE).push(row);});
    return states.concat([unknown]).map(function(state){return{label:label(state),state:state,values:weeks.map(function(week){return present.has(week.date)?counts(buckets.get(week.date)||[])[state]:null;})};});
  }
  function csvCell(value){var out=text(value);if(/^[=+@\-\t\r\n]/.test(out))out="'"+out;return '"'+out.replace(/"/g,'""')+'"';}
  if(typeof module!=='undefined'&&module.exports)module.exports={matches:matches,groups:groups,counts:counts,timeline:timeline,csvCell:csvCell};
  if(!root.document||!root.CLEAR_PUMPOFF)return;
  var cfg=root.CLEAR_PUMPOFF,doc=root.document,rows=cfg.rows||[],history=cfg.history||[],controls=Array.from(doc.querySelectorAll('[data-pf-filter]'));
  var table=doc.getElementById('pfWellsTable'),nodes=new Map(Array.from(table.querySelectorAll('[data-pf-row]')).map(function(node){return[Number(node.dataset.pfRow),node];}));
  var master=doc.getElementById('pfSelectAll'),charts={},visible=[],summary='Sin filtros',num=new Intl.NumberFormat('es-AR');
  function announce(s){doc.getElementById('pfStatus').textContent=s;}
  function filters(){return controls.filter(function(c){return c.value!=='';}).map(function(c){return{field:c.dataset.pfFilter,value:c.tagName==='SELECT'?JSON.parse(c.value):c.value,exact:c.tagName==='SELECT'};});}
  controls.forEach(function(control){
    var field=control.dataset.pfFilter;
    if(control.tagName==='SELECT'){
      var values=groups(rows.concat(history),field).map(function(g){return g.value;});
      if(field==='ESTADO')values=states.concat(values.includes(unknown)?[unknown]:[]);
      if(field==='ZONA')values=Array.from(new Set(cfg.zones.concat(values)));
      values.forEach(function(value){var option=doc.createElement('option');option.value=JSON.stringify(value);option.textContent=label(value)||'Sin asignar';control.appendChild(option);});
    }
    control.addEventListener(control.tagName==='SELECT'?'change':'input',refresh);
    var initial=new URLSearchParams(root.location.search).get('f_'+field);
    if(initial!==null)control.value=control.tagName==='SELECT'?JSON.stringify(initial):initial;
  });
  function apply(state,zone){
    controls.forEach(function(control){
      if(control.dataset.pfFilter==='ESTADO')control.value=control.value===JSON.stringify(state)&&!zone?'':JSON.stringify(state);
      if(zone&&control.dataset.pfFilter==='ZONA')control.value=JSON.stringify(zone);
    });refresh();
  }
  function syncMaster(){
    if(!master)return;var inputs=visible.map(function(i){return nodes.get(i).querySelector('[data-ns-report-add]');}).filter(Boolean);
    var n=inputs.filter(function(i){return i.checked;}).length;master.checked=!!inputs.length&&n===inputs.length;master.indeterminate=n>0&&n<inputs.length;
  }
  if(master)master.addEventListener('change',function(){visible.forEach(function(i){var input=nodes.get(i).querySelector('[data-ns-report-add]');if(input&&!input.disabled)input.checked=master.checked;});syncMaster();});
  table.addEventListener('change',syncMaster);root.addEventListener('clear-report-selection-loaded',syncMaster);
  var ring={id:'pfEmptyRing',afterDraw:function(chart){
    if(chart.config.type!=='doughnut'||chart.data.datasets[0].data.some(function(n){return n>0;}))return;
    var area=chart.chartArea;if(!area)return;var cx=(area.left+area.right)/2,cy=(area.top+area.bottom)/2,r=Math.max(10,Math.min(area.width,area.height)/2-24);
    chart.ctx.save();chart.ctx.strokeStyle='#e5ebee';chart.ctx.lineWidth=24;chart.ctx.beginPath();chart.ctx.arc(cx,cy,r,0,2*Math.PI);chart.ctx.stroke();chart.ctx.restore();
  }};
  function pie(id,canvas,zone){
    if(typeof root.Chart==='undefined')return;
    var item={id:id,zone:zone,states:states.slice(),total:0};
    item.chart=new root.Chart(doc.getElementById(canvas),{type:'doughnut',data:{labels:states.map(label),datasets:[{label:'Pozos',data:[0,0,0],backgroundColor:states.map(function(s){return colors[s];}),borderWidth:2,borderColor:'#fff'}]},
      options:{responsive:true,maintainAspectRatio:false,animation:false,cutout:'70%',plugins:{legend:{display:id!=='general',position:'bottom',labels:{boxWidth:9,usePointStyle:true,font:{size:10}},onClick:function(event,legend){if(item.total)apply(item.states[legend.index],zone);}},tooltip:{callbacks:{label:function(c){return c.label+': '+num.format(c.raw)+' pozos ('+(item.total?Number(c.raw)*100/item.total:0).toFixed(1)+'%)';}}}},onClick:function(event,points){if(points.length&&item.total)apply(item.states[points[0].index],zone);}},plugins:[ring]});
    charts[id]=item;
  }
  pie('general','pfGeneralChart','');cfg.zones.forEach(function(zone,i){pie('zone'+i,'pfZoneChart'+i,zone);});
  if(typeof root.Chart!=='undefined'){
    charts.trend={id:'trend',chart:new root.Chart(doc.getElementById('pfTrendChart'),{type:'line',data:{labels:cfg.weeks.map(function(w){return w.label;}),datasets:[]},options:{
      responsive:true,maintainAspectRatio:false,animation:false,spanGaps:false,interaction:{mode:'index',intersect:false},
      plugins:{legend:{position:'bottom',labels:{usePointStyle:true,boxWidth:9}},tooltip:{callbacks:{label:function(c){return c.dataset.label+': '+num.format(c.raw)+' pozos';}}}},
      scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}},
      onClick:function(event,points){if(!points.length)return;var p=points[0],item=charts.trend.series[p.datasetIndex];if(item.values[p.index]===null)return;
        var url=new URL(root.location.href);url.searchParams.set('lectura',cfg.weeks[p.index].date);
        filters().forEach(function(f){url.searchParams.set('f_'+f.field,f.value);});url.searchParams.set('f_ESTADO',item.state);root.location.href=url.toString();}
    }})};
  }else announce('No se cargó Chart.js. La grilla sigue disponible.');
  function updatePie(item,data){
    var totals=counts(data);item.states=states.concat(totals[unknown]?[unknown]:[]);item.total=data.length;
    item.chart.data.labels=item.states.map(label);item.chart.data.datasets[0].data=item.states.map(function(s){return totals[s];});
    item.chart.data.datasets[0].backgroundColor=item.states.map(function(s){return colors[s];});item.chart.update('none');
    item.payload={kind:'chart',chart_type:'doughnut',unit:'pozos',axis_label:'Estado',labels:item.states.map(label),datasets:[{label:'Pozos',values:item.states.map(function(s){return totals[s];})}]};
  }
  function refresh(){
    var selected=filters();visible=[];rows.forEach(function(row,i){var show=matches(row,selected);nodes.get(i).hidden=!show;if(show)visible.push(i);});
    var data=visible.map(function(i){return rows[i];}),total=counts(data);
    summary=selected.map(function(f){return cfg.columns[f.field]+': '+(label(f.value)||'Sin asignar');}).join(' · ')||'Sin filtros';
    doc.getElementById('pfFilterSummary').textContent=summary;doc.getElementById('pfTotal').textContent=cfg.configured?num.format(data.length):'—';
    doc.getElementById('pfRowCount').textContent=num.format(data.length)+' de '+num.format(rows.length)+' pozos en la lectura. Los mismos filtros se aplican a la evolución.';
    doc.getElementById('pfEmpty').hidden=!!data.length;
    var box=doc.getElementById('pfStateSummary');box.textContent='';
    states.concat(total[unknown]?[unknown]:[]).forEach(function(state){
      var button=doc.createElement('button');button.type='button';button.className='pfStateButton';button.dataset.state=state;button.disabled=!cfg.configured||!data.length;
      var dot=doc.createElement('i');dot.className='pfDot';dot.style.background=colors[state];var name=doc.createElement('span');name.textContent=label(state);
      var count=doc.createElement('strong');count.textContent=cfg.configured?num.format(total[state]):'—';var percent=doc.createElement('small');percent.textContent=data.length?(total[state]*100/data.length).toFixed(1)+'%':'—';
      button.append(dot,name,count,percent);button.addEventListener('click',function(){apply(state,'');});box.appendChild(button);
    });
    var outside=data.filter(function(r){return !cfg.zones.includes(r.ZONA);}).length;
    doc.getElementById('pfGeneralMessage').textContent=!cfg.configured?'Sin datos confirmados para graficar.':(!data.length?'Sin pozos para este filtro.':(outside?outside+' pozos no corresponden a las tres zonas configuradas; están incluidos en el total general.':'MANUAL · AUTOMÁTICO · HOA'+(total[unknown]?' · '+total[unknown]+' sin clasificar':'')));
    Object.keys(charts).filter(function(id){return id!=='trend';}).forEach(function(id){var item=charts[id],subset=item.zone?data.filter(function(r){return r.ZONA===item.zone;}):data;updatePie(item,subset);});
    cfg.zones.forEach(function(zone,i){var subset=data.filter(function(r){return r.ZONA===zone;});doc.getElementById('pfZoneCount'+i).textContent=cfg.configured?num.format(subset.length)+' pozos':'—';
      doc.getElementById('pfZoneMessage'+i).textContent=!cfg.configured?'Sin datos confirmados':(!subset.length?'Sin pozos para este filtro':'Clic en un estado para ver los pozos de '+zone);});
    var series=timeline(history,cfg.weeks,selected),saved=new Set(history.map(function(r){return r.SEMANA_DESDE;}));
    if(charts.trend){
      var active=series.filter(function(s){return s.state!==unknown||s.values.some(function(v){return v>0;});});charts.trend.series=active;
      charts.trend.chart.data.datasets=active.map(function(s){return{label:s.label,data:s.values,borderColor:colors[s.state],backgroundColor:colors[s.state],pointRadius:4,tension:0,fill:false,spanGaps:false};});
      charts.trend.chart.update('none');
      charts.trend.total=cfg.historyReady?saved.size:0;charts.trend.payload={kind:'chart',chart_type:'line',unit:'pozos',axis_label:'Semana',labels:cfg.weeks.map(function(w){return w.label;}),datasets:active.map(function(s){return{label:s.label,values:s.values};})};
    }
    doc.getElementById('pfTrendMessage').textContent=!cfg.historyReady?'Histórico no disponible. No se muestran valores estimados.':(!saved.size?'Todavía no hay lecturas semanales guardadas.':saved.size+' semanas con registros. Los huecos indican semanas sin captura; la lectura actual no se agrega hasta guardarla.');
    Array.from(doc.querySelectorAll('[data-pf-add-chart]')).forEach(function(button){var item=charts[button.dataset.pfAddChart];button.disabled=!item||!item.total||!!item.busy;});
    syncMaster();
  }
  doc.getElementById('pfReset').addEventListener('click',function(){controls.forEach(function(c){c.value='';});refresh();});
  Array.from(doc.querySelectorAll('[data-pf-sort]')).forEach(function(button){var ascending=false;button.addEventListener('click',function(){ascending=!ascending;var field=button.dataset.pfSort;
    Array.from(nodes.keys()).sort(function(a,b){return text(rows[a][field]).localeCompare(text(rows[b][field]),'es',{numeric:true})*(ascending?1:-1);}).forEach(function(i){table.tBodies[0].appendChild(nodes.get(i));});table.tBodies[0].appendChild(doc.getElementById('pfEmpty'));
  });});
  Array.from(doc.querySelectorAll('[data-pf-add-chart]')).forEach(function(button){button.addEventListener('click',function(){
    var item=charts[button.dataset.pfAddChart];if(!item||!item.total||item.busy||!root.CLEAR_NS_REPORT)return;item.busy=true;button.disabled=true;
    var payload=Object.assign({},item.payload,{context:(item.id==='trend'?'Última lectura guardada por semana (no cierre automático). ':cfg.label+'. ')+'Captura de pantalla: '+cfg.stamp+'. Filtros: '+summary+(item.zone?'. Zona: '+item.zone:'')});
    try{payload.image_data=item.chart.toBase64Image('image/png',1);}catch(e){item.busy=false;button.disabled=false;announce('No se pudo capturar el gráfico.');return;}
    root.CLEAR_NS_REPORT.addItems([{key:cfg.chartKeys[item.id],type:'chart',title:'PUMP OFF · '+(item.id==='trend'?'Evolución semanal':(item.zone||'General')+' · '+item.total+' pozos'),payload:payload}])
      .then(function(){announce('Gráfico agregado al reporte con sus filtros.');}).catch(function(e){announce(e.message);}).then(function(){item.busy=false;refresh();});
  });});
  var add=doc.getElementById('pfAddSelected');
  if(add)add.addEventListener('click',function(){
    var inputs=visible.map(function(i){return nodes.get(i).querySelector('[data-ns-report-add]');}).filter(function(input){return input&&input.checked&&!input.disabled;});
    if(!inputs.length){announce('Seleccioná al menos un pozo visible.');return;}
    var items=inputs.map(function(input){return{key:input.dataset.reportKey,type:'row',title:input.dataset.reportTitle,payload:JSON.parse(decodeURIComponent(escape(root.atob(input.dataset.reportPayload))))};});
    add.disabled=true;root.CLEAR_NS_REPORT.addItems(items).then(function(r){announce(r.message);}).catch(function(e){announce(e.message);}).then(function(){add.disabled=false;syncMaster();});
  });
  doc.getElementById('pfExport').addEventListener('click',function(){
    var fields=Array.from(table.tHead.rows[0].cells).filter(function(c){return c.style.display!=='none';}).map(function(c){return c.dataset.columnKey==='report'?'SEMANA':c.dataset.columnKey;});
    var lines=[['PUMP OFF',cfg.label],['Captura',cfg.stamp],['Filtros',summary],fields.map(function(f){return cfg.columns[f];})];
    Array.from(table.tBodies[0].querySelectorAll('[data-pf-row]')).filter(function(node){return!node.hidden;}).forEach(function(node){var row=rows[Number(node.dataset.pfRow)];lines.push(fields.map(function(f){return row[f];}));});
    var url=URL.createObjectURL(new Blob(['\uFEFF'+lines.map(function(line){return line.map(csvCell).join(';');}).join('\r\n')],{type:'text/csv;charset=utf-8'}));
    var a=doc.createElement('a');a.href=url;a.download='CLEAR_PUMP_OFF.csv';doc.body.appendChild(a);a.click();a.remove();root.setTimeout(function(){URL.revokeObjectURL(url);},1000);
  });
  var save=doc.getElementById('pfSaveWeek');
  if(save)save.addEventListener('click',function(){
    if(!root.confirm('Se guardarán TODOS los pozos confirmados de esta lectura, sin aplicar los filtros. Reemplaza solo la lectura de la semana actual; las anteriores se conservan. ¿Continuar?'))return;
    save.disabled=true;fetch('pumpoff_cierre_api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:cfg.token})})
      .then(function(r){return r.json();}).then(function(r){if(!r.ok)throw new Error(r.error);announce(r.message);root.location.reload();})
      .catch(function(e){announce(e.message);save.disabled=false;});
  });
  refresh();
})(typeof window!=='undefined'?window:globalThis);
