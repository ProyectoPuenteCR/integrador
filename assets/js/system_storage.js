/* CLEAR: graficos HTML/CSS, sin CDN ni refresco automatico. */
(function () {
  'use strict';
  var root=document.getElementById('systemStorage');
  if(!root) return;
  var $=function(id){return document.getElementById('storage'+id);};
  var snapshot=null, page=0, busy=false;
  var number=new Intl.NumberFormat('es-AR',{maximumFractionDigits:2});
  var integer=new Intl.NumberFormat('es-AR',{maximumFractionDigits:0});
  function valid(n){return typeof n==='number' && isFinite(n);}
  function mb(n){return valid(n)?number.format(n):'—';}
  function size(n){return !valid(n)?'No disponible':n>=1024?number.format(n/1024)+' GB':number.format(n)+' MB';}
  function pct(n){return valid(n)?number.format(n)+' %':'No disponible';}
  function text(id,value){$(id).textContent=value;}
  function node(tag,cls,value){var e=document.createElement(tag);if(cls)e.className=cls;if(value!==undefined)e.textContent=value;return e;}
  function warn(id,message){$(id).hidden=!message;$(id).textContent=message||'';}
  function key(t){return t.SCHEMA_NAME+'.'+t.TABLE_NAME;}
  function empty(target,message){target.replaceChildren(node('p','storage__muted',message));}
  function cell(row,value){row.appendChild(node('td','',value));}
  function filtered(){
    var q=$('Search').value.trim().toLocaleLowerCase('es');
    return snapshot.tables.rows.filter(function(t){return key(t).toLocaleLowerCase('es').indexOf(q)!==-1;});
  }
  function drawTables(){
    if(!snapshot) return;
    var rows=filtered(), sort=$('Sort').value;
    rows.sort(function(a,b){
      if(sort==='TABLE_NAME') return key(a).localeCompare(key(b),'es');
      var av=valid(a[sort])?a[sort]:-1,bv=valid(b[sort])?b[sort]:-1;
      return bv-av || key(a).localeCompare(key(b),'es');
    });
    var reserved=rows.reduce(function(s,t){return s+(valid(t.RESERVED_MB)?t.RESERVED_MB:0);},0);
    text('TableSummary',snapshot.tables.ok?rows.length+' de '+snapshot.tables.rows.length+' tablas · Reserva medida: '+size(reserved):'Medición de tablas no disponible');
    var limit=Number($('PageSize').value)||Math.max(1,rows.length);
    var pages=Math.max(1,Math.ceil(rows.length/limit));
    page=Math.min(page,pages-1);
    var body=$('TableRows');body.replaceChildren();
    rows.slice(page*limit,(page+1)*limit).forEach(function(t){
      var tr=node('tr');
      tr.tabIndex=0;
      tr.title=t.IN_MEMORY?'Tabla en memoria: medición no disponible':'Seleccionar fila';
      cell(tr,key(t)+(t.IN_MEMORY?' (en memoria)':''));
      cell(tr,valid(t.ROWS_APPROX)?integer.format(t.ROWS_APPROX):'—');
      ['DATA_MB','INDEX_MB','USED_MB','RESERVED_MB','UNUSED_MB'].forEach(function(k){cell(tr,mb(t[k]));});
      function select(){body.querySelectorAll('.is-selected').forEach(function(el){el.classList.remove('is-selected');});tr.classList.add('is-selected');}
      tr.addEventListener('click',select);
      tr.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();select();}});
      body.appendChild(tr);
    });
    if(!rows.length){var tr=node('tr');var td=node('td','',snapshot.tables.ok?'No hay tablas que coincidan con el filtro.':'Medición no disponible; revisá el aviso de permisos.');td.colSpan=7;tr.appendChild(td);body.appendChild(tr);}
    text('Page',(page+1)+' / '+pages);$('Prev').disabled=page===0;$('Next').disabled=page>=pages-1;
    drawBars(rows);
  }
  function drawBars(rows){
    var bars=$('Bars');bars.replaceChildren();
    var top=rows.filter(function(t){return valid(t.RESERVED_MB)&&t.RESERVED_MB>0;})
      .sort(function(a,b){return b.RESERVED_MB-a.RESERVED_MB;}).slice(0,10);
    if(!top.length){empty(bars,snapshot.tables.ok?'No hay tamaños medidos para graficar con este filtro.':'El gráfico requiere acceso a los metadatos de las tablas.');return;}
    var max=top[0].RESERVED_MB;
    top.forEach(function(t){
      var button=node('button','storage__barrow');button.type='button';
      button.title=key(t)+' · Reservado: '+size(t.RESERVED_MB);
      button.setAttribute('aria-label',button.title+'. Filtrar por esta tabla.');
      button.appendChild(node('span','storage__barlabel',key(t)));
      var track=node('span','storage__track'), stack=node('span','storage__stack');
      stack.style.width=(t.RESERVED_MB/max*100)+'%';
      [['DATA_MB','data'],['INDEX_MB','index'],['UNUSED_MB','unused']].forEach(function(pair){
        var seg=node('span','storage__seg--'+pair[1]);
        seg.style.width=(Math.max(0,t[pair[0]]||0)/t.RESERVED_MB*100)+'%';
        stack.appendChild(seg);
      });
      track.appendChild(stack);button.appendChild(track);
      button.appendChild(node('span','storage__barvalue',size(t.RESERVED_MB)));
      button.addEventListener('click',function(){$('Search').value=key(t);page=0;drawTables();});
      bars.appendChild(button);
    });
  }
  function drawFiles(){
    var body=$('FileRows');body.replaceChildren();
    snapshot.files.rows.forEach(function(f){
      var tr=node('tr');
      var growth=!f.GROWTH_PAGES?'Desactivado':f.GROWTH_PERCENT?number.format(f.GROWTH_PAGES)+' %':size(f.GROWTH_PAGES/128);
      var max=f.MAX_PAGES===-1?'Hasta límite de motor / disco':f.MAX_PAGES===0?'Sin crecimiento':size(f.MAX_PAGES/128);
      [f.FILE_NAME,f.FILE_TYPE_DESC,f.FILE_STATE,mb(f.SIZE_MB),mb(f.USED_MB),growth,max].forEach(function(v){cell(tr,v);});
      body.appendChild(tr);
    });
    warn('FileError',snapshot.files.ok?'':snapshot.files.error);
  }
  function draw(){
    var f=snapshot.files,l=snapshot.log;
    text('DataSize',size(f.data_mb));text('DataUsed',size(f.data_used_mb));
    text('DataFree','Libre dentro de los archivos: '+size(f.data_free_mb));
    text('LogFile',size(f.log_file_mb));
    text('LogPercent',l.ok?pct(l.percent):'No disponible');
    text('LogUsed','Uso actual: '+size(l.used_mb));
    var color=l.ok?(l.percent>=95?'var(--red)':l.percent>=80?'var(--amber)':'var(--petrol)'):'var(--line-strong)';
    $('LogCard').style.borderLeftColor=color;
    $('LogRing').style.setProperty('--ring-color',color);
    $('LogRing').style.setProperty('--occupancy',(l.ok?Math.max(0,Math.min(100,l.percent)):0)+'%');
    $('LogRing').setAttribute('aria-label',l.ok?'Log ocupado: '+pct(l.percent):'Ocupación del log no disponible');
    text('LogRingText',l.ok?pct(l.percent):'—');
    text('LogDetail',l.ok?'Usado '+size(l.used_mb)+' · Libre interno '+size(l.free_mb)+' · Total utilizable '+size(l.total_mb):l.error);
    text('LogState',l.ok?(l.percent>=95?'Ocupación alta: revisar con el DBA':l.percent>=80?'Atención: revisar ocupación del log':'Por debajo del umbral de aviso'):'Sin evaluación del log');
    text('Recovery',snapshot.meta?'Recuperación: '+snapshot.meta.RECOVERY+' · Espera de reutilización: '+snapshot.meta.REUSE_WAIT:'Estado de recuperación no disponible');
    warn('TableError',snapshot.tables.ok?'':snapshot.tables.error);
    text('Warnings',snapshot.warnings.join(' '));
    drawTables();drawFiles();
    var when=snapshot.meta?snapshot.meta.SERVER_TIME+' (hora del servidor SQL)':new Date(snapshot.captured_at).toLocaleString('es-AR');
    text('Status','Base: '+snapshot.database+' · Medición: '+when+' · Lectura: '+number.format(snapshot.elapsed_ms)+' ms · Caché por sesión: 60 s'+(snapshot.cache_age?' (reutilizada; '+snapshot.cache_age+' s)':'')+'. Sin actualización automática.');
  }
  async function load(){
    if(busy)return;
    busy=true;$('Refresh').disabled=true;$('Database').disabled=true;
    root.setAttribute('aria-busy','true');
    warn('Error','');text('Status','Leyendo metadatos de '+$('Database').value+'...');
    $('Content').hidden=true;
    // No se conservan datos de otra base ante un fallo.
    snapshot=null;
    try{
      var response=await fetch('estado_sistema_storage_api.php?db='+encodeURIComponent($('Database').value),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      if(!response.ok || !(response.headers.get('content-type')||'').includes('application/json')) throw new Error('No se pudo consultar el almacenamiento. Revisá la sesión, conexión y permisos.');
      var result=await response.json();
      if(!result.ok) throw new Error(result.error||'No se obtuvo una medición válida.');
      snapshot=result;
      var select=$('Database');select.replaceChildren();
      result.databases.forEach(function(name){var option=node('option','',name);option.value=name;option.selected=name===result.database;select.appendChild(option);});
      page=0;draw();$('Content').hidden=false;
    }catch(e){
      warn('Error',e.message||'Error al consultar los tamaños.');
      text('Status','No se muestran tamaños porque la lectura no se pudo completar.');
    }finally{busy=false;$('Refresh').disabled=false;$('Database').disabled=false;root.setAttribute('aria-busy','false');}
  }
  $('Refresh').addEventListener('click',load);
  $('Database').addEventListener('change',function(){$('Search').value='';load();});
  $('Search').addEventListener('input',function(){page=0;drawTables();});
  $('Sort').addEventListener('change',function(){page=0;drawTables();});
  $('PageSize').addEventListener('change',function(){page=0;drawTables();});
  $('Clear').addEventListener('click',function(){$('Search').value='';page=0;drawTables();$('Search').focus();});
  $('Prev').addEventListener('click',function(){page=Math.max(0,page-1);drawTables();});
  $('Next').addEventListener('click',function(){page++;drawTables();});
  load();
})();
