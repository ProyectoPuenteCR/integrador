(function(){
  'use strict';
  const cfg=window.CLEAR_TELEMETRY_PAGE||{};
  const table=document.getElementById('table');
  if(!table)return;
  const body=table.tBodies[0];
  const search=document.getElementById('q');
  const visibleCount=document.getElementById('visible');
  const shownCount=document.getElementById('shown');
  const pageSizeSelect=document.getElementById('telPageSize');
  const storage='clear_tel_'+(cfg.key||'telemetry')+'_cols_v317';
  const refreshStorage=storage+'_refresh';
  const pageSizeStorage=storage+'_page_size';
  let timer=null,dragHeader=null,quickFilter='';

  const normalize=value=>String(value??'').trim().toLocaleLowerCase('es');
  const rows=()=>Array.from(body.rows);
  const attrValue=value=>String(value).replace(/\\/g,'\\\\').replace(/"/g,'\\"');
  const cell=(row,column)=>row.querySelector('td[data-col="'+attrValue(column)+'"]');
  const filteredRows=()=>rows().filter(row=>!row.hidden);

  function currentPageSize(){
    if(!pageSizeSelect||pageSizeSelect.value==='all')return Infinity;
    const value=parseInt(pageSizeSelect.value||'50',10);
    return Number.isFinite(value)&&value>0?value:50;
  }

  function applyPageLimit(){
    const matched=filteredRows();
    const limit=currentPageSize();
    rows().forEach(row=>row.classList.remove('tel-page-hidden'));
    matched.forEach((row,index)=>row.classList.toggle('tel-page-hidden',index>=limit));
    if(visibleCount)visibleCount.textContent=String(matched.length);
    if(shownCount)shownCount.textContent=String(Math.min(matched.length,limit));
  }

  function selectedGroups(){
    const output={};
    document.querySelectorAll('.multi:checked').forEach(input=>{
      (output[input.dataset.col]??=[]).push(normalize(input.value));
    });
    return output;
  }

  function updateSummaries(){
    document.querySelectorAll('.tel-grid details').forEach(panel=>{
      const summary=panel.querySelector('summary');
      if(!summary)return;
      const base=summary.dataset.label||summary.textContent.replace(/\s*·.*$/,'').trim();
      summary.dataset.label=base;
      const selected=panel.querySelectorAll('.multi:checked').length;
      summary.textContent=base+(selected?' · '+selected+' seleccionado'+(selected===1?'':'s'):' · selección múltiple');
    });
  }

  function updateQuickCards(){
    document.querySelectorAll('[data-quick-filter]').forEach(button=>button.classList.toggle('is-active',button.dataset.quickFilter===quickFilter));
  }

  function applyFilters(){
    const global=normalize(search.value);
    const groups=selectedGroups();
    const columnFilters={};
    document.querySelectorAll('.tel-filter').forEach(input=>{
      const value=normalize(input.value);
      if(value)columnFilters[input.dataset.col]=value;
    });
    rows().forEach(row=>{
      let show=!global||Array.from(row.cells).some(item=>normalize(item.dataset.raw).includes(global));
      if(show){
        for(const column of Object.keys(groups)){
          const target=cell(row,column);
          if(!target||!groups[column].includes(normalize(target.dataset.raw))){show=false;break;}
        }
      }
      if(show){
        for(const column of Object.keys(columnFilters)){
          const target=cell(row,column);
          if(!target||!normalize(target.dataset.raw).includes(columnFilters[column])){show=false;break;}
        }
      }
      row.hidden=!show;
    });
    applyPageLimit();
    updateSummaries();
    updateQuickCards();
  }

  function clearFilters(){
    search.value='';
    document.querySelectorAll('.multi').forEach(input=>input.checked=false);
    document.querySelectorAll('.tel-filter').forEach(input=>input.value='');
    quickFilter='';
    applyFilters();
  }

  function setQuickFilter(type){
    const input=document.querySelector('.tel-filter[data-col="COMUNICACION"]');
    if(!input)return;
    document.querySelectorAll('.multi[data-col="COMUNICACION"]').forEach(item=>item.checked=false);
    if(quickFilter===type){quickFilter='';input.value='';}
    else{
      quickFilter=type;
      input.value=type==='comm-fail'?'Sin comunicación':'Intermitente';
    }
    applyFilters();
  }

  function exportCsv(){
    const visibleColumns=Array.from(table.tHead.rows[0].cells).filter(header=>header.style.display!=='none').map(header=>header.dataset.col);
    const quote=value=>'"'+String(value??'').replace(/\r?\n/g,' ').replace(/"/g,'""')+'"';
    const lines=[visibleColumns.map(quote).join(';')];
    rows().filter(row=>!row.hidden).forEach(row=>lines.push(visibleColumns.map(column=>quote(cell(row,column)?.dataset.raw??'')).join(';')));
    const url=URL.createObjectURL(new Blob(['\ufeff'+lines.join('\r\n')],{type:'text/csv;charset=utf-8'}));
    const anchor=document.createElement('a');
    anchor.href=url;
    anchor.download=(cfg.key||'telemetria')+'_'+new Date().toISOString().slice(0,19).replace(/[:T]/g,'-')+'.csv';
    anchor.click();
    setTimeout(()=>URL.revokeObjectURL(url),500);
  }

  function schedule(){
    if(timer)clearInterval(timer);
    const interval=document.getElementById('interval');
    localStorage.setItem(refreshStorage,interval.value);
    if(+interval.value)timer=setInterval(()=>{if(!document.hidden)location.reload();},+interval.value*1000);
  }

  function showColumn(column,show){
    table.querySelectorAll('[data-col="'+attrValue(column)+'"]').forEach(item=>item.style.display=show?'':'none');
  }

  function moveColumn(column,beforeColumn){
    const header=table.querySelector('th[data-col="'+attrValue(column)+'"]');
    const before=beforeColumn?table.querySelector('th[data-col="'+attrValue(beforeColumn)+'"]'):null;
    if(!header||header===before)return;
    table.tHead.rows[0].insertBefore(header,before);
    rows().forEach(row=>{
      const current=cell(row,column),target=beforeColumn?cell(row,beforeColumn):null;
      if(current)row.insertBefore(current,target);
    });
  }

  function saveColumns(){
    localStorage.setItem(storage,JSON.stringify(Array.from(table.tHead.rows[0].cells).map(cell=>({column:cell.dataset.col,visible:cell.style.display!=='none'}))));
  }

  function buildColumnMenu(){
    const menu=document.getElementById('colmenu');
    menu.innerHTML='';
    Array.from(table.tHead.rows[0].cells).forEach(header=>{
      const label=document.createElement('label');
      const checkbox=document.createElement('input');
      checkbox.type='checkbox';checkbox.checked=header.style.display!=='none';
      checkbox.addEventListener('change',()=>{showColumn(header.dataset.col,checkbox.checked);saveColumns();});
      label.append(checkbox,' '+(header.dataset.col==='COMUNICACION'?'COM':header.dataset.col));
      menu.append(label);
    });
  }

  function loadColumns(){
    try{
      const saved=JSON.parse(localStorage.getItem(storage)||'[]');
      saved.forEach(item=>moveColumn(item.column,null));
      saved.forEach(item=>showColumn(item.column,item.visible));
    }catch(error){}
  }

  search.addEventListener('input',applyFilters);
  document.querySelectorAll('.tel-filter').forEach(input=>input.addEventListener('input',()=>{
    if(input.dataset.col==='COMUNICACION')quickFilter='';
    applyFilters();
  }));
  document.querySelectorAll('.multi').forEach(input=>input.addEventListener('change',()=>{quickFilter='';applyFilters();}));
  document.querySelectorAll('[data-quick-filter]').forEach(button=>button.addEventListener('click',()=>setQuickFilter(button.dataset.quickFilter)));
  document.getElementById('clear').addEventListener('click',clearFilters);
  document.getElementById('refresh').addEventListener('click',()=>location.reload());
  document.getElementById('export').addEventListener('click',exportCsv);

  if(pageSizeSelect){
    const allowedPageSizes=['50','100','all'];
    const savedPageSize=localStorage.getItem(pageSizeStorage);
    pageSizeSelect.value=allowedPageSizes.includes(savedPageSize)?savedPageSize:'50';
    pageSizeSelect.addEventListener('change',()=>{
      localStorage.setItem(pageSizeStorage,pageSizeSelect.value);
      applyPageLimit();
      const wrap=table.closest('.tel-wrap');
      if(wrap)wrap.scrollTop=0;
    });
  }

  const interval=document.getElementById('interval');
  interval.value=localStorage.getItem(refreshStorage)||'0';
  interval.addEventListener('change',schedule);
  schedule();

  const menu=document.getElementById('colmenu');
  document.getElementById('colbtn').addEventListener('click',event=>{event.stopPropagation();menu.classList.toggle('open');buildColumnMenu();});
  menu.addEventListener('click',event=>event.stopPropagation());
  document.addEventListener('click',()=>menu.classList.remove('open'));
  loadColumns();

  Array.from(table.tHead.rows[0].cells).forEach(header=>{
    header.addEventListener('dragstart',event=>{if(event.target.closest('input')){event.preventDefault();return;}dragHeader=header;});
    header.addEventListener('dragover',event=>event.preventDefault());
    header.addEventListener('drop',event=>{
      event.preventDefault();
      if(!dragHeader||dragHeader===header)return;
      const draggedColumn=dragHeader.dataset.col;
      const targetColumn=header.dataset.col;
      const moveAfter=dragHeader.cellIndex<header.cellIndex;
      table.tHead.rows[0].insertBefore(dragHeader,moveAfter?header.nextSibling:header);
      rows().forEach(row=>{
        const draggedCell=cell(row,draggedColumn),targetCell=cell(row,targetColumn);
        if(draggedCell&&targetCell)row.insertBefore(draggedCell,moveAfter?targetCell.nextSibling:targetCell);
      });
      saveColumns();
    });
  });

  applyFilters();
})();
