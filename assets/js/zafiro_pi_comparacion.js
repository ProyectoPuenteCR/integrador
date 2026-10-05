(function(){
  function toast(msg){var t=document.createElement('div');t.className='zpcToast';t.textContent=msg;document.body.appendChild(t);setTimeout(function(){t.remove();},1800);}
  function savePref(key,value){var fd=new FormData();fd.append('key',key);fd.append('value',JSON.stringify(value));return fetch('user_prefs_api.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();});}
  var methods=document.querySelector('[data-zpc-methods]');
  if(methods){
    var checks=Array.prototype.slice.call(methods.querySelectorAll('.zpcMethod input[type=checkbox]'));
    var all=methods.querySelector('[data-zpc-all]'),none=methods.querySelector('[data-zpc-none]'),save=methods.querySelector('[data-zpc-save]');
    if(all)all.onclick=function(){checks.forEach(function(c){c.checked=true;});};
    if(none)none.onclick=function(){checks.forEach(function(c){c.checked=false;});};
    if(save)save.onclick=function(){
      var values=checks.filter(function(c){return c.checked;}).map(function(c){return c.value;});
      save.disabled=true;
      savePref('zafiro_pi_metodos',values).then(function(r){if(!r.ok)throw new Error(r.error||'No se pudo guardar');toast('Configuración guardada');setTimeout(function(){location.reload();},450);}).catch(function(e){alert(e.message);}).finally(function(){save.disabled=false;});
    };
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
    var filters=Array.prototype.slice.call(table.querySelectorAll('[data-zpc-col]'));
    function apply(){
      var body=table.tBodies[0]; if(!body)return;
      Array.prototype.slice.call(body.rows).forEach(function(row){
        if(row.cells.length<10)return;
        var ok=true;
        filters.forEach(function(inp){var q=(inp.value||'').trim().toLowerCase(),idx=parseInt(inp.getAttribute('data-zpc-col'),10);if(q&&row.cells[idx].textContent.toLowerCase().indexOf(q)<0)ok=false;});
        row.style.display=ok?'':'none';
      });
    }
    filters.forEach(function(i){i.addEventListener('input',apply);});
  }
})();