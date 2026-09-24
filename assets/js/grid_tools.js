(function(){
  'use strict';
  var button=document.getElementById('gridToolsBtn');
  if(!button)return;
  var tables=[],state={},panel=null,activeIndex=0,saveTimer=0,remoteLoaded=false;
  var preferenceKey='global_grid_columns';
  var localKey='clear_global_grid_columns_v1';

  function clean(value){return String(value||'').replace(/[↕▲▼]/g,'').replace(/\s+/g,' ').trim();}
  function slug(value){return clean(value).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'')||'columna';}
  function tableKey(table,index){
    var page=(location.pathname.split('/').pop()||'pantalla').replace(/\.php$/i,'');
    var identity=table.id||table.getAttribute('data-grid-key')||('grilla_'+(index+1));
    return page+':'+identity;
  }
  function headerRow(table){return table.tHead&&table.tHead.rows&&table.tHead.rows[0]?table.tHead.rows[0]:null;}
  function columnsFor(table){
    var row=headerRow(table);if(!row)return[];var used={};
    return Array.prototype.slice.call(row.cells).map(function(cell,index){
      var label=clean(cell.getAttribute('data-column-label')||cell.textContent)||('Columna '+(index+1));
      var base=cell.getAttribute('data-column-key')||slug(label),key=base;
      used[base]=(used[base]||0)+1;if(used[base]>1)key=base+'_'+used[base];
      return{index:index,key:key,label:label,cell:cell};
    });
  }
  function readLocal(){try{var value=JSON.parse(localStorage.getItem(localKey)||'{}');return value&&typeof value==='object'?value:{};}catch(e){return{};}}
  function writeLocal(){try{localStorage.setItem(localKey,JSON.stringify(state));}catch(e){}}
  function loadRemote(){
    if(remoteLoaded)return;remoteLoaded=true;
    fetch('user_prefs_api.php?key='+encodeURIComponent(preferenceKey),{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json();}).then(function(res){if(!res||!res.ok||!res.value)return;var remote=JSON.parse(res.value);if(!remote||typeof remote!=='object')return;state=Object.assign({},remote,state);tables.forEach(apply);writeLocal();if(panel&&!panel.hidden)renderList();}).catch(function(){});
  }
  function persist(){
    writeLocal();window.clearTimeout(saveTimer);saveTimer=window.setTimeout(function(){
      fetch('user_prefs_api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({key:preferenceKey,value:JSON.stringify(state)}).toString()}).catch(function(){});
    },450);
  }
  function hiddenSet(item){var values=state[item.key];return Array.isArray(values)?values:[];}
  function apply(item){
    var hidden=hiddenSet(item);item.columns.forEach(function(column){
      var show=hidden.indexOf(column.key)<0;
      var index=column.cell&&typeof column.cell.cellIndex==='number'?column.cell.cellIndex:column.index;
      Array.prototype.forEach.call(item.table.rows,function(row){var cell=row.cells[index];if(cell)cell.style.display=show?'':'none';});
    });
  }
  function buildPanel(){
    panel=document.createElement('section');panel.className='clearGridTools';panel.hidden=true;
    panel.innerHTML='<div class="clearGridTools__head"><div><b>Columnas de la grilla</b><small>La selección se guarda para tu usuario</small></div><button type="button" data-grid-close aria-label="Cerrar">×</button></div><label class="clearGridTools__table" hidden>Grilla<select data-grid-table></select></label><div class="clearGridTools__actions"><button type="button" data-grid-all>Mostrar todas</button><button type="button" data-grid-essential>Ocultar no esenciales</button></div><div class="clearGridTools__list" data-grid-list></div>';
    document.body.appendChild(panel);
    panel.querySelector('[data-grid-close]').addEventListener('click',function(){panel.hidden=true;});
    panel.querySelector('[data-grid-all]').addEventListener('click',function(){var item=tables[activeIndex];if(!item)return;state[item.key]=[];apply(item);renderList();persist();});
    panel.querySelector('[data-grid-essential]').addEventListener('click',function(){var item=tables[activeIndex];if(!item)return;var essential=/reporte|tipo|fecha|hora|tag|pozo|bater[ií]a|instalaci[oó]n|descripci[oó]n|estado|prioridad|total|comentario/i;state[item.key]=item.columns.filter(function(c){return !essential.test(c.label);}).map(function(c){return c.key;});if(state[item.key].length>=item.columns.length)state[item.key]=[];apply(item);renderList();persist();});
    var select=panel.querySelector('[data-grid-table]');select.addEventListener('change',function(){activeIndex=parseInt(select.value,10)||0;renderList();});
  }
  function renderList(){
    var item=tables[activeIndex];if(!item)return;var list=panel.querySelector('[data-grid-list]'),hidden=hiddenSet(item);list.innerHTML='';
    item.columns.slice().sort(function(a,b){return (a.cell?a.cell.cellIndex:a.index)-(b.cell?b.cell.cellIndex:b.index);}).forEach(function(column){var label=document.createElement('label'),check=document.createElement('input');check.type='checkbox';check.checked=hidden.indexOf(column.key)<0;label.appendChild(check);label.appendChild(document.createTextNode(column.label));list.appendChild(label);check.addEventListener('change',function(){var current=hiddenSet(item).slice();if(check.checked)current=current.filter(function(key){return key!==column.key;});else if(current.indexOf(column.key)<0)current.push(column.key);if(current.length>=item.columns.length){check.checked=true;return;}state[item.key]=current;apply(item);persist();});});
  }
  function show(){
    loadRemote();
    if(!panel)buildPanel();var select=panel.querySelector('[data-grid-table]'),label=panel.querySelector('.clearGridTools__table');select.innerHTML='';tables.forEach(function(item,index){var option=document.createElement('option');option.value=String(index);option.textContent=item.title;select.appendChild(option);});label.hidden=tables.length<2;select.value=String(activeIndex);renderList();panel.hidden=false;
    var rect=button.getBoundingClientRect(),width=Math.min(360,window.innerWidth-30);panel.style.width=width+'px';panel.style.left=Math.max(15,Math.min(window.innerWidth-width-15,rect.right-width))+'px';panel.style.top=(rect.bottom+8)+'px';
  }
  function init(){
    state=readLocal();document.querySelectorAll('table.grid').forEach(function(table,index){
      var columns=columnsFor(table);if(!columns.length)return;
      Array.prototype.slice.call(table.tHead.querySelectorAll('th')).forEach(function(th){if(th.querySelector('input,select,textarea')){th.classList.add('no-sort');th.setAttribute('data-sortable','false');}});
      var key=tableKey(table,index),title=table.getAttribute('aria-label')||table.getAttribute('data-grid-title')||((table.closest('section,.tablewrap,.nsTableCard,.mcCard')||document).querySelector('h2,h3')||{}).textContent||('Grilla '+(index+1));
      var item={table:table,key:key,title:clean(title),columns:columns};tables.push(item);apply(item);
    });
    if(!tables.length)return;button.hidden=false;
  }
  button.addEventListener('click',function(event){event.stopPropagation();if(panel&&!panel.hidden){panel.hidden=true;return;}show();});
  document.addEventListener('click',function(event){if(panel&&!panel.hidden&&!panel.contains(event.target)&&event.target!==button)panel.hidden=true;});
  document.addEventListener('click',function(event){if(event.target.closest&&event.target.closest('.gridFilterRow input,.gridFilterRow select,.mcFilterRow input,.mcFilterRow select'))event.stopPropagation();},true);
  document.addEventListener('clear-grid-columns-reordered',function(){if(panel&&!panel.hidden)renderList();});
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
