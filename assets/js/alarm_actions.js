(function(){
  'use strict';
  var sqlModal=document.getElementById('sqlHistoryModal');
  var sqlOverlay=document.getElementById('sqlHistoryModalOverlay');
  var sqlClose=document.getElementById('sqlHistoryModalClose');
  var sqlFrame=document.getElementById('sqlHistoryModalFrame');
  var sqlOpenPage=document.getElementById('sqlHistoryModalOpenPage');
  var sqlActiveButton=null;

  function closeSqlHistory(){
    if(!sqlModal)return;
    sqlModal.classList.remove('is-open');sqlModal.setAttribute('aria-hidden','true');
    if(sqlOverlay)sqlOverlay.hidden=true;
    document.body.classList.remove('has-sql-history-modal');
    window.setTimeout(function(){if(sqlModal&&!sqlModal.classList.contains('is-open')&&sqlFrame)sqlFrame.setAttribute('src','about:blank');},180);
    if(sqlActiveButton)sqlActiveButton.focus();sqlActiveButton=null;
  }
  function openSqlHistory(button){
    if(!sqlModal||!sqlFrame||!sqlOpenPage)return;
    var tag=button.getAttribute('data-sql-tag')||'';
    if(!tag)return;
    var start=button.getAttribute('data-sql-start')||'';
    var end=button.getAttribute('data-sql-end')||'';
    sqlActiveButton=button;
    var embeddedParams=new URLSearchParams({embedded:'1',tag:tag});
    var pageParams=new URLSearchParams({tag:tag});
    if(start){embeddedParams.set('start',start);pageParams.set('start',start);}
    if(end){embeddedParams.set('end',end);pageParams.set('end',end);}
    sqlFrame.setAttribute('src','alarm_sql_historico.php?'+embeddedParams.toString());
    sqlOpenPage.setAttribute('href','alarm_sql_historico.php?'+pageParams.toString());
    if(sqlOverlay)sqlOverlay.hidden=false;
    sqlModal.classList.add('is-open');sqlModal.setAttribute('aria-hidden','false');
    document.body.classList.add('has-sql-history-modal');
    if(sqlClose)sqlClose.focus();
  }

  var modal=document.getElementById('alarmQuickComment');
  var overlay=document.getElementById('alarmQuickCommentOverlay');
  var closeBtn=document.getElementById('alarmQuickCommentClose');
  var cancelBtn=document.getElementById('alarmQuickCommentCancel');
  var saveBtn=document.getElementById('alarmQuickCommentSave');
  var meta=document.getElementById('alarmQuickCommentMeta');
  var textarea=document.getElementById('alarmQuickCommentText');
  var statusBox=document.getElementById('alarmQuickCommentStatus');
  var previous=document.getElementById('alarmQuickCommentPrevious');
  var activeButton=null;
  var current=null;

  function setStatus(message,type){
    if(!statusBox)return;
    statusBox.hidden=!message;
    statusBox.textContent=message||'';
    statusBox.className='alarmQuickComment__status'+(type?' is-'+type:'');
  }
  function formatDate(value){
    var raw=String(value||'');
    var m=raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2}(?::\d{2})?)/);
    return m?m[3]+'/'+m[2]+'/'+m[1]+' '+m[4]:raw;
  }
  function closeModal(){
    if(!modal)return;
    modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');
    if(overlay)overlay.hidden=true;
    document.body.classList.remove('has-alarm-quick-comment');
    current=null;
    if(activeButton)activeButton.focus();
    activeButton=null;
  }
  function readButton(button){
    return {
      event_key:button.getAttribute('data-event-key')||'',
      subject:button.getAttribute('data-subject')||button.getAttribute('data-tag')||'',
      subject_label:button.getAttribute('data-subject-label')||button.getAttribute('data-tag')||'',
      context:button.getAttribute('data-context')||'alarma',
      tag:button.getAttribute('data-tag')||'',
      timestamp:button.getAttribute('data-timestamp')||'',
      value:button.getAttribute('data-value')||'',
      description:button.getAttribute('data-description')||'',
      can_create:button.getAttribute('data-can-create')==='1'
    };
  }
  function openModal(button){
    if(!modal||!textarea)return;
    activeButton=button;current=readButton(button);
    var metaParts=[current.subject_label||current.tag];
    if(current.timestamp)metaParts.push(formatDate(current.timestamp));
    if(current.value)metaParts.push(current.value);
    meta.textContent=metaParts.filter(Boolean).join(' · ');
    textarea.value='';textarea.readOnly=!current.can_create;
    if(saveBtn)saveBtn.hidden=!current.can_create;
    if(previous){previous.hidden=true;previous.textContent='';}
    setStatus('Cargando comentario…','loading');
    if(overlay)overlay.hidden=false;
    modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');
    document.body.classList.add('has-alarm-quick-comment');

    var params=new URLSearchParams({action:'get_comment',subject:current.subject,tag:current.tag,timestamp:current.timestamp,value:current.value,context:current.context});
    fetch('alarm_events_api.php?'+params.toString(),{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(response){return response.json().then(function(data){if(!response.ok)throw new Error(data.error||'No se pudo cargar');return data;});})
      .then(function(data){
        textarea.value=data.comment||'';
        button.classList.toggle('has-comment',!!data.comment);
        window.dispatchEvent(new CustomEvent('clear-alarm-comment-updated',{detail:{subject:button.getAttribute('data-subject')||button.getAttribute('data-tag')||'',comment:data.comment||''}}));
        if(data.comment&&previous){
          var detail=[];if(data.user)detail.push('Usuario: '+data.user);if(data.updated_at)detail.push('Actualizado: '+formatDate(data.updated_at));
          previous.textContent=detail.join(' · ')||'Comentario cargado';previous.hidden=false;
        }
        setStatus(data.comment?'Comentario central cargado.':'Todavía no hay comentarios para esta alarma o entidad.',data.comment?'ok':'');
        if(current.can_create)textarea.focus();
      })
      .catch(function(error){setStatus(error.message||'No se pudo cargar el comentario.','error');});
  }
  function saveComment(){
    if(!current||!current.can_create||!textarea)return;
    var comment=textarea.value.trim();
    var savedSubject=current.subject||current.tag||'';
    if(!comment){setStatus('Escribí un comentario antes de guardar.','error');return;}
    var data=new FormData();
    data.append('action','save_comment');data.append('event_key',current.event_key||'alarm-event');
    data.append('subject',current.subject);data.append('context',current.context);
    data.append('tag',current.tag);data.append('timestamp',current.timestamp);data.append('value',current.value);
    data.append('description',current.description);data.append('comment',comment);
    if(saveBtn)saveBtn.disabled=true;
    setStatus('Guardando comentario…','loading');
    fetch('alarm_events_api.php',{method:'POST',body:data,headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(response){return response.json().then(function(payload){if(!response.ok)throw new Error(payload.error||'No se pudo guardar');return payload;});})
      .then(function(payload){
        if(activeButton)activeButton.classList.add('has-comment');
        window.dispatchEvent(new CustomEvent('clear-alarm-comment-updated',{detail:{subject:savedSubject,comment:comment}}));
        if(previous){previous.textContent='Usuario: '+(payload.user||'—')+' · Guardado: '+formatDate(payload.saved_at||'');previous.hidden=false;}
        setStatus(payload.message||'Comentario guardado.','ok');
      })
      .catch(function(error){setStatus(error.message||'No se pudo guardar el comentario.','error');})
      .finally(function(){if(saveBtn)saveBtn.disabled=false;});
  }

  document.addEventListener('click',function(event){
    var sqlButton=event.target.closest&&event.target.closest('[data-sql-history="true"]');
    if(sqlButton){event.preventDefault();event.stopPropagation();openSqlHistory(sqlButton);return;}
    var button=event.target.closest&&event.target.closest('[data-alarm-comment="true"]');
    if(!button)return;
    event.preventDefault();event.stopPropagation();openModal(button);
  });
  document.addEventListener('keydown',function(event){
    if(event.key!=='Escape')return;
    if(sqlModal&&sqlModal.classList.contains('is-open')){closeSqlHistory();return;}
    if(modal&&modal.classList.contains('is-open'))closeModal();
  });
  if(sqlClose)sqlClose.addEventListener('click',closeSqlHistory);
  if(sqlOverlay)sqlOverlay.addEventListener('click',closeSqlHistory);
  if(closeBtn)closeBtn.addEventListener('click',closeModal);
  if(cancelBtn)cancelBtn.addEventListener('click',closeModal);
  if(overlay)overlay.addEventListener('click',closeModal);
  if(saveBtn)saveBtn.addEventListener('click',saveComment);
})();
