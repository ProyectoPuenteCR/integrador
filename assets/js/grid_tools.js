(function(){
  'use strict';
  var button=document.getElementById('gridToolsBtn');
  var tables=[],state={},panel=null,activeItem=null,activeButton=null,saveTimer=0,refreshTimer=0;
  var remoteLoaded=false,dirty={},spans=new WeakMap();
  var preferenceKey='global_grid_columns',localKey='clear_global_grid_columns_v1';

  function clean(value){return String(value||'').replace(/[↕▲▼]/g,'').replace(/\s+/g,' ').trim();}
  function slug(value){return clean(value).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'')||'columna';}
  function tableKey(table,index){
    var page=(location.pathname.split('/').pop()||'pantalla').replace(/\.php$/i,'');
    return page+':'+(table.id||table.getAttribute('data-grid-key')||('grilla_'+(index+1)));
  }
  function headerRow(table){return table.tHead&&table.tHead.rows[0];}
  function labelFor(cell,index){
    var explicit=cell.getAttribute('data-column-label')||cell.getAttribute('data-report-label')||cell.getAttribute('data-label');
    if(explicit)return clean(explicit);
    var copy=cell.cloneNode(true);
    copy.querySelectorAll('input,select,textarea,.column-drag-handle,.column-resizer,.sort-indicator,.sortarrow,[aria-hidden="true"]').forEach(function(node){node.remove();});
    return clean(copy.textContent)||('Columna '+(index+1));
  }
  function columnsFor(table){
    var row=headerRow(table);if(!row)return[];
    var used={};
    return Array.prototype.map.call(row.cells,function(cell,index){
      var label=labelFor(cell,index);
      var base=cell.getAttribute('data-clear-column-key')||cell.getAttribute('data-column-key')||slug(label),key=base;
      used[base]=(used[base]||0)+1;if(used[base]>1)key=base+'_'+used[base];
      cell.setAttribute('data-clear-column-key',key);
      return{key:key,label:label,cell:cell};
    });
  }
  function hasOwnSelector(table){
    if(table.matches('[data-grid-columns="native"],[data-grid-columns="off"]'))return true;
    if(table.matches('.tg-table')&&document.getElementById('tgColumnsBtn'))return true;
    if(table.matches('.tel-table')&&document.getElementById('colbtn'))return true;
    var card=table.closest('.nsTableCard');
    return table.hasAttribute('data-ns-custom-columns')&&card&&card.querySelector('[data-ns-columns-toggle]')&&card.querySelector('[data-ns-columns-menu]');
  }
  function eligible(table){
    var row=headerRow(table);
    return row&&row.cells.length>1&&!hasOwnSelector(table)&&Array.prototype.every.call(row.cells,function(cell){return cell.colSpan===1&&cell.rowSpan===1;});
  }
  function readLocal(){try{var value=JSON.parse(localStorage.getItem(localKey)||'{}');return value&&typeof value==='object'&&!Array.isArray(value)?value:{};}catch(e){return{};}}
  function writeLocal(){try{localStorage.setItem(localKey,JSON.stringify(state));}catch(e){}}
  function loadRemote(){
    if(remoteLoaded)return;remoteLoaded=true;
    fetch('user_prefs_api.php?key='+encodeURIComponent(preferenceKey),{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.json();}).then(function(res){
        if(!res||!res.ok||!res.value)return;
        var remote=JSON.parse(res.value);if(!remote||typeof remote!=='object'||Array.isArray(remote))return;
        Object.keys(remote).forEach(function(key){if(!dirty[key]&&Array.isArray(remote[key]))state[key]=remote[key];});
        tables.forEach(apply);writeLocal();if(panel&&!panel.hidden)renderList();
      }).catch(function(){});
  }
  function persist(item){
    dirty[item.key]=true;writeLocal();window.clearTimeout(saveTimer);
    saveTimer=window.setTimeout(function(){
      fetch('user_prefs_api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({key:preferenceKey,value:JSON.stringify(state)}).toString()}).catch(function(){});
    },450);
  }
  function hiddenSet(item){
    var values=Array.isArray(state[item.key])?state[item.key]:[];
    var keys=item.columns.map(function(column){return column.key;});
    var hidden=values.filter(function(key,index){return keys.indexOf(key)>=0&&values.indexOf(key)===index;});
    // Siempre dejar al menos una columna visible, incluso con preferencias antiguas.
    return hidden.length>=keys.length?hidden.slice(1):hidden;
  }
  function apply(item){
    var hidden=hiddenSet(item),visible=[];
    item.columns.forEach(function(column){visible[column.cell.cellIndex]=hidden.indexOf(column.key)<0;});
    Array.prototype.forEach.call(item.table.rows,function(row){
      var position=0;
      Array.prototype.forEach.call(row.cells,function(cell){
        if(!spans.has(cell))spans.set(cell,cell.colSpan);
        var span=spans.get(cell),shown=0;
        for(var i=position;i<position+span;i++)if(visible[i]!==false)shown++;
        var display=shown?'':'none';if(cell.style.display!==display)cell.style.display=display;
        var nextSpan=Math.max(1,shown);if(cell.colSpan!==nextSpan)cell.colSpan=nextSpan;
        position+=span;
      });
    });
  }
  function closePanel(){
    if(panel)panel.hidden=true;
    if(activeButton)activeButton.setAttribute('aria-expanded','false');
  }
  function buildPanel(){
    panel=document.createElement('section');panel.id='clearGridColumnsPanel';panel.className='clearGridTools';panel.hidden=true;
    panel.setAttribute('aria-label','Columnas de la grilla');
    panel.innerHTML='<div class="clearGridTools__head"><div><b>Columnas de la grilla</b><small>La selección se guarda para tu usuario</small></div><button type="button" data-grid-close aria-label="Cerrar columnas">×</button></div><label class="clearGridTools__table" hidden>Grilla<select data-grid-table></select></label><div class="clearGridTools__actions"><button type="button" data-grid-all>Mostrar todas</button><button type="button" data-grid-essential>Ocultar no esenciales</button></div><div class="clearGridTools__list" data-grid-list></div>';
    document.body.appendChild(panel);
    panel.querySelector('[data-grid-close]').addEventListener('click',function(){closePanel();if(activeButton)activeButton.focus();});
    panel.querySelector('[data-grid-all]').addEventListener('click',function(){
      if(!activeItem)return;state[activeItem.key]=[];apply(activeItem);renderList();persist(activeItem);
    });
    panel.querySelector('[data-grid-essential]').addEventListener('click',function(){
      if(!activeItem)return;
      var essential=/reporte|tipo|fecha|hora|tag|pozo|bater[ií]a|instalaci[oó]n|descripci[oó]n|estado|prioridad|total|comentario/i;
      state[activeItem.key]=activeItem.columns.filter(function(column){return!essential.test(column.label);}).map(function(column){return column.key;});
      state[activeItem.key]=hiddenSet(activeItem);apply(activeItem);renderList();persist(activeItem);
    });
    panel.querySelector('[data-grid-table]').addEventListener('change',function(event){
      activeItem=tables[parseInt(event.target.value,10)||0];renderList();
    });
  }
  function renderList(){
    if(!activeItem)return;
    var list=panel.querySelector('[data-grid-list]'),hidden=hiddenSet(activeItem);list.innerHTML='';
    activeItem.columns.slice().sort(function(a,b){return a.cell.cellIndex-b.cell.cellIndex;}).forEach(function(column){
      var label=document.createElement('label'),check=document.createElement('input');
      check.type='checkbox';check.checked=hidden.indexOf(column.key)<0;
      label.appendChild(check);label.appendChild(document.createTextNode(column.label));list.appendChild(label);
      check.addEventListener('change',function(){
        var current=hiddenSet(activeItem).slice();
        if(check.checked)current=current.filter(function(key){return key!==column.key;});
        else if(current.indexOf(column.key)<0)current.push(column.key);
        if(current.length>=activeItem.columns.length){check.checked=true;return;}
        state[activeItem.key]=current;apply(activeItem);persist(activeItem);
      });
    });
  }
  function positionPanel(){
    if(!panel||panel.hidden||!activeButton)return;
    var rect=activeButton.getBoundingClientRect(),width=Math.min(360,window.innerWidth-30);
    var top=Math.min(rect.bottom+8,Math.max(10,window.innerHeight-180));
    panel.style.width=width+'px';panel.style.left=Math.max(15,Math.min(window.innerWidth-width-15,rect.right-width))+'px';
    panel.style.top=top+'px';panel.style.maxHeight=Math.max(140,window.innerHeight-top-12)+'px';
  }
  function show(item,trigger){
    if(panel&&!panel.hidden&&activeItem===item&&activeButton===trigger){closePanel();return;}
    closePanel();activeItem=item;activeButton=trigger;
    if(!panel)buildPanel();
    var select=panel.querySelector('[data-grid-table]');select.innerHTML='';
    tables.forEach(function(entry,index){var option=document.createElement('option');option.value=String(index);option.textContent=entry.title;select.appendChild(option);});
    panel.querySelector('.clearGridTools__table').hidden=trigger!==button||tables.length<2;
    select.value=String(tables.indexOf(item));renderList();panel.hidden=false;
    trigger.setAttribute('aria-expanded','true');positionPanel();
    var first=panel.querySelector('input');if(first)first.focus();
  }
  function addToolbar(item){
    var bar=document.createElement('div');bar.className='clearGridToolbar';
    var toggle=document.createElement('button');toggle.type='button';toggle.className='clearGridColumnsToggle';
    toggle.textContent='Columnas ▾';toggle.title='Mostrar u ocultar columnas';
    toggle.setAttribute('aria-label','Mostrar u ocultar columnas de '+item.title);
    toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-controls','clearGridColumnsPanel');
    bar.appendChild(toggle);
    var anchor=item.table.closest('.tablescroll,.scadaTableScroll,.gtTableScroll,.ppScroll,.sigma-history__table-wrap')||item.table;
    anchor.parentNode.insertBefore(bar,anchor);item.toolbar=bar;
    toggle.addEventListener('click',function(event){event.stopPropagation();show(item,toggle);});
  }
  function refresh(){
    refreshTimer=0;
    var found=Array.prototype.filter.call(document.querySelectorAll('table'),eligible);
    tables=tables.filter(function(item){
      if(found.indexOf(item.table)>=0)return true;
      if(activeItem===item){closePanel();activeItem=null;}item.toolbar.remove();return false;
    });
    found.forEach(function(table,index){
      var item=tables.find(function(entry){return entry.table===table;});
      if(!item){
        var host=table.closest('section,.tablewrap,.nsTableCard,.mcCard,article');
        var heading=host&&host.querySelector('h2,h3');
        item={table:table,key:tableKey(table,index),title:clean(table.getAttribute('aria-label')||table.getAttribute('data-grid-title')||(heading&&heading.textContent)||('Grilla '+(index+1)))};
        tables.push(item);addToolbar(item);
      }
      item.columns=columnsFor(table);apply(item);
      table.tHead.querySelectorAll('th').forEach(function(th){
        if(th.querySelector('input,select,textarea')){th.classList.add('no-sort');th.setAttribute('data-sortable','false');}
      });
    });
    if(button)button.hidden=!tables.length;
    if(panel&&!panel.hidden)renderList();
  }
  function scheduleRefresh(mutations){
    var relevant=mutations.some(function(mutation){
      if(mutation.target.nodeType===1&&mutation.target.closest('table'))return true;
      return Array.prototype.some.call(mutation.addedNodes,function(node){return node.nodeType===1&&(node.matches('table')||node.querySelector('table'));})||
        Array.prototype.some.call(mutation.removedNodes,function(node){return node.nodeType===1&&(node.matches('table')||node.querySelector('table'));});
    });
    if(relevant&&!refreshTimer)refreshTimer=window.requestAnimationFrame(refresh);
  }
  function init(){
    state=readLocal();refresh();loadRemote();
    new MutationObserver(scheduleRefresh).observe(document.body,{childList:true,subtree:true});
  }
  if(button){
    button.setAttribute('aria-controls','clearGridColumnsPanel');button.setAttribute('aria-expanded','false');
    button.addEventListener('click',function(event){event.stopPropagation();if(tables.length)show(activeItem||tables[0],button);});
  }
  document.addEventListener('click',function(event){if(panel&&!panel.hidden&&!panel.contains(event.target)&&event.target!==activeButton)closePanel();});
  document.addEventListener('keydown',function(event){if(event.key==='Escape'&&panel&&!panel.hidden){closePanel();if(activeButton)activeButton.focus();}});
  document.addEventListener('click',function(event){if(event.target.closest&&event.target.closest('.gridFilterRow input,.gridFilterRow select,.mcFilterRow input,.mcFilterRow select'))event.stopPropagation();},true);
  document.addEventListener('clear-grid-columns-reordered',function(){tables.forEach(apply);if(panel&&!panel.hidden)renderList();});
  window.addEventListener('resize',positionPanel);
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
