(function(){
  'use strict';
  const cfg=window.CLEAR_TELEMETRY_GRID||{};
  const table=document.getElementById('tgTable');
  if(!table)return;
  const tbody=table.tBodies[0];
  const search=document.getElementById('tgSearch');
  const visible=document.getElementById('tgVisible');
  const shown=document.getElementById('tgShown');
  const pageSizeSelect=document.getElementById('tgPageSize');
  const storage='clear_tg_cols_'+(cfg.key||'telemetry')+'_v'+(cfg.columnStateVersion||'317');
  /* Clave nueva: descarta intervalos agresivos guardados por versiones previas. */
  const refreshKey='clear_tg_refresh_safe_v2_'+(cfg.key||'telemetry');
  const pageSizeKey='clear_tg_page_size_'+(cfg.key||'telemetry');
  const filtersKey='clear_tg_filters_'+(cfg.key||'telemetry')+'_v1';
  const persistFilters=cfg.persistFilters===true;
  let sortColumn='',sortDirection=1,timer=null,dragHead=null,dragItem=null,quickFilter='';

  const normalize=value=>String(value??'').trim().toLocaleLowerCase('es');
  const rows=()=>Array.from(tbody.rows);
  const attrValue=value=>String(value).replace(/\\/g,'\\\\').replace(/"/g,'\\"');
  const cell=(row,column)=>row.querySelector('td[data-column="'+attrValue(column)+'"]');
  const filteredRows=()=>rows().filter(row=>!row.hidden);

  function currentPageSize(){
    if(!pageSizeSelect||pageSizeSelect.value==='all')return Infinity;
    const value=parseInt(pageSizeSelect.value||'50',10);
    return Number.isFinite(value)&&value>0?value:50;
  }

  function applyPageLimit(){
    const matched=filteredRows();
    const limit=currentPageSize();
    rows().forEach(row=>row.classList.remove('tg-page-hidden'));
    matched.forEach((row,index)=>row.classList.toggle('tg-page-hidden',index>=limit));
    if(visible)visible.textContent=String(matched.length);
    if(shown)shown.textContent=String(Math.min(matched.length,limit));
  }

  function selectedGroups(){
    const output={};
    document.querySelectorAll('.tg-panel').forEach(panel=>{
      const values=Array.from(panel.querySelectorAll('.tg-multi:checked')).map(input=>normalize(input.value));
      if(values.length)output[panel.dataset.group]=values;
    });
    return output;
  }

  function updateFilterSummaries(){
    document.querySelectorAll('.tg-panel').forEach(panel=>{
      const summary=panel.querySelector('summary small');
      if(!summary)return;
      const count=panel.querySelectorAll('.tg-multi:checked').length;
      summary.textContent=count?count+' seleccionado'+(count===1?'':'s'):'Todos';
    });
  }

  function updateQuickCards(){
    document.querySelectorAll('[data-quick-filter]').forEach(button=>{
      button.classList.toggle('is-active',button.dataset.quickFilter===quickFilter);
    });
  }

  function matchesColumnFilter(row,column,filter){
    const target=cell(row,column);
    if(!target)return false;
    const raw=normalize(target.dataset.raw);
    const value=normalize(filter.value);
    if(column==='ALM'){
      const count=parseInt(target.dataset.raw||'0',10)||0;
      if(value==='__with__')return count>0;
      if(value==='__without__')return count===0;
    }
    return filter.tagName==='SELECT'?raw===value:raw.includes(value);
  }

  function applyFilters(){
    const global=normalize(search.value);
    const groups=selectedGroups();
    const columnFilters={};
    document.querySelectorAll('.tg-filter:not([data-server-filter])').forEach(filter=>{
      if(normalize(filter.value)!=='')columnFilters[filter.dataset.column]=filter;
    });

    rows().forEach(row=>{
      let show=!global||Array.from(row.cells).some(item=>normalize(item.dataset.raw).includes(global));
      if(show){
        for(const column of Object.keys(columnFilters)){
          if(!matchesColumnFilter(row,column,columnFilters[column])){show=false;break;}
        }
      }
      if(show){
        for(const column of Object.keys(groups)){
          const target=cell(row,column);
          if(!target||!groups[column].includes(normalize(target.dataset.raw))){show=false;break;}
        }
      }
      row.hidden=!show;
    });
    applyPageLimit();
    updateFilterSummaries();
    updateQuickCards();
    window.dispatchEvent(new CustomEvent('clear-grid-visibility-changed'));
  }

  function saveFilters(){
    if(!persistFilters)return;
    const columns={};
    document.querySelectorAll('.tg-filter:not([data-server-filter])').forEach(filter=>{
      if(filter.dataset.column)columns[filter.dataset.column]=filter.value;
    });
    const groups={};
    document.querySelectorAll('.tg-panel').forEach(panel=>{
      groups[panel.dataset.group]=Array.from(panel.querySelectorAll('.tg-multi:checked')).map(input=>input.value);
    });
    localStorage.setItem(filtersKey,JSON.stringify({search:search.value,columns:columns,groups:groups}));
  }

  function loadFilters(){
    if(!persistFilters)return;
    let saved=null;
    try{saved=JSON.parse(localStorage.getItem(filtersKey)||'null');}catch(error){saved=null;}
    if(!saved||typeof saved!=='object')return;
    search.value=typeof saved.search==='string'?saved.search:'';
    if(saved.columns&&typeof saved.columns==='object'){
      document.querySelectorAll('.tg-filter:not([data-server-filter])').forEach(filter=>{
        const value=saved.columns[filter.dataset.column];
        if(typeof value!=='string')return;
        if(filter.tagName==='SELECT'){
          const exists=Array.from(filter.options).some(option=>option.value===value);
          filter.value=exists?value:'';
        }else filter.value=value;
      });
    }
    if(saved.groups&&typeof saved.groups==='object'){
      document.querySelectorAll('.tg-panel').forEach(panel=>{
        const values=Array.isArray(saved.groups[panel.dataset.group])?saved.groups[panel.dataset.group]:[];
        panel.querySelectorAll('.tg-multi').forEach(input=>input.checked=values.includes(input.value));
      });
    }
  }

  function clearFilters(){
    search.value='';
    document.querySelectorAll('.tg-filter').forEach(item=>item.value='');
    document.querySelectorAll('.tg-multi').forEach(item=>item.checked=false);
    quickFilter='';
    if(persistFilters)localStorage.removeItem(filtersKey);
    applyFilters();
  }

  function columnOrder(){return Array.from(table.tHead.rows[0].cells).map(header=>header.dataset.column);}

  function moveColumn(column,beforeColumn){
    const header=table.querySelector('th[data-column="'+attrValue(column)+'"]');
    const before=beforeColumn?table.querySelector('th[data-column="'+attrValue(beforeColumn)+'"]'):null;
    if(!header||header===before)return;
    table.tHead.rows[0].insertBefore(header,before);
    rows().forEach(row=>{
      const current=cell(row,column),target=beforeColumn?cell(row,beforeColumn):null;
      if(current)row.insertBefore(current,target);
    });
  }

  function showColumn(column,show){
    if(column==='__REPORTE')show=true;
    table.querySelectorAll('[data-column="'+attrValue(column)+'"]').forEach(item=>item.style.display=show?'':'none');
  }

  function readState(){
    try{return JSON.parse(localStorage.getItem(storage)||'null');}catch(error){return null;}
  }

  function saveState(){
    const hidden=Array.from(document.querySelectorAll('.tg-col-toggle:not(:checked)')).map(item=>item.value).filter(column=>column!=='__REPORTE');
    localStorage.setItem(storage,JSON.stringify({order:columnOrder(),hidden:hidden}));
  }

  function buildColumnsMenu(hidden){
    const box=document.getElementById('tgColumnsList');
    const hiddenSet=new Set(hidden||[]);
    box.innerHTML='';
    columnOrder().forEach(column=>{
      if(column==='__REPORTE')return;
      const item=document.createElement('div');
      item.className='tg-col-item';
      item.draggable=true;
      item.dataset.column=column;
      const drag=document.createElement('span');
      drag.className='drag';drag.textContent='☰';
      const label=document.createElement('label');
      const checkbox=document.createElement('input');
      checkbox.type='checkbox';checkbox.className='tg-col-toggle';checkbox.value=column;checkbox.checked=!hiddenSet.has(column);
      const text=document.createElement('span');text.textContent=column==='__COMUNICACION'?'COM':column;
      label.append(checkbox,text);item.append(drag,label);box.append(item);
      checkbox.addEventListener('change',()=>{showColumn(column,checkbox.checked);saveState();});
      item.addEventListener('dragstart',()=>dragItem=item);
      item.addEventListener('dragover',event=>event.preventDefault());
      item.addEventListener('drop',event=>{
        event.preventDefault();
        if(dragItem&&dragItem!==item){moveColumn(dragItem.dataset.column,item.dataset.column);box.insertBefore(dragItem,item);saveState();}
      });
    });
  }

  function loadColumns(){
    const state=readState();
    if(state&&Array.isArray(state.order))state.order.forEach(column=>moveColumn(column,null));
    const hidden=state&&Array.isArray(state.hidden)?state.hidden.filter(column=>column!=='__REPORTE'):[];
    hidden.forEach(column=>showColumn(column,false));
    buildColumnsMenu(hidden);
  }

  function sortBy(header){
    const column=header.dataset.column,type=header.dataset.type;
    sortDirection=sortColumn===column?-sortDirection:1;
    sortColumn=column;
    rows().sort((leftRow,rightRow)=>{
      const left=cell(leftRow,column)?.dataset.raw??'';
      const right=cell(rightRow,column)?.dataset.raw??'';
      if(type==='number'){
        const a=parseFloat(String(left).replace(',','.'));
        const b=parseFloat(String(right).replace(',','.'));
        return ((Number.isNaN(a)?-Infinity:a)-(Number.isNaN(b)?-Infinity:b))*sortDirection;
      }
      return String(left).localeCompare(String(right),'es',{numeric:true,sensitivity:'base'})*sortDirection;
    }).forEach(row=>tbody.appendChild(row));
    applyPageLimit();
  }

  function exportExcel(){
    const headers=Array.from(table.tHead.rows[0].cells).filter(header=>header.style.display!=='none'&&header.dataset.column!=='__REPORTE');
    const columns=headers.map(header=>header.dataset.column);
    const labels=headers.map(header=>header.dataset.reportLabel||header.dataset.column);
    const html=value=>String(value??'').replace(/^([=+\-@])/,'\'$1').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/\r?\n/g,' ');
    const body=rows().filter(row=>!row.hidden).map(row=>'<tr>'+columns.map(column=>'<td style="mso-number-format:\'\\@\'">'+html(cell(row,column)?.dataset.raw??'')+'</td>').join('')+'</tr>').join('');
    const documentHtml='<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse;font-family:Arial;font-size:10pt}th{background:#1a596b;color:#fff}th,td{border:1px solid #b9c8ce;padding:5px;text-align:left;vertical-align:top}</style></head><body><h2>'+html(cfg.title||'Telemetría')+'</h2><table><thead><tr>'+labels.map(label=>'<th>'+html(label)+'</th>').join('')+'</tr></thead><tbody>'+body+'</tbody></table></body></html>';
    const blob=new Blob(['\ufeff'+documentHtml],{type:'application/vnd.ms-excel;charset=utf-8'});
    const url=URL.createObjectURL(blob),anchor=document.createElement('a');
    anchor.href=url;anchor.style.display='none';
    anchor.download=(cfg.title||'telemetria').replace(/[^A-Za-z0-9ÁÉÍÓÚÜÑáéíóúüñ_-]+/g,'_')+'_'+new Date().toISOString().slice(0,19).replace(/[:T]/g,'-')+'.xls';
    document.body.appendChild(anchor);anchor.click();anchor.remove();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
  }

  function refreshSchedule(){
    if(timer)clearInterval(timer);
    const interval=parseInt(document.getElementById('tgInterval').value||'0',10);
    localStorage.setItem(refreshKey,String(interval));
    if(interval>0)timer=setInterval(()=>{if(!document.hidden)location.reload();},interval*1000);
  }

  function setQuickFilter(type){
    const column=type==='remote-stop'?(cfg.remoteStopColumn||'AF-ESTADO-PARO-REMOTO'):'__COMUNICACION';
    const filter=document.querySelector('.tg-filter[data-column="'+attrValue(column)+'"]');
    if(!filter)return;
    document.querySelectorAll('.tg-panel[data-group="'+attrValue(column)+'"] .tg-multi').forEach(item=>item.checked=false);
    if(quickFilter===type){quickFilter='';filter.value='';}
    else{
      quickFilter=type;
      filter.value=type==='remote-stop'?'Habilitado':(type==='comm-fail'?'Sin comunicación':'Demorada');
    }
    applyFilters();
    saveFilters();
  }

  search.addEventListener('input',()=>{applyFilters();saveFilters();});
  document.querySelectorAll('.tg-filter').forEach(filter=>{
    filter.addEventListener(filter.tagName==='SELECT'?'change':'input',()=>{
      if(filter.dataset.column==='__COMUNICACION'||filter.dataset.column===cfg.remoteStopColumn)quickFilter='';
      applyFilters();
      saveFilters();
    });
    filter.addEventListener('click',event=>event.stopPropagation());
  });
  document.querySelectorAll('.tg-multi').forEach(input=>input.addEventListener('change',()=>{quickFilter='';applyFilters();saveFilters();}));
  document.querySelectorAll('[data-all]').forEach(button=>button.addEventListener('click',()=>{
    quickFilter='';button.closest('.tg-panel').querySelectorAll('.tg-multi').forEach(item=>item.checked=true);applyFilters();saveFilters();
  }));
  document.querySelectorAll('[data-clear]').forEach(button=>button.addEventListener('click',()=>{
    quickFilter='';button.closest('.tg-panel').querySelectorAll('.tg-multi').forEach(item=>item.checked=false);applyFilters();saveFilters();
  }));
  document.querySelectorAll('[data-quick-filter]').forEach(button=>button.addEventListener('click',()=>setQuickFilter(button.dataset.quickFilter)));
  document.getElementById('tgClear').addEventListener('click',clearFilters);
  document.getElementById('tgExport').addEventListener('click',exportExcel);
  document.getElementById('tgRefresh').addEventListener('click',()=>location.reload());
  const saveFiltersButton=document.getElementById('tgSaveFilters');
  if(saveFiltersButton)saveFiltersButton.addEventListener('click',()=>{
    saveFilters();
    const original=saveFiltersButton.textContent;
    saveFiltersButton.textContent='Filtros guardados';
    setTimeout(()=>{saveFiltersButton.textContent=original;},1400);
  });

  const columnsMenu=document.getElementById('tgColumnsMenu');
  const columnsButton=document.getElementById('tgColumnsBtn');
  function setColumnsMenu(open){columnsMenu.classList.toggle('show',open);columnsButton.setAttribute('aria-expanded',open?'true':'false');}
  columnsButton.addEventListener('click',event=>{event.stopPropagation();setColumnsMenu(!columnsMenu.classList.contains('show'));});
  columnsMenu.addEventListener('click',event=>event.stopPropagation());
  document.addEventListener('click',()=>setColumnsMenu(false));
  document.addEventListener('keydown',event=>{if(event.key==='Escape')setColumnsMenu(false);});
  document.getElementById('tgResetCols').addEventListener('click',()=>{localStorage.removeItem(storage);location.reload();});

  table.querySelectorAll('th[draggable="true"]').forEach(header=>{
    header.addEventListener('dragstart',event=>{if(event.target.closest('input,select')){event.preventDefault();return;}dragHead=header;});
    header.addEventListener('dragover',event=>event.preventDefault());
    header.addEventListener('drop',event=>{
      event.preventDefault();
      if(dragHead&&dragHead!==header){moveColumn(dragHead.dataset.column,header.dataset.column);const state=readState();buildColumnsMenu(state&&state.hidden?state.hidden:[]);saveState();}
    });
    header.addEventListener('click',event=>{if(!event.target.closest('input,select'))sortBy(header);});
  });

  if(pageSizeSelect){
    const allowedPageSizes=['50','100','all'];
    const savedPageSize=localStorage.getItem(pageSizeKey);
    pageSizeSelect.value=allowedPageSizes.includes(savedPageSize)?savedPageSize:'50';
    pageSizeSelect.addEventListener('change',()=>{
      localStorage.setItem(pageSizeKey,pageSizeSelect.value);
      applyPageLimit();
      const wrap=table.closest('.tg-wrap');
      if(wrap)wrap.scrollTop=0;
    });
  }

  const interval=document.getElementById('tgInterval');
  const allowedIntervals=['0','300','600','1800'];
  const savedInterval=localStorage.getItem(refreshKey);
  interval.value=allowedIntervals.includes(savedInterval)?savedInterval:'0';
  interval.addEventListener('change',refreshSchedule);

  loadFilters();

  /*
   * Cuando cambia la zona, el navegador puede conservar el valor anterior
   * del combo BATERIA. Eso hace que la nueva zona quede limitada a una sola
   * batería (por ejemplo CE 04). Se limpia una sola vez después de recargar.
   */
  if(sessionStorage.getItem('clear_reset_zone_battery_filter')==='1'){
    sessionStorage.removeItem('clear_reset_zone_battery_filter');
    const batteryColumn=cfg.batteryColumn||'BATERIA';
    const batteryFilter=document.querySelector('.tg-filter[data-column="'+attrValue(batteryColumn)+'"]');
    if(batteryFilter)batteryFilter.value='';
    document.querySelectorAll('.tg-panel[data-group="'+attrValue(batteryColumn)+'"] .tg-multi').forEach(item=>item.checked=false);
    saveFilters();
  }

  loadColumns();
  refreshSchedule();
  applyFilters();
})();
