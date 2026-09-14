(function(root){
 'use strict';
 function unique(values){return Array.from(new Set(values.filter(Boolean))).sort(function(a,b){return a.localeCompare(b,'es');});}
 function pairs(catalog,supervisor,chief,zone){return (catalog.pairs||[]).filter(function(p){return (!zone||p.zona===zone)&&(!supervisor||p.supervisor===supervisor)&&(!chief||p.jefe===chief);});}
 if(typeof module!=='undefined'&&module.exports)module.exports={unique:unique,pairs:pairs};
 if(!root.document)return;
 root.CLEAR_NG_PEOPLE=function(opts){
  var doc=root.document,form=opts.form,cfg=opts.cfg,audit=cfg.type==='AUDITORIA',catalog=null,pending=null,adding=false,state={},keepOld=false;
  function field(name){return form.elements.namedItem(name);}
  function status(message){doc.getElementById('ngCatalogStatus').textContent=message;}
  function values(rows,key){return unique(rows.map(function(r){return r[key];}));}
  function select(control,items,value,old,label){
   control.textContent='';var empty=doc.createElement('option');empty.value='';empty.textContent=label;control.appendChild(empty);
   unique(items.concat(old&&value?[value]:[])).forEach(function(v){var o=doc.createElement('option');o.value=v;o.textContent=v+(items.indexOf(v)<0?' (anterior)':'');control.appendChild(o);});
   control.value=value||'';if(control.selectedIndex<0)control.value='';return control.value;
  }
  function render(){
   var data=catalog||{pairs:[],people:{},batteries:[]};
   if(audit){
    var batteries=data.batteries.filter(function(b){return !state.zone||b.ZONA===state.zone;}),list=doc.getElementById('ngBatteries');list.textContent='';
    values(batteries,'BATERIA').forEach(function(v){var o=doc.createElement('option');o.value=v;list.appendChild(o);});
    var sup=values(pairs(data,'',state.chief,state.zone),'supervisor'),chief=values(pairs(data,state.supervisor,'',state.zone),'jefe');
    if(!state.chief&&state.supervisor&&chief.length===1)state.chief=chief[0];
    state.supervisor=select(field('SUPERVISOR'),sup,state.supervisor,keepOld,'Seleccionar supervisor');
    state.chief=select(field('JEFE_PRODUCCION'),chief,state.chief,keepOld,'Seleccionar jefe');
    return;
   }
   state.person=select(field('RESPONSABLE'),data.people[state.type]||[],state.person,keepOld,'Seleccionar responsable');
   var isSupervisor=state.type==='SUPERVISOR',isChief=state.type==='JEFE_PRODUCCION',chiefControl=field('JEFE_PRODUCCION'),multi=doc.getElementById('ngRelatedSupervisors');
   doc.getElementById('ngRelatedChiefControl').hidden=!isSupervisor;chiefControl.disabled=!isSupervisor;chiefControl.required=isSupervisor;
   var chiefs=values(pairs(data,state.person,'',''),'jefe');if(isSupervisor&&state.person&&!state.chief&&chiefs.length===1)state.chief=chiefs[0];
   state.chief=select(chiefControl,isSupervisor&&state.person?chiefs:[],state.chief,keepOld&&isSupervisor,'Seleccionar jefe');
   doc.getElementById('ngRelatedSupervisorsControl').hidden=!isChief;multi.disabled=!isChief;multi.textContent='';
   var supervisors=isChief&&state.person?values(pairs(data,'',state.person,''),'supervisor'):[];
   unique(supervisors.concat(keepOld&&isChief?state.supervisors:[])).forEach(function(v){var o=doc.createElement('option');o.value=v;o.textContent=v+(supervisors.indexOf(v)<0?' (anterior)':'');o.selected=state.supervisors.indexOf(v)>=0;multi.appendChild(o);});
   state.supervisors=Array.from(multi.selectedOptions).map(function(o){return o.value;});
   var addPanel=doc.getElementById('ngNewResponsibleControl');if(addPanel)addPanel.hidden=state.type!=='GUARDADO';
   var add=doc.getElementById('ngSaveResponsible');if(add)add.disabled=adding||!catalog||!catalog.saved_ready;
  }
  function load(force){
   if(pending)return pending;if(catalog&&!force){render();return Promise.resolve(catalog);}
   status('Cargando responsables y asignaciones locales…');
   pending=opts.request(opts.api('catalogo')).then(function(r){catalog=r.catalog;render();status(catalog.batteries.length+' instalaciones · '+catalog.people.PLATAFORMA.length+' usuarios activos · '+catalog.people.GUARDADO.length+' responsables guardados. '+(catalog.warnings||[]).join(' '));return catalog;}).catch(function(e){status(e.message+' Usá Actualizar listas para reintentar.');}).then(function(result){pending=null;return result;});return pending;
  }
  doc.getElementById('ngReloadCatalog').addEventListener('click',function(){if(!adding)load(true);});
  if(audit){
   field('ZONA').addEventListener('change',function(){keepOld=false;state.zone=this.value;state.chief='';state.supervisor='';field('BATERIA').value='';render();opts.changed();});
   field('BATERIA').addEventListener('change',function(){
    if(!catalog)return;var name=this.value.trim().toLocaleUpperCase('es'),matches=catalog.batteries.filter(function(b){return b.BATERIA.toLocaleUpperCase('es')===name&&(!state.zone||b.ZONA===state.zone);});
    keepOld=false;state.supervisor='';state.chief='';if(matches.length===1){var b=matches[0];state.zone=b.ZONA;field('ZONA').value=b.ZONA;state.supervisor=b.SUPERVISOR;state.chief=b.JEFE_PRODUCCION;}render();opts.changed();
   });
   field('SUPERVISOR').addEventListener('change',function(){keepOld=false;state.supervisor=this.value;if(!pairs(catalog||{},state.supervisor,state.chief,state.zone).length)state.chief='';render();opts.changed();});
   field('JEFE_PRODUCCION').addEventListener('change',function(){keepOld=false;state.chief=this.value;if(!pairs(catalog||{},state.supervisor,state.chief,state.zone).length)state.supervisor='';render();opts.changed();});
  }else{
   field('RESPONSABLE_TIPO').addEventListener('change',function(){keepOld=false;state.type=this.value;state.person='';state.chief='';state.supervisors=[];render();opts.changed();});
   field('RESPONSABLE').addEventListener('change',function(){keepOld=false;state.person=this.value;state.chief='';state.supervisors=[];render();opts.changed();});
   field('JEFE_PRODUCCION').addEventListener('change',function(){state.chief=this.value;keepOld=false;opts.changed();});
   doc.getElementById('ngRelatedSupervisors').addEventListener('change',function(){state.supervisors=Array.from(this.selectedOptions).map(function(o){return o.value;});this.setCustomValidity(state.supervisors.length>30?'Elegí hasta 30 supervisores.':'');keepOld=false;opts.changed();});
   var add=doc.getElementById('ngSaveResponsible'),name=doc.getElementById('ngNewResponsible'),message=doc.getElementById('ngResponsibleStatus');
   if(add){name.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();add.click();}});add.addEventListener('click',function(){
    if(adding||!catalog||!catalog.saved_ready)return;var value=name.value.trim();if(!value){message.textContent='Escribí el nombre del responsable.';name.focus();return;}
    adding=true;var disabled=Array.from(form.elements).map(function(c){return [c,c.disabled];});disabled.forEach(function(item){item[0].disabled=true;});
    message.textContent='Guardando responsable…';var data=new root.FormData();data.set('NOMBRE',value);data.set('token',cfg.token);
    opts.request(opts.api('guardar_responsable'),{method:'POST',body:data}).then(function(r){
     catalog.people.GUARDADO=unique(catalog.people.GUARDADO.concat([r.result.nombre]));state.type='GUARDADO';field('RESPONSABLE_TIPO').value=state.type;state.person=r.result.nombre;state.chief='';state.supervisors=[];keepOld=false;name.value='';message.textContent=r.result.existing?'Ya existía: seleccionado sin duplicarlo.':'Responsable guardado. Ya podés usarlo en esta carga y en las próximas.';opts.changed();
    }).catch(function(e){message.textContent=e.message;}).then(function(){adding=false;disabled.forEach(function(item){item[0].disabled=item[1];});render();});
   });}
  }
  return {busy:function(){return adding;},open:function(row){
   row=row||{};keepOld=!!row.ID;state={type:row.RESPONSABLE_TIPO||'',person:row.RESPONSABLE||'',chief:row.JEFE_PRODUCCION||'',supervisor:row.SUPERVISOR||'',supervisors:(row.SUPERVISORES||[]).slice(),zone:row.ZONA||''};
   if(!audit){var type=field('RESPONSABLE_TIPO');Array.from(type.options).filter(function(o){return o.value==='LEGADO';}).forEach(function(o){o.remove();});if(state.type==='LEGADO'){var o=doc.createElement('option');o.value='LEGADO';o.textContent='Responsable anterior (conservar)';type.appendChild(o);}type.value=state.type;doc.getElementById('ngRelatedSupervisors').setCustomValidity('');}
   render();return load(false);
  }};
 };
})(typeof window!=='undefined'?window:globalThis);
