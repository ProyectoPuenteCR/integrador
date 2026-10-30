(function(){
  'use strict';
  const cfg=window.CLEAR_TELEMETRY_GRID||{};
  if(!['monitoreo_pozos','telemetria_pcp'].includes(cfg.key))return;
  const bell='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>';
  let active=null;
  function node(tag,className,text){const el=document.createElement(tag);el.className=className||'';if(text!==undefined)el.textContent=text;return el;}
  async function request(url,options={}){
    const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'},...options});
    let data;try{data=await response.json();}catch(error){throw new Error('No se pudo leer la respuesta. Verificá la sesión de CLEAR.');}
    if(!response.ok||!data.ok)throw new Error(data.error||'No se pudo completar la consulta.');return data;
  }
  function detach(){if(active){active.controller.abort();active=null;}}
  function decorate({row,body,activityHost,well,close}){
    detach();
    const state={controller:new AbortController()};active=state;
    const current=()=>active===state;
    const panel=node('section','wd-activity');panel.setAttribute('aria-label','Comentario y alarmas del pozo');if(activityHost)activityHost.replaceChildren(panel);else body.prepend(panel);
    const comment=node('article','wd-activity-card');
    const heading=node('div','wd-activity-heading');heading.append(node('h3','','Comentario del pozo'));comment.append(heading);
    const preview=node('p','wd-comment-preview');
    const cell=row.querySelector('[data-column="COMENTARIO"]');
    const trigger=row.querySelector('[data-alarm-comment="true"]');
    const subject=trigger?.dataset.subject||cell?.querySelector('[data-comment-subject]')?.dataset.commentSubject;
    const canRead=cfg.wellCommentView===true;
    const canEdit=trigger?.dataset.canCreate==='1';
    let saved=canRead?(cell?.textContent.trim()||'Sin comentario'):'Sin permiso para ver comentarios';
    preview.textContent=saved;comment.append(preview);
    const status=node('p','wd-activity-status');status.setAttribute('role','status');comment.append(status);panel.append(comment);
    let latestComment='',latestAuthor='',loaded=false,editing=false;
    function updateGrid(text){
      if(canRead&&cell){const span=cell.querySelector('[data-comment-preview]');if(span){span.textContent=text||'Sin comentario';span.title=text;}cell.dataset.raw=text;
        const detail=body.querySelector('[data-history-column="COMENTARIO"] dd');if(detail)detail.textContent=text||'Sin comentario';}
      if(trigger)trigger.classList.toggle('has-comment',!!text);
      if(canRead)window.dispatchEvent(new CustomEvent('clear-alarm-comment-updated',{detail:{subject,comment:text}}));
    }
    if(subject&&(canRead||canEdit)){
      const edit=node('button','tg-btn','Editar comentario');edit.type='button';edit.disabled=true;if(canEdit)heading.append(edit);
      const form=node('form','wd-comment-form');form.hidden=true;
      const textLabel=node('label','','Comentario');const text=node('textarea');text.maxLength=2000;text.required=true;text.rows=3;textLabel.append(text);
      const authorLabel=node('label','','Nombre y apellido');const author=node('input');author.type='text';author.required=true;author.minLength=3;author.maxLength=120;author.autocomplete='name';authorLabel.append(author);
      const buttons=node('div','wd-comment-buttons');const save=node('button','tg-btn','Guardar comentario');save.type='submit';const cancel=node('button','tg-btn','Cancelar');cancel.type='button';buttons.append(save,cancel);form.append(textLabel,authorLabel,buttons);comment.append(form);
      edit.onclick=()=>{if(!loaded)return;editing=true;text.value=latestComment;author.value=latestAuthor;form.hidden=false;preview.hidden=true;edit.hidden=true;status.textContent='';text.focus();};
      cancel.onclick=()=>{editing=false;form.hidden=true;preview.hidden=false;edit.hidden=false;status.textContent='';};
      if(canRead)request('alarm_events_api.php?'+new URLSearchParams({action:'get_comment',subject}),{signal:state.controller.signal}).then(data=>{
        if(!current())return;loaded=true;latestComment=data.comment||'';latestAuthor=data.author||'';saved=latestComment||'Sin comentario';
        if(canRead){preview.textContent=saved;updateGrid(latestComment);}edit.disabled=false;
      }).catch(error=>{if(current()&&error.name!=='AbortError'){status.textContent=error.message;status.classList.add('is-error');}});
      else {loaded=true;edit.disabled=false;edit.textContent='Agregar comentario';}
      form.onsubmit=async event=>{
        event.preventDefault();if(save.disabled||!editing)return;
        const value=text.value.trim(),name=author.value.trim().replace(/\s+/g,' ');
        if(!value||name.length<3){status.textContent='Completá el comentario y tu nombre y apellido.';return;}
        const data=new FormData();
        Object.entries({action:'save_comment',event_key:trigger.dataset.eventKey,subject,context:trigger.dataset.context||cfg.key,comment:value,author:name}).forEach(([key,value])=>data.append(key,value));
        save.disabled=true;cancel.disabled=true;text.disabled=true;author.disabled=true;status.textContent='Guardando comentario…';status.classList.remove('is-error');
        try{
          // Un guardado iniciado por el usuario termina aunque cierre la ficha.
          await request('alarm_events_api.php',{method:'POST',body:data});
          if(!current())return;
          latestComment=value;latestAuthor=name;saved=value;preview.textContent=canRead?value:'Comentario guardado. Sin permiso para ver el contenido.';updateGrid(value);
          editing=false;form.hidden=true;preview.hidden=false;edit.hidden=false;status.textContent='Comentario guardado.';
        }catch(error){if(current()){status.textContent=error.message;status.classList.add('is-error');}}
        finally{save.disabled=false;cancel.disabled=false;text.disabled=false;author.disabled=false;}
      };
    }
    const alarms=node('article','wd-activity-card');const alarmHead=node('div','wd-activity-heading');alarmHead.append(node('h3','','Última alarma'));alarms.append(alarmHead);
    const last=node('div','wd-last-alarm');last.setAttribute('aria-live','polite');alarms.append(last);panel.append(alarms);
    if(!cfg.wellAlarms){last.textContent='Sin permiso para consultar el historial de alarmas.';return;}
    const link=node('a','tg-btn wd-alarm-bell');link.innerHTML=bell;
    const count=node('strong','','…');link.append(count,node('span','','Alarmas · 24 h'));
    const url=(cfg.wellAlarmsHistory?'pozos_todas_alarmas.php':'pozos_alarmas24.php')+'?'+new URLSearchParams({pozo:well,pozo_exacto:'1',prefijo_pozo:''});
    link.href=url;link.dataset.telemetryModalUrl=url+'&embed=1';link.dataset.telemetryModalOpenUrl=url;link.dataset.telemetryModalTitle='Historial de alarmas · '+well;link.dataset.telemetryModalEyebrow='Alarmas de pozo';link.setAttribute('aria-label','Abrir historial de alarmas de '+well);link.addEventListener('click',close);alarmHead.append(link);
    last.textContent='Consultando última alarma…';
    request('well_activity_api.php?'+new URLSearchParams({well,source:cfg.key}),{signal:state.controller.signal}).then(data=>{
      if(!current())return;
      const validCount=typeof data.count24h==='number'&&Number.isInteger(data.count24h)&&data.count24h>=0;
      count.textContent=validCount?String(data.count24h):'—';link.classList.toggle('has-alarms',validCount&&data.count24h>0);
      link.title=validCount?data.count24h+' alarmas en las últimas 24 horas (caché de telemetría). Abrir historial.':'Contador de alarmas no disponible. Abrir historial.';
      const alarmKpi=body.querySelector('[data-kpi-column="ALM"]');
      if(alarmKpi){alarmKpi.querySelector('strong').textContent=validCount?String(data.count24h):'—';alarmKpi.classList.toggle('is-no-data',!validCount);alarmKpi.title=link.title;}
      const alarmField=body.querySelector('[data-history-column="ALM"]');
      if(alarmField){alarmField.querySelector('dd').textContent=validCount?String(data.count24h):'—';alarmField.classList.toggle('is-no-data',!validCount);}
      const alarmAction=body.closest('dialog')?.querySelector('[data-well-alarm-action]');
      if(alarmAction){const label=validCount?'Ver alarmas ('+data.count24h+')':'Ver alarmas';alarmAction.textContent=label;alarmAction.setAttribute('aria-label',label);alarmAction.title=link.title;}
      last.replaceChildren();if(!data.latest){last.textContent='Sin alarmas registradas para este pozo.';return;}
      const item=data.latest;last.append(node('strong','',item.description||item.tag||'Alarma'));
      const list=node('dl','wd-alarm-fields');
      [['Inicio',item.started_at],['Último evento',item.last_at],['Valor',item.value],['Estado',item.status]].forEach(([label,value])=>{const field=node('div');field.append(node('dt','',label),node('dd','',value||'Sin dato'));list.append(field);});last.append(list);
    }).catch(error=>{if(current()&&error.name!=='AbortError'){count.textContent='—';last.textContent=error.message;last.classList.add('is-error');}});
  }
  window.CLEAR_WELL_ACTIVITY={decorate,detach};
})();
