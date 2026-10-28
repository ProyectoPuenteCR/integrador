(function(){
  'use strict';
  var form=document.getElementById('sigmaHistoryForm'),well=document.getElementById('historyWell'),from=document.getElementById('historyFrom'),to=document.getElementById('historyTo'),apply=document.getElementById('historyApply'),status=document.getElementById('historyStatus'),rowsBox=document.getElementById('historyRows'),empty=document.getElementById('historyEmpty');
  var items=[],events=[],records=[],chart=null,request=0,controller=null;
  var zone='America/Argentina/Buenos_Aires';
  function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
  function number(v,d){return v==null||isNaN(Number(v))?'—':Number(v).toLocaleString('es-AR',{minimumFractionDigits:d,maximumFractionDigits:d});}
  function date(v){if(!v)return '—';var d=new Date(v);return isNaN(d.getTime())?String(v):d.toLocaleString('es-AR',{timeZone:zone,day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'});}
  function stamp(v){return new Date(v).getTime();}
  function range(){return [stamp(from.value+'T00:00:00-03:00'),stamp(to.value+'T00:00:00-03:00')+86400000];}
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
  function buildRecords(){
    var bounds=range();
    records=items.map(function(x){return Object.assign({},x,{kind:'measurement',time:x.capture_iso||x.capture,event_label:'Medición SQL',email_status:'No corresponde'});});
    events.forEach(function(event){
      records.push({kind:'alert',time:event.start,event:event,event_label:'Alerta 3Sigma',email_status:!event.audit_available?'Auditoría no disponible':event.audits.length?'Ver envíos registrados':'Sin registro de email',detail:!event.audit_available?'No se pudo consultar la auditoría.':event.audits.length?'Cada envío registrado figura en su propia fila.':'No hay evidencia de email en las anotaciones leídas; no significa que no se haya enviado.'});
      event.audits.forEach(function(audit){
        var time=audit.sent_at||audit.recorded_at;
        // Filter delivery attempts by their own date, independently of the alert.
        if(time&&(stamp(time)<bounds[0]||stamp(time)>=bounds[1]))return;
        records.push({kind:'email',time:time,event:event,audit:audit,event_label:'Email · alerta 3Sigma',email_status:audit.status,
          detail:'Estado PI: '+(audit.status_raw||'no registrado')+' · Reintentos: '+(audit.retry_count==null?'no registrados':audit.retry_count)+' · Registro: '+date(audit.recorded_at)});
      });
    });
    records.sort(function(a,b){return (stamp(a.time)||0)-(stamp(b.time)||0);});
  }
  function cells(x){
    var audit=x.audit||{},recipients=audit.recipients||[];
    return [date(x.time),x.today?date(x.today):'—',number(x.sigma_numeric,4),number(x.excess_numeric,0),x.battery||'—',x.method||'—',x.event_label,x.email_status,audit.sent_at?date(audit.sent_at):'—',x.kind==='email'?(recipients.length?recipients.join('; '):'No registrados'):'—',audit.error||((x.kind==='email'&&x.email_status==='Error')?'Error sin detalle registrado por PI':x.detail||'—')];
  }
  function drawTable(){
    rowsBox.innerHTML=records.slice().reverse().map(function(x){
      var values=cells(x);
      return '<tr class="sigma-history__row is-'+x.kind+'">'+values.map(function(value,i){
        var content=esc(value);
        if(i===6&&x.event)content='<details><summary>'+content+'</summary><div>'+esc(x.event.name)+'<br>ID: '+esc(x.event.id)+'<br>Inicio: '+esc(date(x.event.start))+'<br>Fin: '+esc(x.event.end?date(x.event.end):'Abierto')+'</div></details>';
        if(i===9&&x.kind==='email'&&x.audit.recipients.length)content='<details><summary>'+x.audit.recipients.length+' destinatario(s)</summary><div>'+x.audit.recipients.map(esc).join('<br>')+'</div></details>';
        return '<td'+(i>=6?' class="sigma-history__audit-cell"':'')+'>'+content+'</td>';
      }).join('')+'</tr>';
    }).join('');
  }
  function drawChart(){
    if(chart){chart.destroy();chart=null;}
    empty.classList.toggle('is-visible',records.length===0);if(!records.length)return;
    var bounds=range(),points=sample(items,1000);
    function metricPoint(x,key){return {x:stamp(x.capture_iso||x.capture),y:x[key]};}
    var datasets=[
      {label:'3Sigma',data:points.map(function(x){return metricPoint(x,'sigma_numeric');}),borderColor:'#2f80a3',backgroundColor:'rgba(47,128,163,.12)',borderWidth:2,pointRadius:points.length>150?1:3,tension:.18,spanGaps:true,yAxisID:'y'},
      {label:'CONT_EXCESOS',data:points.map(function(x){return metricPoint(x,'excess_numeric');}),borderColor:'#e9660a',backgroundColor:'rgba(233,102,10,.10)',borderWidth:2,pointRadius:points.length>150?1:3,tension:.18,spanGaps:true,yAxisID:'y1'}
    ];
    function markers(label,filter,color,shape,y){
      datasets.push({type:'scatter',label:label,data:records.filter(filter).filter(function(x){return isFinite(stamp(x.time));}).map(function(x){return {x:Math.max(bounds[0],stamp(x.time)),y:y,record:x};}),yAxisID:'audit',pointRadius:7,pointHoverRadius:9,pointStyle:shape,backgroundColor:color,borderColor:color,showLine:false,order:-1});
    }
    markers('Alerta real AF',function(x){return x.kind==='alert';},'#7c3aed','triangle',1);
    markers('Email enviado',function(x){return x.kind==='email'&&x.email_status==='Enviado';},'#16824b','rectRot',2);
    markers('Email con error',function(x){return x.kind==='email'&&x.email_status==='Error';},'#c42d38','crossRot',3);
    markers('Email pendiente / otro estado',function(x){return x.kind==='email'&&x.email_status!=='Enviado'&&x.email_status!=='Error';},'#ac7100','rect',2.5);
    chart=new Chart(document.getElementById('sigmaHistoryChart'),{type:'line',data:{datasets:datasets},options:{responsive:true,maintainAspectRatio:false,animation:false,interaction:{mode:'nearest',intersect:false},plugins:{legend:{position:'top'},tooltip:{callbacks:{title:function(context){return context.length?date(context[0].parsed.x):'';},label:function(context){
      var row=context.raw.record;
      if(!row)return context.dataset.label+': '+number(context.parsed.y,context.datasetIndex===0?4:0);
      if(row.kind==='alert')return ['Alerta real: '+row.event.name,'Inicio: '+date(row.event.start),row.email_status];
      return [row.email_status+' · '+row.audit.recipients.length+' destinatario(s)','Envío: '+date(row.audit.sent_at),row.detail].concat(row.audit.recipients).concat(row.audit.error?[row.audit.error]:[]);
    }}}},scales:{x:{type:'linear',min:bounds[0],max:bounds[1]-1,ticks:{maxTicksLimit:9,maxRotation:0,callback:function(value){return new Date(value).toLocaleDateString('es-AR',{timeZone:zone,day:'2-digit',month:'2-digit'});}},grid:{display:false}},y:{position:'left',title:{display:true,text:'3Sigma'}},y1:{position:'right',beginAtZero:true,title:{display:true,text:'Excesos'},grid:{drawOnChartArea:false}},audit:{display:false,min:0,max:4}}}});
  }
  function load(){
    if(!well.value||!from.value||!to.value){setStatus('Completá el pozo y las fechas.','error');return;}
    if(from.value>to.value){setStatus('La fecha Desde no puede ser posterior a Hasta.','error');return;}
    var current=++request;if(controller)controller.abort();controller=new AbortController();
    apply.disabled=true;document.getElementById('historyExcel').disabled=true;
    items=[];events=[];records=[];metrics();drawTable();drawChart();
    document.getElementById('historyAuditStatus').textContent='Consultando eventos y auditoría AF…';
    setStatus('Consultando valores SQL, eventos reales y registros de email…','loading');
    fetch('tecss_3sigma_historico_api.php?'+new URLSearchParams({pozo:well.value,desde:from.value,hasta:to.value}).toString(),{signal:controller.signal,cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.json().then(function(data){if(!r.ok||!data.ok)throw new Error(data.error||'No se pudo consultar el histórico.');return data;});})
      .then(function(data){
        if(current!==request)return;
        items=Array.isArray(data.items)?data.items:[];events=Array.isArray(data.events)?data.events:[];
        buildRecords();metrics();drawTable();drawChart();
        document.getElementById('historyRange').textContent=from.value+' a '+to.value;
        var emails=records.filter(function(x){return x.kind==='email';}).length;
        document.getElementById('historyAuditStatus').textContent=data.events_available?events.length+' alertas reales AF · '+emails+' registros de email en el período. Los eventos pueden comenzar antes del rango.':'Eventos y auditoría AF no disponibles.';
        var warnings=Array.isArray(data.warnings)?data.warnings:[];
        setStatus('Consulta completada: '+items.length+' valores SQL. '+warnings.join(' '),warnings.length?'warning':'ok');
      })
      .catch(function(error){if(current!==request||error.name==='AbortError')return;items=[];events=[];records=[];metrics();drawTable();drawChart();document.getElementById('historyAuditStatus').textContent='Consulta no disponible.';setStatus(error.message||'No se pudo consultar el histórico.','error');})
      .finally(function(){if(current===request){apply.disabled=false;document.getElementById('historyExcel').disabled=!records.length;}});
  }
  function exportExcel(){
    if(!records.length){setStatus('No hay registros para exportar.','error');return;}
    var headers=['Captura / evento','HOY','3SIGMA','CONT_EXCESOS','Batería','Método','Evento','Estado email','Fecha de envío','Destinatarios','Error / detalle','ID evento','Nombre evento','Fin evento','ID anotación','Estado PI original','Reintentos'];
    function excelText(v){v=String(v==null?'':v);return esc(/^[\s]*[=+@-]/.test(v)?"'"+v:v);}
    var html='<!doctype html><html><head><meta charset="utf-8"><style>table{border-collapse:collapse}th,td{border:1px solid #9fb2bd;padding:6px}th{background:#174f5e;color:#fff}</style></head><body><h2>Histórico 3Sigma · '+esc(well.value)+'</h2><p>Desde: '+esc(from.value)+' | Hasta: '+esc(to.value)+'</p><p>'+esc(document.getElementById('historyAuditStatus').textContent)+'</p><p>'+esc(status.textContent)+'</p><table><thead><tr>'+headers.map(function(x){return '<th>'+esc(x)+'</th>';}).join('')+'</tr></thead><tbody>';
    records.forEach(function(x){var event=x.event||{},audit=x.audit||{};html+='<tr>'+cells(x).concat([event.id,event.name,event.end?date(event.end):'',audit.annotation_id,audit.status_raw,audit.retry_count]).map(function(v){return '<td>'+excelText(v)+'</td>';}).join('')+'</tr>';});html+='</tbody></table></body></html>';
    var blob=new Blob(['\uFEFF'+html],{type:'application/vnd.ms-excel;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='Historico_3Sigma_'+well.value.replace(/[^A-Za-z0-9_-]+/g,'_')+'_'+from.value+'_'+to.value+'.xls';document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(url);},500);
  }
  form.addEventListener('submit',function(event){event.preventDefault();load();});document.getElementById('historyExcel').addEventListener('click',exportExcel);if(well.value)load();
})();
