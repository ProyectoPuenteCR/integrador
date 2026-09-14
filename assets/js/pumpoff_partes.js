(function(root){
  'use strict';
  function weekBounds(value){
    if(!/^\d{4}-\d{2}-\d{2}$/.test(value))return null;
    var date=new Date(value+'T12:00:00Z');
    if(!Number.isFinite(date.getTime())||date.toISOString().slice(0,10)!==value)return null;
    date.setUTCDate(date.getUTCDate()-(date.getUTCDay()+4)%7);
    var from=date.toISOString().slice(0,10);date.setUTCDate(date.getUTCDate()+6);
    return {from:from,to:date.toISOString().slice(0,10)};
  }
  if(typeof module!=='undefined'&&module.exports)module.exports={weekBounds:weekBounds};
  if(!root.document||!root.CLEAR_PUMPOFF)return;
  var doc=root.document,cfg=root.CLEAR_PUMPOFF,form=doc.getElementById('pfPartForm'),panel=doc.getElementById('pfPartPanel');
  if(!form||!panel)return;
  var dirty=false,saving=false,error=doc.getElementById('pfPartError'),submit=doc.getElementById('pfPartSubmit');
  var lookup=root.CLEAR_PF_LOOKUP?root.CLEAR_PF_LOOKUP.create(form,cfg):null;
  function field(key){return form.elements.namedItem(key);}
  var file=field('ADJUNTO'),removeFile=field('QUITAR_ADJUNTO');
  function fileInfo(){var selected=file.files[0],invalid=selected&&(selected.size>5242880||selected.size===0);file.setCustomValidity(invalid?'El archivo debe tener contenido y pesar como máximo 5 MB.':'');doc.getElementById('pfAttachmentInfo').textContent=selected?(selected.name+' · '+(selected.size/1048576).toFixed(2)+' MB'+(invalid?' · NO ADMITIDO':'')):'';doc.getElementById('pfClearFile').hidden=!selected;}
  file.addEventListener('change',function(){if(file.files.length)removeFile.checked=false;fileInfo();});
  removeFile.addEventListener('change',function(){if(removeFile.checked){file.value='';fileInfo();}});
  doc.getElementById('pfClearFile').addEventListener('click',function(){file.value='';fileInfo();dirty=true;});
  function hint(){var bounds=weekBounds(field('SEMANA_DESDE').value);doc.getElementById('pfPartWeekHint').textContent=bounds?'Miércoles '+bounds.from.split('-').reverse().join('/')+' al martes '+bounds.to.split('-').reverse().join('/'):'Elegí una fecha válida.';}
  function close(){if(saving)return;if(dirty&&!root.confirm('¿Descartar los cambios que todavía no guardaste?'))return;panel.hidden=true;dirty=false;if(lookup)lookup.close();var add=doc.getElementById('pfAddPart');if(add)add.focus();}
  function open(row){
    if(saving)return;
    if(dirty&&!root.confirm('¿Descartar los cambios y abrir otro parte?'))return;
    form.reset();field('ID').value='0';field('VERSION').value='0';field('SEMANA_DESDE').value=cfg.selectedWeek;
    if(row)Array.from(form.elements).forEach(function(input){if(input.name&&input.type!=='file'&&input.type!=='checkbox'&&row[input.name]!=null)input.value=row[input.name];});
    var current=doc.getElementById('pfCurrentAttachment'),link=doc.getElementById('pfCurrentAttachmentLink');current.hidden=!(row&&row.ADJUNTO_NOMBRE);link.removeAttribute('href');if(row&&row.ADJUNTO_NOMBRE){link.href='reporte_pozos_adjunto.php?id='+row.ID;link.textContent='Descargar: '+row.ADJUNTO_NOMBRE;}fileInfo();
    doc.getElementById('pfPartTitle').textContent=row?'Editar parte · '+row.POZO:'Agregar parte';
    submit.textContent=row?'Guardar cambios':'Guardar parte';error.hidden=true;error.textContent='';panel.hidden=false;dirty=false;hint();
    panel.scrollIntoView({behavior:'smooth',block:'start'});field(row?'ESTADO':'SEMANA_DESDE').focus();if(lookup)lookup.open();
  }
  var add=doc.getElementById('pfAddPart');if(add)add.addEventListener('click',function(){if(cfg.canCreate)open(null);});
  doc.querySelectorAll('[data-pf-edit-part]').forEach(function(button){button.addEventListener('click',function(){var row=cfg.rows[Number(button.dataset.pfEditPart)];if(row&&row.CAN_EDIT)open(row);});});
  doc.getElementById('pfPartCancel').addEventListener('click',close);
  form.addEventListener('pf:catalog-changed',function(){dirty=true;});
  form.addEventListener('input',function(){dirty=true;});form.addEventListener('change',function(){dirty=true;hint();});
  root.addEventListener('beforeunload',function(event){if(dirty){event.preventDefault();event.returnValue='';}});
  form.addEventListener('submit',function(event){
    event.preventDefault();if(saving||!form.reportValidity())return;
    var payload=new root.FormData(form);payload.set('token',cfg.partToken);payload.set('QUITAR_ADJUNTO',removeFile.checked?'1':'0');if(!file.files.length)payload.delete('ADJUNTO');
    saving=true;if(lookup)lookup.close();var disabledBefore=Array.from(form.elements).map(function(input){return {input:input,disabled:input.disabled};});disabledBefore.forEach(function(item){item.input.disabled=true;});error.hidden=true;submit.textContent='Guardando…';
    root.fetch('pumpoff_partes_api.php',{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'},body:payload})
      .then(function(response){return response.json().catch(function(){throw new Error('El servidor rechazó el envío. Revisá el límite de carga PHP/IIS; máximo 5 MB por archivo.');});}).then(function(value){
        if(!value.ok||!value.result||!weekBounds(value.result.week))throw new Error(value.error||'No se pudo guardar. Tu formulario sigue abierto.');
        dirty=false;var url=new URL(root.location.href),week=value.result.week;
        Array.from(url.searchParams.keys()).filter(function(k){return k.indexOf('f_')===0;}).forEach(function(k){url.searchParams.delete(k);});
        url.searchParams.set('lectura',week);url.searchParams.set('hasta',week);
        var start=new Date(week+'T12:00:00Z');start.setUTCDate(start.getUTCDate()-49);url.searchParams.set('desde',start.toISOString().slice(0,10));url.searchParams.set('guardado','1');
        root.location.assign(url.toString());
      }).catch(function(e){error.textContent=e.message||'No se pudo guardar. Revisá la conexión.';error.hidden=false;saving=false;disabledBefore.forEach(function(item){item.input.disabled=item.disabled;});submit.textContent=field('ID').value==='0'?'Guardar parte':'Guardar cambios';});
  });
})(typeof window!=='undefined'?window:globalThis);
