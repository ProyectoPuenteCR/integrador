(function(root){
  'use strict';
  if(!root.document||!root.CLEAR_PUMPOFF)return;
  var doc=root.document,cfg=root.CLEAR_PUMPOFF,lookup=root.CLEAR_PF_LOOKUP;
  function text(v){return v==null?'':String(v).trim();}
  function fold(v){return text(v).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toUpperCase();}
  function decode(control){if(control.tagName!=='SELECT')return control.value;try{return JSON.parse(control.value);}catch(e){return control.value;}}
  function filters(controls){return controls.filter(function(c){return c.value!=='';}).map(function(c){return {field:c.dataset.pfFilter||c.dataset.rpSourceFilter,value:decode(c),exact:c.tagName==='SELECT'};});}
  function matches(row,selected){return selected.every(function(f){return f.exact?fold(row[f.field])===fold(f.value):fold(row[f.field]).includes(fold(f.value));});}
  function populate(control,rows,field){var before=control.value,used=new Set();control.textContent='';var all=doc.createElement('option');all.value='';all.textContent='Todos';control.appendChild(all);rows.map(function(r){return text(r[field]);}).sort(function(a,b){return a.localeCompare(b,'es',{numeric:true});}).forEach(function(value){if(used.has(value))return;used.add(value);var option=doc.createElement('option');option.value=JSON.stringify(value);option.textContent=value==='AUTOMATICO'?'AUTOMÁTICO':(value||'Sin dato');control.appendChild(option);});if(Array.from(control.options).some(function(o){return o.value===before;}))control.value=before;}
  function announce(s){doc.getElementById('pfStatus').textContent=s;}
  function csvCell(v){var s=text(v);if(/^[=+@\-\t\r\n]/.test(s))s="'"+s;return '"'+s.replace(/"/g,'""')+'"';}
  var top=doc.getElementById('pfWellsTable'),rows=cfg.rows||[],topControls=Array.from(doc.querySelectorAll('[data-pf-filter]')),visible=[];
  var topNodes=new Map(Array.from(top.querySelectorAll('[data-pf-row]')).map(function(n){return [Number(n.dataset.pfRow),n];})),master=doc.getElementById('pfSelectAll');
  function topChecks(){return visible.map(function(i){return topNodes.get(i).querySelector('[data-ns-report-add]');}).filter(Boolean);}
  function topMaster(){if(!master)return;var checks=topChecks(),n=checks.filter(function(c){return c.checked;}).length;master.checked=!!n&&n===checks.length;master.indeterminate=n>0&&n<checks.length;}
  function filterTop(){var selected=filters(topControls);visible=[];rows.forEach(function(row,i){var show=matches(row,selected);topNodes.get(i).hidden=!show;if(show)visible.push(i);});doc.getElementById('pfEmpty').hidden=!!visible.length;doc.getElementById('pfRowCount').textContent=visible.length+' de '+rows.length+' registros guardados en la semana.';doc.getElementById('pfFilterSummary').textContent=selected.map(function(f){return cfg.columns[f.field]+': '+(f.value||'Sin dato');}).join(' · ')||'Sin filtros';topMaster();}
  topControls.forEach(function(c){if(c.tagName==='SELECT')populate(c,rows,c.dataset.pfFilter);var initial=new URLSearchParams(root.location.search).get('f_'+c.dataset.pfFilter);if(initial!==null)c.value=c.tagName==='SELECT'?JSON.stringify(initial):initial;c.addEventListener('input',filterTop);c.addEventListener('change',filterTop);});
  if(master)master.addEventListener('change',function(){topChecks().forEach(function(c){if(!c.disabled)c.checked=master.checked;});topMaster();});top.addEventListener('change',topMaster);root.addEventListener('clear-report-selection-loaded',topMaster);
  doc.getElementById('pfReset').addEventListener('click',function(){topControls.forEach(function(c){c.value='';});filterTop();});
  doc.querySelectorAll('[data-pf-sort]').forEach(function(button){var asc=false;button.addEventListener('click',function(){asc=!asc;var field=button.dataset.pfSort;Array.from(topNodes.keys()).sort(function(a,b){return text(rows[a][field]).localeCompare(text(rows[b][field]),'es',{numeric:true})*(asc?1:-1);}).forEach(function(i){top.tBodies[0].appendChild(topNodes.get(i));});top.tBodies[0].appendChild(doc.getElementById('pfEmpty'));});});
  var add=doc.getElementById('pfAddSelected');if(add)add.addEventListener('click',function(){var inputs=topChecks().filter(function(c){return c.checked&&!c.disabled;});if(!inputs.length){announce('Seleccioná registros de la grilla superior.');return;}var items=inputs.map(function(c){return {key:c.dataset.reportKey,type:'row',title:c.dataset.reportTitle,payload:JSON.parse(decodeURIComponent(escape(root.atob(c.dataset.reportPayload))))};});add.disabled=true;root.CLEAR_NS_REPORT.addItems(items).then(function(r){announce(r.message);}).catch(function(e){announce(e.message);}).then(function(){add.disabled=false;});});
  doc.getElementById('pfExport').addEventListener('click',function(){var fields=Array.from(top.tHead.rows[0].cells).filter(function(c){return c.style.display!=='none';}).map(function(c){return c.dataset.columnKey==='report'?'SEMANA':c.dataset.columnKey;});var lines=[['Reporte de Pozos',cfg.label],fields.map(function(f){return cfg.columns[f];})];top.tBodies[0].querySelectorAll('[data-pf-row]').forEach(function(n){if(!n.hidden)lines.push(fields.map(function(f){return rows[Number(n.dataset.pfRow)][f];}));});var url=root.URL.createObjectURL(new root.Blob(['\uFEFF'+lines.map(function(line){return line.map(csvCell).join(';');}).join('\r\n')],{type:'text/csv;charset=utf-8'}));var a=doc.createElement('a');a.href=url;a.download='CLEAR_REPORTE_POZOS.csv';a.click();root.setTimeout(function(){root.URL.revokeObjectURL(url);},1000);});
  filterTop();

  var source=doc.getElementById('rpSourceTable'),sourceControls=Array.from(doc.querySelectorAll('[data-rp-source-filter]')),sourceRows=[],sourceVisible=[],sourceSelected=new Set(),sourceData=null;
  var sourceStatus=doc.getElementById('rpSourceStatus'),sourceMaster=doc.getElementById('rpSourceSelectAll'),sourceSort='POZO',sourceAsc=true;
  var pass=doc.getElementById('rpPassSelected'),draftPanel=doc.getElementById('rpDraftPanel'),draftForm=doc.getElementById('rpDraftForm'),drafts=new Map(),batchSaving=false;
  function identity(r){return fold(r.POZO)+'|'+fold(r.BATERIA).replace(/[ \-_.\/]/g,'');}
  function sourceSummary(){var n=sourceVisible.filter(function(r){return sourceSelected.has(identity(r));}).length;sourceMaster.checked=!!sourceVisible.length&&n===sourceVisible.length;sourceMaster.indeterminate=n>0&&n<sourceVisible.length;doc.getElementById('rpSourceSelected').textContent=sourceSelected.size+' elegidos';if(pass)pass.disabled=!cfg.configured||!sourceSelected.size;}
  function renderSource(){
    var selected=filters(sourceControls);sourceVisible=sourceRows.filter(function(r){return matches(r,selected);}).sort(function(a,b){return text(a[sourceSort]).localeCompare(text(b[sourceSort]),'es',{numeric:true})*(sourceAsc?1:-1);});
    var body=source.tBodies[0];body.textContent='';var headers=Array.from(source.tHead.rows[0].cells);
    sourceVisible.forEach(function(row){var tr=doc.createElement('tr');tr.dataset.rpSourceRow=identity(row);headers.forEach(function(header){var td=doc.createElement('td'),key=header.dataset.columnKey;td.dataset.columnKey=key;td.style.display=header.style.display;if(key==='report'){var check=doc.createElement('input');check.type='checkbox';check.checked=sourceSelected.has(identity(row));check.disabled=!cfg.canCreate;check.setAttribute('aria-label','Seleccionar '+row.POZO);check.addEventListener('change',function(){if(check.checked)sourceSelected.add(identity(row));else sourceSelected.delete(identity(row));sourceSummary();});td.appendChild(check);}else td.textContent=text(row[key])||'Sin dato';tr.appendChild(td);});body.appendChild(tr);});
    if(!sourceVisible.length){var tr=doc.createElement('tr'),td=doc.createElement('td');td.colSpan=7;td.textContent='No hay pozos para estos filtros. Podés usar Agregar parte para una carga manual.';tr.appendChild(td);body.appendChild(tr);}
    if(sourceData)sourceStatus.textContent=sourceVisible.length+' de '+sourceRows.length+' pozos BM disponibles. '+(sourceData.warnings||[]).join(' ');
    sourceSummary();
  }
  function receiveCatalog(catalog){sourceData=catalog;sourceRows=catalog.wells||[];var available=new Set(sourceRows.map(identity));sourceSelected.forEach(function(key){if(!available.has(key))sourceSelected.delete(key);});sourceControls.forEach(function(c){if(c.tagName==='SELECT')populate(c,sourceRows,c.dataset.rpSourceFilter);});renderSource();}
  sourceControls.forEach(function(c){c.addEventListener('input',renderSource);c.addEventListener('change',renderSource);});
  sourceMaster.addEventListener('change',function(){if(!cfg.canCreate)return;sourceVisible.forEach(function(r){if(sourceMaster.checked)sourceSelected.add(identity(r));else sourceSelected.delete(identity(r));});renderSource();});
  doc.getElementById('rpSourceReset').addEventListener('click',function(){sourceControls.forEach(function(c){c.value='';});renderSource();});
  doc.querySelectorAll('[data-rp-source-sort]').forEach(function(button){button.addEventListener('click',function(){sourceAsc=sourceSort===button.dataset.rpSourceSort?!sourceAsc:true;sourceSort=button.dataset.rpSourceSort;renderSource();});});
  function loadSource(force){sourceStatus.textContent='Cargando la caché local de pozos…';return lookup.load(force).then(function(result){receiveCatalog(result.catalog);}).catch(function(){sourceStatus.textContent='No se pudo cargar la lista local. Usá Actualizar listas para reintentar. La carga manual sigue disponible.';});}
  doc.getElementById('rpSourceReload').addEventListener('click',function(){loadSource(true);});
  root.addEventListener('pf:catalog-ready',function(event){receiveCatalog(event.detail);});
  if(lookup)loadSource(false);else sourceStatus.textContent='Falta el archivo pumpoff_catalogo.js. Copiá todos los archivos del parche.';

  function draftError(message){var e=doc.getElementById('rpDraftError');e.textContent=message||'';e.hidden=!message;}
  function renderDrafts(){
    if(!draftPanel)return;['rpDraftWeek','rpDraftState','rpApplyState','rpClearDraft'].forEach(function(id){doc.getElementById(id).disabled=batchSaving;});draftPanel.hidden=!drafts.size;var body=doc.querySelector('#rpDraftTable tbody');body.textContent='';
    var week=doc.getElementById('rpDraftWeek').value;
    drafts.forEach(function(row,key){var tr=doc.createElement('tr');tr.dataset.rpDraftRow=key;
      ['SEMANA','ZONA','SUPERVISOR','JEFE_PRODUCCION','BATERIA','POZO','TAG','ESTADO','OBSERVACIONES'].forEach(function(field){var td=doc.createElement('td');
        if(field==='SEMANA'){var label=doc.createElement('span');label.textContent=week;td.appendChild(label);var remove=doc.createElement('button');remove.type='button';remove.className='pfEditPart';remove.textContent='Quitar';remove.disabled=batchSaving;remove.addEventListener('click',function(){drafts.delete(key);renderDrafts();});td.appendChild(remove);}
        else{var control;if(field==='ZONA'||field==='ESTADO'){control=doc.createElement('select');[''].concat(field==='ZONA'?cfg.zones:['MANUAL','AUTOMATICO','HOA']).forEach(function(value){var o=doc.createElement('option');o.value=value;o.textContent=value||'Seleccionar…';control.appendChild(o);});}else if(field==='OBSERVACIONES'){control=doc.createElement('textarea');control.rows=3;control.maxLength=2000;}else{control=doc.createElement('input');control.type='text';control.maxLength={SUPERVISOR:200,JEFE_PRODUCCION:200,BATERIA:255,POZO:180,TAG:255}[field];if(field==='POZO'||field==='BATERIA')control.readOnly=true;}
          control.value=text(row[field]);control.required=field!=='TAG'&&field!=='OBSERVACIONES';control.disabled=batchSaving;control.dataset.rpDraftField=field;control.setAttribute('aria-label',field+' de '+row.POZO);control.addEventListener('input',function(){row[field]=control.value;});control.addEventListener('change',function(){row[field]=control.value;});td.appendChild(control);
          if(field==='JEFE_PRODUCCION'&&!row[field]&&row.JEFE_ZONA){var use=doc.createElement('button');use.type='button';use.className='pfUseChief';use.textContent='Usar '+row.JEFE_ZONA+' (jefe de zona)';use.disabled=batchSaving;use.addEventListener('click',function(){row.JEFE_PRODUCCION=row.JEFE_ZONA;control.value=row.JEFE_ZONA;use.remove();});td.appendChild(use);}
          if(field==='TAG'&&row.TAG_SOURCE){var hint=doc.createElement('small');hint.textContent=row.TAG_SOURCE;td.appendChild(hint);}
        }tr.appendChild(td);
      });body.appendChild(tr);
    });doc.getElementById('rpDraftCount').textContent=drafts.size+' pozos pendientes de guardar';
  }
  if(pass)pass.addEventListener('click',function(){
    if(batchSaving)return;var selected=sourceRows.filter(function(r){return sourceSelected.has(identity(r));});if(!selected.length){announce('Seleccioná pozos en la grilla inferior.');return;}
    var keys=new Set(drafts.keys()),chosen=new Map();for(var row of selected){var key=fold(row.POZO);if(chosen.has(key)&&identity(chosen.get(key))!==identity(row)){announce('El pozo '+row.POZO+' tiene más de una batería elegida. Seleccioná una sola asignación.');return;}chosen.set(key,row);keys.add(key);}
    if(keys.size>100){announce('Seleccioná hasta 100 pozos por lote. No se descartó ninguna selección.');return;}
    var data=lookup.model(sourceData||{},cfg.history||[]);chosen.forEach(function(row,key){if(!drafts.has(key))drafts.set(key,Object.assign(lookup.suggestions(data,row),{ID:0,VERSION:0,ESTADO:''}));});
    renderDrafts();draftError('');draftPanel.scrollIntoView({behavior:'smooth',block:'start'});announce(selected.length+' pozos pasados al borrador de arriba. Revisá los datos y guardá el lote.');
  });
  if(draftForm){
    doc.getElementById('rpDraftWeek').addEventListener('change',renderDrafts);
    doc.getElementById('rpApplyState').addEventListener('click',function(){var state=doc.getElementById('rpDraftState').value;if(!state){draftError('Elegí el estado a aplicar al lote.');return;}drafts.forEach(function(row){row.ESTADO=state;});renderDrafts();draftError('');});
    doc.getElementById('rpClearDraft').addEventListener('click',function(){if(batchSaving)return;if(root.confirm('¿Descartar el lote sin guardar?')){drafts.clear();renderDrafts();}});
    root.addEventListener('beforeunload',function(event){if(drafts.size&&!batchSaving){event.preventDefault();event.returnValue='';}});
    draftForm.addEventListener('submit',function(event){
      event.preventDefault();if(batchSaving||!drafts.size||!draftForm.reportValidity())return;
      if(!root.confirm('Se agregarán '+drafts.size+' pozos al parte de la semana. No se reemplazan registros existentes. ¿Guardar?'))return;
      var payload={token:cfg.partToken,rows:Array.from(drafts.values()).map(function(row){var out={ID:0,VERSION:0,SEMANA_DESDE:doc.getElementById('rpDraftWeek').value};['ZONA','SUPERVISOR','JEFE_PRODUCCION','BATERIA','POZO','TAG','ESTADO','OBSERVACIONES'].forEach(function(f){out[f]=text(row[f]);});return out;})};
      batchSaving=true;doc.getElementById('rpSaveBatch').disabled=true;renderDrafts();draftError('');
      root.fetch('reporte_pozos_lote_api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(payload)}).then(function(r){return r.json();}).then(function(r){if(!r.ok||!r.result||!/^\d{4}-\d{2}-\d{2}$/.test(r.result.week))throw new Error(r.error||'No se pudo guardar el lote.');drafts.clear();var url=new URL(root.location.href);Array.from(url.searchParams.keys()).filter(function(k){return k.indexOf('f_')===0;}).forEach(function(k){url.searchParams.delete(k);});url.searchParams.set('lectura',r.result.week);url.searchParams.set('guardado','1');root.location.assign(url.toString());}).catch(function(e){batchSaving=false;doc.getElementById('rpSaveBatch').disabled=false;renderDrafts();draftError(e.message);});
    });
  }
})(typeof window!=='undefined'?window:globalThis);
