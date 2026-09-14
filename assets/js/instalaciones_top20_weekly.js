(function(){
  'use strict';

  var refreshSelect=document.getElementById('it20Refresh');
  if(refreshSelect){
    refreshSelect.addEventListener('change',function(){this.form.submit();});
    var seconds=parseInt(refreshSelect.value,10)||0;
    if(seconds>0)window.setTimeout(function(){window.location.reload();},seconds*1000);
  }

  var exportButton=document.getElementById('it20Export');
  if(exportButton)exportButton.addEventListener('click',function(){
    var form=document.getElementById('instalacionesTop20Filters');
    window.location.href='instalaciones_top20_export.php?'+new URLSearchParams(new FormData(form)).toString();
  });

  var filtersForm=document.getElementById('instalacionesTop20Filters');
  if(filtersForm){
    var serverCurrentWeek=filtersForm.getAttribute('data-current-week')||'';
    var selectedWeek=filtersForm.getAttribute('data-selected-week')||'';
    var followsCurrentWeek=serverCurrentWeek!==''&&selectedWeek===serverCurrentWeek;
    function localOperationalWeek(){
      var date=new Date();
      date.setHours(0,0,0,0);
      var mondayDay=date.getDay()===0?7:date.getDay();
      var daysSinceWednesday=(mondayDay-3+7)%7;
      date.setDate(date.getDate()-daysSinceWednesday);
      var year=date.getFullYear();
      var month=String(date.getMonth()+1).padStart(2,'0');
      var day=String(date.getDate()).padStart(2,'0');
      return year+'-'+month+'-'+day;
    }
    window.setInterval(function(){
      var browserWeek=localOperationalWeek();
      if(followsCurrentWeek&&browserWeek!==serverCurrentWeek){
        var params=new URLSearchParams(new FormData(filtersForm));
        params.set('semana',browserWeek);
        window.location.href='instalaciones_top20.php?'+params.toString();
      }
    },60000);
  }

  var modal=document.getElementById('it20CommentModal');
  if(!modal)return;
  var overlay=document.getElementById('it20CommentOverlay');
  var closeButton=document.getElementById('it20CommentClose');
  var cancelButton=document.getElementById('it20CommentCancel');
  var saveButton=document.getElementById('it20CommentSave');
  var meta=document.getElementById('it20CommentMeta');
  var textarea=document.getElementById('it20CommentText');
  var statusBox=document.getElementById('it20CommentStatus');
  var previous=document.getElementById('it20CommentPrevious');
  var current=null;
  var activeButton=null;

  function formatDate(value){
    var raw=String(value||'');
    var match=raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}:\d{2}(?::\d{2})?))?/);
    return match?match[3]+'/'+match[2]+'/'+match[1]+(match[4]?' '+match[4]:''):raw;
  }
  function setStatus(message,type){
    statusBox.hidden=!message;
    statusBox.textContent=message||'';
    statusBox.className='it20CommentModal__status'+(type?' is-'+type:'');
  }
  function closeModal(){
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden','true');
    if(overlay)overlay.hidden=true;
    document.body.classList.remove('has-it20-comment');
    current=null;
    if(activeButton)activeButton.focus();
    activeButton=null;
  }
  function markInstallation(installation,hasComment){
    Array.prototype.forEach.call(document.querySelectorAll('[data-installation-comment="true"]'),function(button){
      if((button.getAttribute('data-installation')||'').toUpperCase()===String(installation||'').toUpperCase()){
        button.classList.toggle('has-comment',hasComment);
      }
    });
  }
  function openModal(button){
    activeButton=button;
    current={
      installation:button.getAttribute('data-installation')||'',
      week_start:button.getAttribute('data-week-start')||'',
      week_end:button.getAttribute('data-week-end')||'',
      can_create:button.getAttribute('data-can-create')==='1'
    };
    meta.textContent=current.installation+' · '+formatDate(current.week_start)+' al '+formatDate(current.week_end);
    textarea.value='';
    textarea.readOnly=!current.can_create;
    if(saveButton)saveButton.hidden=!current.can_create;
    previous.hidden=true;previous.textContent='';
    setStatus('Cargando comentario…','loading');
    if(overlay)overlay.hidden=false;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden','false');
    document.body.classList.add('has-it20-comment');

    var params=new URLSearchParams({action:'get',instalacion:current.installation,semana_desde:current.week_start,semana_hasta:current.week_end});
    fetch('instalaciones_top20_comments_api.php?'+params.toString(),{cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(response){return response.json().then(function(data){if(!response.ok)throw new Error(data.error||'No se pudo cargar');return data;});})
      .then(function(data){
        textarea.value=data.comment||'';
        if(data.found){
          var detail=[];
          if(data.user)detail.push('Usuario: '+data.user);
          if(data.updated_at)detail.push('Actualizado: '+formatDate(data.updated_at));
          previous.textContent=detail.join(' · ')||'Comentario semanal cargado';
          previous.hidden=false;
          markInstallation(current.installation,true);
        }
        setStatus(data.found?'Comentario semanal cargado.':'Todavía no hay comentarios para esta instalación en la semana seleccionada.',data.found?'ok':'');
        if(current.can_create)textarea.focus();
      })
      .catch(function(error){setStatus(error.message||'No se pudo cargar el comentario.','error');});
  }
  function saveComment(){
    if(!current||!current.can_create)return;
    var comment=textarea.value.trim();
    if(!comment){setStatus('Escribí un comentario antes de guardar.','error');return;}
    var data=new FormData();
    data.append('action','save');data.append('instalacion',current.installation);
    data.append('semana_desde',current.week_start);data.append('semana_hasta',current.week_end);
    data.append('comment',comment);
    if(saveButton)saveButton.disabled=true;
    setStatus('Guardando comentario…','loading');
    fetch('instalaciones_top20_comments_api.php',{method:'POST',body:data,headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(response){return response.json().then(function(payload){if(!response.ok)throw new Error(payload.error||'No se pudo guardar');return payload;});})
      .then(function(payload){
        markInstallation(current.installation,true);
        previous.textContent='Usuario: '+(payload.user||'—')+' · Guardado: '+formatDate(payload.saved_at||'');
        previous.hidden=false;
        setStatus(payload.message||'Comentario semanal guardado.','ok');
      })
      .catch(function(error){setStatus(error.message||'No se pudo guardar el comentario.','error');})
      .then(function(){if(saveButton)saveButton.disabled=false;});
  }

  document.addEventListener('click',function(event){
    var button=event.target.closest&&event.target.closest('[data-installation-comment="true"]');
    if(!button)return;
    event.preventDefault();event.stopPropagation();openModal(button);
  });
  document.addEventListener('keydown',function(event){if(event.key==='Escape'&&modal.classList.contains('is-open'))closeModal();});
  if(closeButton)closeButton.addEventListener('click',closeModal);
  if(cancelButton)cancelButton.addEventListener('click',closeModal);
  if(overlay)overlay.addEventListener('click',closeModal);
  if(saveButton)saveButton.addEventListener('click',saveComment);
})();
