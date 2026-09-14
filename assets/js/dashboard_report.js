(function(){
  'use strict';
  var cfg=window.CLEAR_DASHBOARD_REPORT||{},items=Array.isArray(cfg.items)?cfg.items:[];
  var toggle=document.querySelector('[data-dashboard-report-toggle]'),label=document.querySelector('[data-dashboard-report-label]'),status=document.querySelector('[data-dashboard-report-status]');
  if(!toggle)return;

  function setStatus(message,error){if(!status)return;status.textContent=message||'';status.classList.toggle('is-error',!!error);}
  function sync(result){
    var selected={};((result&&result.keys)||[]).forEach(function(key){selected[key]=true;});
    var count=items.filter(function(item){return!!selected[item.key];}).length;
    toggle.checked=items.length>0&&count===items.length;
    toggle.indeterminate=count>0&&count<items.length;
    if(label)label.textContent=toggle.checked?'Resumen completo incluido':'Incluir resumen completo';
  }
  function refresh(){if(!window.CLEAR_NS_REPORT||!window.CLEAR_NS_REPORT.refresh)return;window.CLEAR_NS_REPORT.refresh().then(sync).catch(function(error){setStatus(error.message||'No se pudo consultar el reporte.',true);});}

  toggle.disabled=!items.length||!window.CLEAR_NS_REPORT||!window.CLEAR_NS_REPORT.setItems;
  toggle.addEventListener('change',function(){
    var active=toggle.checked,previous=!active;
    toggle.disabled=true;toggle.indeterminate=false;setStatus(active?'Agregando el resumen completo…':'Quitando el resumen completo…',false);
    window.CLEAR_NS_REPORT.setItems(items,active).then(function(result){
      toggle.checked=active;if(label)label.textContent=active?'Resumen completo incluido':'Incluir resumen completo';
      setStatus(active?(cfg.addedMessage||'Dashboard completo agregado al reporte.'):(cfg.removedMessage||'Dashboard quitado del reporte.'),false);
      if(result&&typeof result.count!=='undefined'&&window.CLEAR_NS_REPORT.updateCount)window.CLEAR_NS_REPORT.updateCount(result.count);
    }).catch(function(error){toggle.checked=previous;setStatus(error.message||'No se pudo actualizar el reporte.',true);}).then(function(){toggle.disabled=false;});
  });
  window.addEventListener('clear-report-selection-loaded',function(event){sync(event.detail||{});});
  refresh();
})();
