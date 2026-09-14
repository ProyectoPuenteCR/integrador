(function(root){
  'use strict';
  function text(v){return v==null?'':String(v).trim();}
  function fold(v){return text(v).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toUpperCase();}
  function battery(v){return fold(v).replace(/[ \-_.\/]/g,'');}
  function same(field,a,b){return field==='BATERIA'?battery(a)===battery(b):fold(a)===fold(b);}
  function zone(v){var key=fold(v);return {'LH ZONA LHCG':'LHCG','ZONA LHCG':'LHCG','CED ZONA I':'CED I','CED ZONA II':'CED II'}[key]||text(v);}
  function unique(rows,field){var values=new Map();rows.forEach(function(r){if(text(r[field]))values.set(fold(r[field]),text(r[field]));});return values.size===1?Array.from(values.values())[0]:'';}
  function latest(history,well,bat){
    var rows=history.filter(function(r){return same('POZO',r.POZO,well)&&same('BATERIA',r.BATERIA,bat);}).sort(function(a,b){return text(b.SEMANA_DESDE).localeCompare(text(a.SEMANA_DESDE));});
    return rows.length?rows[0]:null;
  }
  function model(catalog,history){
    var wells=(catalog.wells||[]).map(function(r){return Object.assign({},r,{ZONA:zone(r.ZONA)});});
    var current=new Set(wells.map(function(r){return fold(r.POZO);})),historic=new Map();
    (history||[]).forEach(function(r){if(current.has(fold(r.POZO)))return;var old=historic.get(fold(r.POZO));if(!old||text(r.SEMANA_DESDE)>text(old.SEMANA_DESDE))historic.set(fold(r.POZO),Object.assign({},r,{ZONA:zone(r.ZONA),FROM_PART:true}));});
    wells=wells.concat(Array.from(historic.values()));
    return {wells:wells,batteries:(catalog.batteries||[]).map(function(r){return Object.assign({},r,{ZONA:zone(r.ZONA)});}),history:history||[]};
  }
  function context(row,values,fields){return fields.every(function(f){return !text(values[f])||same(f,row[f],values[f]);});}
  function options(data,field,values){
    var pool=data.batteries.concat(data.wells),fields=['ZONA'],result=[];
    if(field!=='SUPERVISOR')fields.push('SUPERVISOR');
    if(field!=='BATERIA'&&field!=='SUPERVISOR')fields.push('BATERIA');
    if(field==='TAG'||field==='JEFE_PRODUCCION')fields.push('POZO');
    if(field==='POZO'||field==='TAG')pool=data.wells;
    if(field==='JEFE_PRODUCCION')pool=pool.concat(data.history);
    pool.filter(function(row){return context(row,values,fields);}).forEach(function(row){
      var value=text(row[field]);
      if(!value&&field==='TAG'){var last=latest(data.history,row.POZO,row.BATERIA);value=last?text(last.TAG):'';}
      if(value)result.push({value:value,row:row,label:value+(field==='POZO'?' · '+row.BATERIA+(row.FROM_PART?' · último parte':''):'')});
    });
    var used=new Set();return result.filter(function(o){var key=field==='BATERIA'?battery(o.value):fold(o.value);if(field==='POZO')key+='|'+battery(o.row.BATERIA);if(used.has(key))return false;used.add(key);return true;}).sort(function(a,b){return a.label.localeCompare(b.label,'es',{numeric:true});});
  }
  function suggestions(data,row){
    var last=latest(data.history,row.POZO,row.BATERIA),out=Object.assign({},row);
    out.TAG_SOURCE=row.FROM_PART?'Último parte guardado; revisá el TAG.':(row.TAG?'TAG de la caché local.':'');
    if(!out.TAG&&last){out.TAG=last.TAG;out.TAG_SOURCE='TAG del último parte guardado; revisalo.';}
    out.CHIEF_SOURCE=out.JEFE_PRODUCCION?(row.FROM_PART?'Jefe del último parte guardado.':'Jefe de producción del catálogo.') : '';
    if(!out.JEFE_PRODUCCION&&last&&same('SUPERVISOR',last.SUPERVISOR,row.SUPERVISOR)&&same('ZONA',last.ZONA,row.ZONA)){
      out.JEFE_PRODUCCION=last.JEFE_PRODUCCION;out.CHIEF_SOURCE='Jefe de producción del último parte guardado; revisalo.';
    }
    return out;
  }
  var cachedCatalog=null,catalogPromise=null;
  function loadCatalog(force){
    if(catalogPromise)return catalogPromise;
    if(cachedCatalog&&!force)return Promise.resolve({ok:true,catalog:cachedCatalog});
    var controller=typeof root.AbortController==='function'?new root.AbortController():null;
    var timer=controller?root.setTimeout(function(){controller.abort();},15000):null;
    catalogPromise=root.fetch('pumpoff_catalogo_api.php',{credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'},signal:controller?controller.signal:undefined})
      .then(function(r){return r.json();}).then(function(result){
        if(!result.ok||!result.catalog||!Array.isArray(result.catalog.wells)||!Array.isArray(result.catalog.batteries))throw new Error(result.error||'Respuesta de catálogo no válida.');
        cachedCatalog=result.catalog;
        if(root.dispatchEvent)root.dispatchEvent(new root.CustomEvent('pf:catalog-ready',{detail:cachedCatalog}));
        return result;
      }).then(function(result){if(timer)root.clearTimeout(timer);catalogPromise=null;return result;},function(error){if(timer)root.clearTimeout(timer);catalogPromise=null;throw error;});
    return catalogPromise;
  }
  function create(form,cfg){
    var doc=form.ownerDocument,fields=['SUPERVISOR','JEFE_PRODUCCION','BATERIA','POZO','TAG'],data=model({},cfg.history),loaded=false,pending=null,openField='',active=-1,shown=[],chief='';
    var status=doc.getElementById('pfCatalogStatus'),reload=doc.getElementById('pfCatalogReload'),useChief=doc.getElementById('pfUseZoneChief');
    root.addEventListener('pf:catalog-ready',function(event){
      data=model(event.detail,cfg.history);loaded=true;
      status.textContent=event.detail.wells.length+' pozos BM y '+event.detail.batteries.length+' baterías. Zona → supervisor → batería → pozo. '+(event.detail.warnings||[]).join(' ');
      if(openField)show(openField,false);
    });
    function input(f){return form.elements.namedItem(f);}
    function box(f){return form.querySelector('[data-pf-lookup="'+f+'"]');}
    function values(){var out={};['ZONA'].concat(fields).forEach(function(f){out[f]=input(f).value;});return out;}
    function source(f,message){box(f).querySelector('[data-pf-field-source]').textContent=message||'';}
    function set(f,value){input(f).value=text(value);if(fields.includes(f))source(f,'');}
    function changed(){form.dispatchEvent(new root.Event('pf:catalog-changed',{bubbles:true}));}
    function close(){fields.forEach(function(f){var c=box(f);c.querySelector('[role="listbox"]').hidden=true;input(f).setAttribute('aria-expanded','false');input(f).removeAttribute('aria-activedescendant');});openField='';active=-1;}
    function show(f,all){
      close();openField=f;var list=box(f).querySelector('[role="listbox"]');list.textContent='';
      var candidates=options(data,f,values()),needle=all?'':fold(input(f).value);
      shown=candidates.filter(function(o){return !needle||fold(o.value).includes(needle);}).slice(0,100);
      if(!shown.length){var empty=doc.createElement('div');empty.className='pfLookupEmpty';empty.textContent=pending?'Cargando listas…':'Sin coincidencias para los filtros elegidos. Podés escribir un valor manual.';list.appendChild(empty);}
      shown.forEach(function(option,i){var button=doc.createElement('button');button.type='button';button.id=list.id+'_'+i;button.setAttribute('role','option');button.setAttribute('aria-selected','false');button.textContent=option.label;button.addEventListener('mousedown',function(e){e.preventDefault();});button.addEventListener('click',function(){choose(f,option);});list.appendChild(button);});
      if(shown.length===100){var more=doc.createElement('small');more.className='pfLookupEmpty';more.textContent='Escribí para acotar las opciones.';list.appendChild(more);}
      list.hidden=false;input(f).setAttribute('aria-expanded','true');
    }
    function chiefHint(rows){chief=unique(rows,'JEFE_ZONA');useChief.hidden=!chief||!!text(input('JEFE_PRODUCCION').value);useChief.textContent=chief?'Usar '+chief+' (jefe de zona)':'';}
    function clearAfter(f){
      var clear={ZONA:['SUPERVISOR','BATERIA','POZO','TAG','JEFE_PRODUCCION'],SUPERVISOR:['BATERIA','POZO','TAG','JEFE_PRODUCCION'],BATERIA:['POZO','TAG','JEFE_PRODUCCION'],POZO:['TAG','JEFE_PRODUCCION']}[f]||[];
      clear.forEach(function(k){set(k,'');});if(clear.length){chief='';useChief.hidden=true;}
    }
    function choose(f,option,focus){
      clearAfter(f);set(f,option.value);
      if(f==='SUPERVISOR'){var supRows=data.batteries.concat(data.wells).filter(function(r){return same(f,r[f],option.value);});var z=unique(supRows,'ZONA');if(z)set('ZONA',z);}
      if(f==='BATERIA'){
        var rows=data.batteries.concat(data.wells).filter(function(r){return same('BATERIA',r.BATERIA,option.value)&&context(r,values(),['ZONA','SUPERVISOR']);});
        ['ZONA','SUPERVISOR','JEFE_PRODUCCION'].forEach(function(k){var value=unique(rows,k);if(value)set(k,value);});
        source('BATERIA','Batería del catálogo local.');chiefHint(rows);
      }
      if(f==='POZO'){
        var row=suggestions(data,option.row);
        ['ZONA','SUPERVISOR','BATERIA','TAG','JEFE_PRODUCCION'].forEach(function(k){set(k,row[k]);});
        source('POZO',row.FROM_PART?'Pozo del último parte guardado; revisar asignación.':'Pozo BM de la caché local.');
        source('SUPERVISOR',row.SUPERVISOR?'Asignación por batería.':'Sin supervisor asignado en el maestro.');
        source('TAG',row.TAG_SOURCE||'Sin TAG en las fuentes locales. Es opcional; podés dejarlo vacío.');
        source('JEFE_PRODUCCION',row.CHIEF_SOURCE||'Sin jefe de producción confirmado.');chiefHint([row]);
      }
      close();changed();if(focus!==false)input(f).focus();close();
    }
    function load(force){
      if(pending)return pending;if(loaded&&!force)return Promise.resolve();
      status.textContent='Cargando pozos BM y asignaciones locales…';reload.disabled=true;
      pending=loadCatalog(force).then(function(result){
          if(!result.ok||!result.catalog||!Array.isArray(result.catalog.wells)||!Array.isArray(result.catalog.batteries))throw new Error(result.error||'Respuesta de catálogo no válida.');
          data=model(result.catalog,cfg.history);loaded=true;
          status.textContent=result.catalog.wells.length+' pozos BM y '+result.catalog.batteries.length+' baterías. Zona → supervisor → batería → pozo. '+(result.catalog.warnings||[]).join(' ');
        }).catch(function(){loaded=false;status.textContent='No se pudieron cargar las listas locales. Usá Actualizar listas para reintentar o completá manualmente.';})
        .then(function(){pending=null;reload.disabled=false;if(openField)show(openField,false);});
      return pending;
    }
    fields.forEach(function(f){
      var control=input(f),container=box(f);
      control.addEventListener('focus',function(){show(f,false);});
      control.addEventListener('input',function(){clearAfter(f);show(f,false);source(f,'');});
      control.addEventListener('change',function(){var found=options(data,f,values()).filter(function(o){return same(f,o.value,control.value);});if(found.length===1)choose(f,found[0],false);});
      control.addEventListener('keydown',function(e){
        if(e.key==='Escape'){close();return;}
        if(e.key==='ArrowDown'||e.key==='ArrowUp'){
          e.preventDefault();if(openField!==f)show(f,true);if(!shown.length)return;
          active=Math.max(0,Math.min(shown.length-1,active+(e.key==='ArrowDown'?1:-1)));
          var items=container.querySelectorAll('[role="option"]');items.forEach(function(item,i){item.setAttribute('aria-selected',String(i===active));});control.setAttribute('aria-activedescendant',items[active].id);items[active].scrollIntoView({block:'nearest'});
        }else if(e.key==='Enter'&&openField===f&&active>=0){e.preventDefault();choose(f,shown[active]);}
      });
      container.querySelector('[data-pf-lookup-toggle]').addEventListener('click',function(){var was=openField===f;control.focus();if(was)close();else show(f,true);});
      container.addEventListener('focusout',function(e){if(!container.contains(e.relatedTarget))close();});
    });
    input('ZONA').addEventListener('change',function(){clearAfter('ZONA');close();changed();});
    useChief.addEventListener('click',function(){if(!chief)return;set('JEFE_PRODUCCION',chief);source('JEFE_PRODUCCION','Jefe de zona aceptado por el usuario como jefe de producción.');useChief.hidden=true;changed();});
    reload.addEventListener('click',function(){load(true);});
    doc.addEventListener('click',function(e){if(!e.target.closest('[data-pf-lookup]'))close();});
    return {open:function(){close();fields.forEach(function(f){source(f,'');});chief='';useChief.hidden=true;return load(false);},close:close};
  }
  var api={create:create,load:loadCatalog,model:model,options:options,suggestions:suggestions,fold:fold};
  if(typeof module!=='undefined'&&module.exports)module.exports=api;
  root.CLEAR_PF_LOOKUP=api;
})(typeof window!=='undefined'?window:globalThis);
