(function(){
  'use strict';
  var form=document.getElementById('sigmaFilterForm');
  var table=document.getElementById('sigmaTable');
  if(!form||!table)return;
  var interval=document.getElementById('sigmaInterval');
  var intervalKey='clear_sigma_refresh';
  var columnsKey='clear_sigma_columns_v2';
  var timer=null;

  function submit(){var page=form.querySelector('[name="page"]');if(page)page.remove();form.submit();}
  form.querySelectorAll('select.sigma-col-filter,[name="zona"],[name="filas"]').forEach(function(select){select.addEventListener('change',submit);});
  form.querySelectorAll('input.sigma-col-filter').forEach(function(input){input.addEventListener('keydown',function(event){if(event.key==='Enter'){event.preventDefault();submit();}});});
  document.getElementById('sigmaRefresh').addEventListener('click',function(){window.location.reload();});

  function schedule(){
    if(timer)window.clearInterval(timer);
    var seconds=parseInt(interval.value||'0',10)||0;
    localStorage.setItem(intervalKey,String(seconds));
    if(seconds>0)timer=window.setInterval(function(){if(!document.hidden)window.location.reload();},seconds*1000);
  }
  var saved=localStorage.getItem(intervalKey);
  if(['0','60','300','600'].indexOf(saved)>=0)interval.value=saved;
  interval.addEventListener('change',schedule);schedule();

  var menu=document.getElementById('sigmaColumnsMenu');
  var button=document.getElementById('sigmaColumnsBtn');
  var headers=Array.prototype.slice.call(table.querySelectorAll('thead th[data-column]')).filter(function(header){return header.dataset.column!=='__REPORTE';});
  var hidden=[];
  try{hidden=JSON.parse(localStorage.getItem(columnsKey)||'[]');if(!Array.isArray(hidden))hidden=[];}catch(error){hidden=[];}
  function showColumn(column,show){table.querySelectorAll('[data-column="'+String(column).replace(/"/g,'\\"')+'"]').forEach(function(cell){cell.style.display=show?'':'none';});}
  var checkboxMap={};
  headers.forEach(function(header){
    var column=header.dataset.column;
    var label=document.createElement('label');
    var checkbox=document.createElement('input');
    checkbox.type='checkbox';checkbox.checked=hidden.indexOf(column)<0;checkbox.disabled=column==='POZO';
    checkboxMap[column]=checkbox;
    label.appendChild(checkbox);label.appendChild(document.createTextNode(column));menu.appendChild(label);
    showColumn(column,checkbox.checked);
    checkbox.addEventListener('change',function(){
      showColumn(column,checkbox.checked);
      hidden=headers.map(function(h){return h.dataset.column;}).filter(function(name){return name!=='POZO'&&checkboxMap[name]&&!checkboxMap[name].checked;});
      localStorage.setItem(columnsKey,JSON.stringify(hidden));
    });
  });
  button.addEventListener('click',function(event){event.stopPropagation();menu.classList.toggle('is-open');});
  menu.addEventListener('click',function(event){event.stopPropagation();});
  document.addEventListener('click',function(){menu.classList.remove('is-open');});
})();
