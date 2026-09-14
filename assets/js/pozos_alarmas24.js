(function(){
  'use strict';
  var chooser=document.getElementById('columnChooser');
  var chooserButton=document.getElementById('columnChooserButton');
  var exportButton=document.getElementById('exportPozosExcel');
  var toggles=Array.prototype.slice.call(document.querySelectorAll('[data-column-toggle]'));
  var columns=window.CLEAR_POZOS_ALARMAS_COLUMNS||[];
  var storageKey=window.CLEAR_POZOS_ALARMAS_STORAGE_KEY||'clear:pozosAlarmas24:visibleColumns:v3';
  var exportUrl=window.CLEAR_POZOS_ALARMAS_EXPORT_URL||'pozos_alarmas24_export.php';

  function readVisible(){
    try{
      var raw=localStorage.getItem(storageKey);
      if(!raw)return columns.map(function(_,i){return i;});
      var parsed=JSON.parse(raw);
      return Array.isArray(parsed)?parsed.map(Number).filter(function(i){return i>=0&&i<columns.length;}):[];
    }catch(e){return columns.map(function(_,i){return i;});}
  }
  function saveVisible(indices){try{localStorage.setItem(storageKey,JSON.stringify(indices));}catch(e){}}
  function selectedIndices(){return toggles.filter(function(t){return t.checked;}).map(function(t){return Number(t.getAttribute('data-column-toggle'));});}
  function applyColumns(indices){
    var visible={};indices.forEach(function(i){visible[i]=true;});
    document.querySelectorAll('[data-column-index]').forEach(function(cell){cell.hidden=!visible[Number(cell.getAttribute('data-column-index'))];});
    toggles.forEach(function(toggle){toggle.checked=!!visible[Number(toggle.getAttribute('data-column-toggle'))];});
    saveVisible(indices);
  }

  if(chooserButton&&chooser)chooserButton.addEventListener('click',function(){chooser.hidden=!chooser.hidden;});
  toggles.forEach(function(toggle){toggle.addEventListener('change',function(){var selected=selectedIndices();if(!selected.length){toggle.checked=true;selected=selectedIndices();}applyColumns(selected);});});
  var showAll=document.getElementById('showAllColumns');
  if(showAll)showAll.addEventListener('click',function(){applyColumns(columns.map(function(_,i){return i;}));});
  var compact=document.getElementById('hideOptionalColumns');
  if(compact)compact.addEventListener('click',function(){
    var important=['ALM_ALMEXTFLD2','ALM_DESCR','ALM_NATIVETIMEIN','ALM_NATIVETIMELAST','ALM_VALUE','ALM_TAGNAME','ALM_UNIT','ALM_ALMSTATUS','ALM_ALMPRIORITY'];
    var indices=[];columns.forEach(function(column,i){if(important.indexOf(String(column).toUpperCase())!==-1)indices.push(i);});
    applyColumns(indices.length?indices:columns.slice(0,Math.min(5,columns.length)).map(function(_,i){return i;}));
  });
  applyColumns(readVisible());


  var filtersForm=document.getElementById('pozosAlarmasFilters');
  if(filtersForm){
    filtersForm.addEventListener('submit',function(event){
      var fromDate=filtersForm.querySelector('[name="desde"]');
      var toDate=filtersForm.querySelector('[name="hasta"]');
      var fromTime=filtersForm.querySelector('[name="hora_desde"]');
      var toTime=filtersForm.querySelector('[name="hora_hasta"]');
      if(fromDate&&toDate&&fromDate.value&&toDate.value){
        var start=new Date(fromDate.value+'T'+((fromTime&&fromTime.value)||'00:00')+':00');
        var end=new Date(toDate.value+'T'+((toTime&&toTime.value)||'23:59')+':59');
        if(start>end){
          event.preventDefault();
          alert('La fecha desde no puede ser posterior a la fecha hasta.');
        }
      }
    });
    filtersForm.querySelectorAll('.pozosColumnFilter').forEach(function(input){
      input.addEventListener('keydown',function(event){
        if(event.key==='Enter'){
          event.preventDefault();
          filtersForm.requestSubmit ? filtersForm.requestSubmit() : filtersForm.submit();
        }
      });
    });
  }

  if(exportButton)exportButton.addEventListener('click',function(){
    var form=document.getElementById('pozosAlarmasFilters');
    var params=new URLSearchParams(new FormData(form));
    params.set('columns',selectedIndices().map(function(i){return columns[i];}).filter(Boolean).join(','));
    window.location.href=exportUrl+'?'+params.toString();
  });
})();
