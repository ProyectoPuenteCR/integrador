(function(){
  function toast(msg){var t=document.createElement('div');t.className='zpcToast';t.textContent=msg;document.body.appendChild(t);setTimeout(function(){t.remove();},1800);}
  function savePref(key,value){var fd=new FormData();fd.append('key',key);fd.append('value',JSON.stringify(value));return fetch('user_prefs_api.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();});}
  document.querySelectorAll('[data-zpc-pref-panel]').forEach(function(panel){
    var key=panel.getAttribute('data-zpc-pref-key')||'',checks=Array.prototype.slice.call(panel.querySelectorAll('.zpcMethod input[type=checkbox]'));
    var all=panel.querySelector('[data-zpc-all]'),none=panel.querySelector('[data-zpc-none]'),save=panel.querySelector('[data-zpc-save]');
    if(all)all.onclick=function(){checks.forEach(function(c){c.checked=true;});};
    if(none)none.onclick=function(){checks.forEach(function(c){c.checked=false;});};
    if(save)save.onclick=function(){
      var values=checks.filter(function(c){return c.checked;}).map(function(c){return c.value;});
      save.disabled=true;
      savePref(key,values).then(function(r){if(!r.ok)throw new Error(r.error||'No se pudo guardar');toast('Configuración guardada');setTimeout(function(){location.reload();},450);}).catch(function(e){alert(e.message);}).finally(function(){save.disabled=false;});
    };
  });
  var modal=document.querySelector('[data-zpc-config-modal]'),openConfig=document.querySelector('[data-zpc-config-open]'),lastFocus=null;
  if(modal&&openConfig){
    function closeConfig(){modal.hidden=true;document.body.style.overflow='';if(lastFocus)lastFocus.focus();}
    openConfig.addEventListener('click',function(){lastFocus=document.activeElement;modal.hidden=false;document.body.style.overflow='hidden';modal.querySelector('.zpcConfigClose').focus();});
    modal.querySelectorAll('[data-zpc-config-close]').forEach(function(el){el.addEventListener('click',closeConfig);});
    modal.addEventListener('keydown',function(e){
      if(e.key==='Escape'){e.preventDefault();closeConfig();}
      if(e.key==='Tab'){var items=Array.prototype.slice.call(modal.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled])')).filter(function(el){return el.getClientRects().length;});
        if(!items.length)return;var first=items[0],last=items[items.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}
    });
  }
  var excluded=(window.CLEAR_ZPC&&Array.isArray(window.CLEAR_ZPC.excludedWells))?window.CLEAR_ZPC.excludedWells.slice():[];
  document.querySelectorAll('[data-zpc-exclude]').forEach(function(c){
    c.addEventListener('change',function(){
      var v=c.value,idx=excluded.indexOf(v);
      if(c.checked&&idx<0)excluded.push(v);
      if(!c.checked&&idx>=0)excluded.splice(idx,1);
      c.disabled=true;
      savePref('zafiro_pi_exclusiones',excluded).then(function(r){if(!r.ok)throw new Error(r.error||'No se pudo guardar');toast(c.checked?'Pozo excluido':'Pozo reincorporado');if(!new URLSearchParams(location.search).has('ver_excluidos'))setTimeout(function(){location.reload();},350);}).catch(function(e){c.checked=!c.checked;alert(e.message);}).finally(function(){c.disabled=false;});
    });
  });
  var table=document.getElementById('zpcTable');
  if(table){
    // El estado visual deriva de los mismos checks de inclusion en reportes.
    function updateRowSelection(row){
      var checks=row.querySelectorAll('.zpcReportPick input[type="checkbox"]');
      var selected=Array.prototype.some.call(checks,function(c){return c.checked;});
      row.classList.toggle('is-row-selected',selected);
    }
    function refreshSelectedRows(){Array.prototype.forEach.call(table.tBodies[0].rows,updateRowSelection);}
    table.addEventListener('change',function(e){if(e.target.matches('.zpcReportPick input[type="checkbox"]'))updateRowSelection(e.target.closest('tr'));});
    var reportSelectAll=table.querySelector('thead [data-ns-report-select-all]');
    if(reportSelectAll)reportSelectAll.addEventListener('change',function(){setTimeout(refreshSelectedRows,0);});
    refreshSelectedRows();
    var filters=Array.prototype.slice.call(table.querySelectorAll('[data-zpc-col]'));
    function apply(){
      var body=table.tBodies[0]; if(!body)return;
      Array.prototype.slice.call(body.rows).forEach(function(row){
        if(row.cells.length<10)return;
        var ok=true;
        filters.forEach(function(inp){
          var q=(inp.value||'').trim().toLowerCase(),idx=parseInt(inp.getAttribute('data-zpc-col'),10);
          if(!q)return;
          var cell=(row.cells[idx]&&row.cells[idx].textContent?row.cells[idx].textContent:'').trim().toLowerCase();
          if(inp.tagName==='SELECT'){if(cell!==q)ok=false;}
          else if(cell.indexOf(q)<0)ok=false;
        });
        row.style.display=ok?'':'none';
      });
    }
    filters.forEach(function(i){
      i.addEventListener(i.tagName==='SELECT'?'change':'input',apply);
    });
  }
})();