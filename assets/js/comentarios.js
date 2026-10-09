(function(){
  'use strict';
  var table=document.getElementById('commentsTable');if(!table)return;
  var storageKey='clear_comments_columns_v2',state={hidden:[],widths:{}};
  try{var saved=JSON.parse(localStorage.getItem(storageKey)||'{}');if(saved&&Array.isArray(saved.hidden))state.hidden=saved.hidden;if(saved&&saved.widths)state.widths=saved.widths;}catch(e){}
  var headers=Array.prototype.slice.call(table.querySelectorAll('thead th[data-column]'));
  function save(){try{localStorage.setItem(storageKey,JSON.stringify(state));}catch(e){}}
  function cells(key){return table.querySelectorAll('[data-column="'+key+'"]');}
  function apply(){headers.forEach(function(th){var key=th.dataset.column,hidden=state.hidden.indexOf(key)>=0,width=parseInt(state.widths[key],10);Array.prototype.forEach.call(cells(key),function(cell){cell.hidden=hidden;if(width>=70){cell.style.width=width+'px';cell.style.minWidth=width+'px';cell.style.maxWidth=width+'px';}});});}
  var button=document.getElementById('commentsColumnsButton'),menu=document.getElementById('commentsColumnsMenu');
  headers.forEach(function(th){var key=th.dataset.column,label=document.createElement('label'),check=document.createElement('input'),handle=document.createElement('span');check.type='checkbox';check.checked=state.hidden.indexOf(key)<0;check.disabled=key==='report';label.appendChild(check);label.appendChild(document.createTextNode(th.dataset.label||key));menu.appendChild(label);check.addEventListener('change',function(){state.hidden=check.checked?state.hidden.filter(function(x){return x!==key;}):state.hidden.concat(key);apply();save();});handle.className='commentsResizeHandle';handle.title='Arrastrar para cambiar el ancho';th.appendChild(handle);handle.addEventListener('mousedown',function(event){event.preventDefault();event.stopPropagation();var startX=event.clientX,startWidth=th.getBoundingClientRect().width;document.body.classList.add('commentsResizing');function move(e){state.widths[key]=Math.max(70,Math.round(startWidth+e.clientX-startX));apply();}function up(){document.removeEventListener('mousemove',move);document.removeEventListener('mouseup',up);document.body.classList.remove('commentsResizing');save();}document.addEventListener('mousemove',move);document.addEventListener('mouseup',up);});});
  button.addEventListener('click',function(event){event.stopPropagation();menu.hidden=!menu.hidden;button.setAttribute('aria-expanded',menu.hidden?'false':'true');});menu.addEventListener('click',function(event){event.stopPropagation();});document.addEventListener('click',function(){menu.hidden=true;button.setAttribute('aria-expanded','false');});apply();
  var modal=document.getElementById('commentsModal'),backdrop=document.getElementById('commentsModalBackdrop'),body=document.getElementById('commentsModalBody'),title=document.getElementById('commentsModalTitle'),lastFocus=null;
  var current=null,selectedRow=null,busy=false;
  var editBtn=document.getElementById('commentsEditBtn'),deleteBtn=document.getElementById('commentsDeleteBtn'),historyBtn=document.getElementById('commentsHistoryBtn'),historyBox=document.getElementById('commentsAudit');
  function open(row){
    var details={};try{details=JSON.parse(row.getAttribute('data-comment-details')||'{}');}catch(e){}
    current=details;selectedRow=row;lastFocus=document.activeElement;title.textContent=details.Asunto||'Comentario';body.innerHTML='';historyBox.hidden=true;historyBox.textContent='';
    Object.keys(details).forEach(function(key){if(key.charAt(0)==='_')return;var item=document.createElement('div');item.className='commentsDetail'+(key==='Comentario'?' commentsDetail--comment':'');var dt=document.createElement('span'),dd=document.createElement('div');dt.textContent=key;dd.textContent=details[key]||'—';item.appendChild(dt);item.appendChild(dd);body.appendChild(item);});
    editBtn.hidden=!details._canEdit;deleteBtn.hidden=!details._canDelete;
    backdrop.hidden=false;modal.hidden=false;document.body.classList.add('commentsModalOpen');document.getElementById('commentsModalClose').focus();
  }
  function close(){if(busy)return;modal.hidden=true;backdrop.hidden=true;document.body.classList.remove('commentsModalOpen');current=null;if(lastFocus)lastFocus.focus();}
  function request(action,comment){
    if(busy||!current)return;
    var fd=new FormData();fd.append('origin',current._origin);fd.append('id',current._id);fd.append('action',action);fd.append('previous',current.Comentario||'');fd.append('comment',comment||'');fd.append('csrf',window.CLEAR_COMMENTS_CSRF||'');
    busy=true;editBtn.disabled=true;deleteBtn.disabled=true;
    fetch('comentarios_admin_api.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(response){return response.json().then(function(data){if(!response.ok||!data.ok)throw new Error(data.error||'No se pudo guardar');return data;});})
      .then(function(data){alert(data.message||'Cambios guardados');window.location.reload();})
      .catch(function(err){alert(err.message||'No se pudo guardar');})
      .finally(function(){busy=false;editBtn.disabled=false;deleteBtn.disabled=false;});
  }
  editBtn.addEventListener('click',function(){
    if(!current||!current._canEdit||busy)return;
    var commentItem=body.querySelector('.commentsDetail--comment div');if(!commentItem)return;
    if(body.querySelector('.commentsEditArea'))return;
    var area=document.createElement('textarea');area.className='commentsEditArea';area.maxLength=20000;area.value=current.Comentario||'';
    var actions=document.createElement('div');actions.className='commentsEditActions';
    var saveBtn=document.createElement('button');saveBtn.type='button';saveBtn.className='nsButton';saveBtn.textContent='Guardar cambios';
    var cancelBtn=document.createElement('button');cancelBtn.type='button';cancelBtn.className='nsButton is-secondary';cancelBtn.textContent='Cancelar';
    saveBtn.addEventListener('click',function(){var value=area.value.trim();if(!value){alert('El comentario es obligatorio');return;}if(value===current.Comentario){alert('No hay cambios');return;}request('EDITAR',value);});
    cancelBtn.addEventListener('click',function(){area.remove();actions.remove();commentItem.hidden=false;editBtn.hidden=false;});
    actions.appendChild(cancelBtn);actions.appendChild(saveBtn);commentItem.after(area,actions);commentItem.hidden=true;editBtn.hidden=true;area.focus();
  });
  deleteBtn.addEventListener('click',function(){
    if(!current||!current._canDelete||busy)return;
    if(confirm('¿Eliminar este comentario del registro operativo? El historial será conservado para auditoría.'))request('ELIMINAR','');
  });
  historyBtn.addEventListener('click',function(){
    if(!current)return;historyBox.hidden=false;historyBox.textContent='Cargando historial…';
    var params=new URLSearchParams({origin:current._origin,id:String(current._id)});
    fetch('comentarios_admin_api.php?'+params.toString(),{cache:'no-store'})
      .then(function(response){return response.json().then(function(data){if(!response.ok||!data.ok)throw new Error(data.error||'Error al consultar');return data;});})
      .then(function(data){
        historyBox.innerHTML='';
        var heading=document.createElement('strong');heading.textContent='Historial de modificaciones';historyBox.appendChild(heading);
        if(data.setup_required){var warn=document.createElement('p');warn.textContent='Pendiente instalar la tabla de auditoría SQL.';historyBox.appendChild(warn);return;}
        if(!data.history.length){var p=document.createElement('p');p.textContent='Sin modificaciones registradas desde la activación de la auditoría.';historyBox.appendChild(p);return;}
        data.history.forEach(function(item){
          var article=document.createElement('article');var header=document.createElement('b');header.textContent=(item.ACCION||'')+' · '+(item.USUARIO||'')+' · '+(item.FECHA||'');
          var oldText=document.createElement('pre');oldText.textContent='Anterior: '+(item.TEXTO_ANTERIOR||'');
          article.appendChild(header);article.appendChild(oldText);
          if(item.TEXTO_NUEVO!==null&&item.TEXTO_NUEVO!==undefined){var newText=document.createElement('pre');newText.textContent='Nuevo: '+item.TEXTO_NUEVO;article.appendChild(newText);}
          historyBox.appendChild(article);
        });
      }).catch(function(err){historyBox.textContent=err.message;});
  });
  Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-comment-details]')).forEach(function(row){row.addEventListener('dblclick',function(event){if(!event.target.closest('button,input,a'))open(row);});row.addEventListener('keydown',function(event){if(event.key==='Enter'){event.preventDefault();open(row);}});});
  backdrop.addEventListener('click',close);document.getElementById('commentsModalClose').addEventListener('click',close);document.getElementById('commentsModalAccept').addEventListener('click',close);document.addEventListener('keydown',function(event){if(event.key==='Escape'&&!modal.hidden)close();});
})();
