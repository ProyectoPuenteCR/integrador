(function(){
  'use strict';

  const cfg=window.CLEAR_INYECCION_AGUA||{};
  const table=document.getElementById('iaTable');
  if(!table)return;

  const tbody=table.tBodies[0];
  const search=document.getElementById('iaSearch');
  const visible=document.getElementById('iaVisible');
  const storage='clear_inyeccion_agua_columns_v321';
  const refreshStorage='clear_inyeccion_agua_refresh_v321';
  const pressureColumn=cfg.pressureColumn||'Presión Inyeccion';
  let quickNegative=false;
  let dragHeader=null;
  let dragMenuItem=null;
  let sortColumn='';
  let sortDirection=1;
  let timer=null;

  const rows=()=>Array.from(tbody.rows);
  const normalize=value=>String(value??'').trim().toLocaleLowerCase('es');
  const attrValue=value=>String(value).replace(/\\/g,'\\\\').replace(/"/g,'\\"');
  const cell=(row,column)=>row.querySelector('td[data-column="'+attrValue(column)+'"]');

  function parseNumber(value){
    let text=String(value??'').trim().replace(/\s+/g,'');
    if(!text)return NaN;
    if(text.includes(',')&&text.includes('.')){
      if(text.lastIndexOf(',')>text.lastIndexOf('.'))text=text.replace(/\./g,'').replace(',','.');
      else text=text.replace(/,/g,'');
    }else if(text.includes(','))text=text.replace(',','.');
    const number=Number(text);
    return Number.isFinite(number)?number:NaN;
  }

  function formatNumber(value){
    let number=Number.isFinite(value)?value:0;
    if(Math.abs(number)<0.005)number=0;
    return new Intl.NumberFormat('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(number);
  }

  function selectedGroups(){
    const output={};
    document.querySelectorAll('.ia-multi:checked').forEach(input=>{
      (output[input.dataset.column]??=[]).push(normalize(input.value));
    });
    return output;
  }

  function updatePanelSummaries(){
    document.querySelectorAll('.ia-panel').forEach(panel=>{
      const summary=panel.querySelector('summary');
      if(!summary)return;
      const base=summary.dataset.baseLabel||summary.textContent.trim();
      const count=panel.querySelectorAll('.ia-multi:checked').length;
      const small=summary.querySelector('small');
      if(small)small.textContent=count?(count+' seleccionado'+(count===1?'':'s')):'Todos';
      else summary.innerHTML=base+' <small>'+(count?count+' seleccionados':'Todos')+'</small>';
    });
  }

  function visibleRows(){return rows().filter(row=>!row.hidden);}

  function updateSummary(){
    const filtered=visibleRows();
    const totals={};
    (cfg.sumColumns||[]).forEach(column=>totals[column]=0);
    let negatives=0;

    filtered.forEach(row=>{
      (cfg.sumColumns||[]).forEach(column=>{
        const number=parseNumber(cell(row,column)?.dataset.raw??'');
        if(Number.isFinite(number))totals[column]+=number;
      });
      const pressure=parseNumber(cell(row,pressureColumn)?.dataset.raw??'');
      if(Number.isFinite(pressure)&&pressure<0)negatives++;
    });

    const values={
      'Caudal Inst': ['iaKpiCaudal','iaSummaryCaudal'],
      'Acumulado Hoy': ['iaKpiAcumulado','iaSummaryAcumulado'],
      'Cierre': ['iaKpiCierre','iaSummaryCierre']
    };
    Object.keys(values).forEach(column=>{
      values[column].forEach(id=>{const element=document.getElementById(id);if(element)element.textContent=formatNumber(totals[column]||0);});
    });

    ['iaKpiNegative','iaSummaryNegative'].forEach(id=>{const element=document.getElementById(id);if(element)element.textContent=String(negatives);});
    const records=document.getElementById('iaKpiRecords');
    if(records)records.textContent=String(filtered.length);
    visible.textContent=String(filtered.length);
  }

  function applyFilters(){
    const global=normalize(search.value);
    const groups=selectedGroups();
    const columnFilters={};
    document.querySelectorAll('.ia-column-filter').forEach(filter=>{
      const value=normalize(filter.value);
      if(value){
        columnFilters[filter.dataset.column]={
          value:value,
          exact:filter.dataset.filterMode==='exact'
        };
      }
    });

    rows().forEach(row=>{
      let show=!global||Array.from(row.cells).some(item=>normalize(item.dataset.raw).includes(global));

      if(show){
        for(const column of Object.keys(groups)){
          const target=normalize(cell(row,column)?.dataset.raw??'');
          if(!groups[column].includes(target)){show=false;break;}
        }
      }

      if(show){
        for(const column of Object.keys(columnFilters)){
          const target=normalize(cell(row,column)?.dataset.raw??'');
          const filter=columnFilters[column];
          const matches=filter.exact?target===filter.value:target.includes(filter.value);
          if(!matches){show=false;break;}
        }
      }

      if(show&&quickNegative){
        const pressure=parseNumber(cell(row,pressureColumn)?.dataset.raw??'');
        show=Number.isFinite(pressure)&&pressure<0;
      }

      row.hidden=!show;
    });

    const card=document.getElementById('iaNegativeCard');
    if(card)card.classList.toggle('is-active',quickNegative);
    updatePanelSummaries();
    updateSummary();
  }

  function clearFilters(){
    search.value='';
    document.querySelectorAll('.ia-multi').forEach(input=>input.checked=false);
    document.querySelectorAll('.ia-column-filter').forEach(input=>input.value='');
    quickNegative=false;
    applyFilters();
  }

  function toggleNegative(){
    quickNegative=!quickNegative;
    applyFilters();
    window.setTimeout(()=>document.getElementById('iaSummary')?.scrollIntoView({behavior:'smooth',block:'center'}),80);
  }

  function columnOrder(){return Array.from(table.tHead.rows[0].cells).map(header=>header.dataset.column);}

  function moveColumn(column,beforeColumn){
    const header=table.querySelector('th[data-column="'+attrValue(column)+'"]');
    const before=beforeColumn?table.querySelector('th[data-column="'+attrValue(beforeColumn)+'"]'):null;
    if(!header||header===before)return;
    table.tHead.rows[0].insertBefore(header,before);
    rows().forEach(row=>{
      const current=cell(row,column);
      const target=beforeColumn?cell(row,beforeColumn):null;
      if(current)row.insertBefore(current,target);
    });
  }

  function showColumn(column,show){
    table.querySelectorAll('[data-column="'+attrValue(column)+'"]').forEach(item=>item.style.display=show?'':'none');
  }

  function readState(){
    try{return JSON.parse(localStorage.getItem(storage)||'null');}catch(error){return null;}
  }

  function saveState(){
    const hidden=Array.from(document.querySelectorAll('.ia-col-toggle:not(:checked)')).map(item=>item.value);
    localStorage.setItem(storage,JSON.stringify({order:columnOrder(),hidden:hidden}));
  }

  function buildColumnsMenu(hidden){
    const box=document.getElementById('iaColumnsList');
    const hiddenSet=new Set(hidden||[]);
    box.innerHTML='';

    columnOrder().forEach(column=>{
      const item=document.createElement('div');
      item.className='ia-col-item';
      item.draggable=true;
      item.dataset.column=column;

      const drag=document.createElement('span');
      drag.className='drag';
      drag.textContent='☰';

      const label=document.createElement('label');
      const checkbox=document.createElement('input');
      checkbox.type='checkbox';
      checkbox.className='ia-col-toggle';
      checkbox.value=column;
      checkbox.checked=!hiddenSet.has(column);
      const text=document.createElement('span');
      text.textContent=column;
      label.append(checkbox,text);
      item.append(drag,label);
      box.append(item);

      checkbox.addEventListener('change',()=>{showColumn(column,checkbox.checked);saveState();});
      item.addEventListener('dragstart',()=>dragMenuItem=item);
      item.addEventListener('dragover',event=>event.preventDefault());
      item.addEventListener('drop',event=>{
        event.preventDefault();
        if(dragMenuItem&&dragMenuItem!==item){
          moveColumn(dragMenuItem.dataset.column,item.dataset.column);
          box.insertBefore(dragMenuItem,item);
          saveState();
        }
      });
    });
  }

  function loadColumns(){
    const state=readState();
    if(state&&Array.isArray(state.order))state.order.forEach(column=>moveColumn(column,null));
    const hidden=state&&Array.isArray(state.hidden)?state.hidden:[];
    hidden.forEach(column=>showColumn(column,false));
    buildColumnsMenu(hidden);
  }

  function sortBy(header){
    const column=header.dataset.column;
    const type=header.dataset.type;
    sortDirection=sortColumn===column?-sortDirection:1;
    sortColumn=column;

    rows().sort((leftRow,rightRow)=>{
      const left=cell(leftRow,column)?.dataset.raw??'';
      const right=cell(rightRow,column)?.dataset.raw??'';
      if(type==='number'){
        const a=parseNumber(left),b=parseNumber(right);
        const safeA=Number.isFinite(a)?a:-Infinity;
        const safeB=Number.isFinite(b)?b:-Infinity;
        return (safeA-safeB)*sortDirection;
      }
      return String(left).localeCompare(String(right),'es',{numeric:true,sensitivity:'base'})*sortDirection;
    }).forEach(row=>tbody.appendChild(row));
  }

  function exportCsv(){
    const headers=Array.from(table.tHead.rows[0].cells).filter(header=>header.style.display!=='none');
    const columns=headers.map(header=>header.dataset.column);
    const quote=value=>'"'+String(value??'').replace(/\r?\n/g,' ').replace(/"/g,'""')+'"';
    const lines=[columns.map(quote).join(';')];
    visibleRows().forEach(row=>lines.push(columns.map(column=>quote(cell(row,column)?.dataset.raw??'')).join(';')));
    const blob=new Blob(['\ufeff'+lines.join('\r\n')],{type:'text/csv;charset=utf-8'});
    const url=URL.createObjectURL(blob);
    const anchor=document.createElement('a');
    anchor.href=url;
    anchor.download='inyeccion_agua_'+new Date().toISOString().slice(0,19).replace(/[:T]/g,'-')+'.csv';
    anchor.click();
    window.setTimeout(()=>URL.revokeObjectURL(url),500);
  }

  function scheduleRefresh(){
    if(timer)clearInterval(timer);
    const interval=document.getElementById('iaInterval');
    const seconds=parseInt(interval.value||'0',10);
    localStorage.setItem(refreshStorage,String(seconds));
    if(seconds>0)timer=setInterval(()=>{if(!document.hidden)location.reload();},seconds*1000);
  }

  search.addEventListener('input',applyFilters);
  document.querySelectorAll('.ia-column-filter').forEach(filter=>{
    const eventName=filter.tagName==='SELECT'?'change':'input';
    filter.addEventListener(eventName,applyFilters);
    filter.addEventListener('click',event=>event.stopPropagation());
  });
  document.querySelectorAll('.ia-multi').forEach(input=>input.addEventListener('change',applyFilters));
  document.querySelectorAll('[data-all]').forEach(button=>button.addEventListener('click',()=>{
    button.closest('.ia-panel').querySelectorAll('.ia-multi').forEach(input=>input.checked=true);
    applyFilters();
  }));
  document.querySelectorAll('[data-clear]').forEach(button=>button.addEventListener('click',()=>{
    button.closest('.ia-panel').querySelectorAll('.ia-multi').forEach(input=>input.checked=false);
    applyFilters();
  }));

  document.getElementById('iaNegativeCard')?.addEventListener('click',toggleNegative);
  document.getElementById('iaClear').addEventListener('click',clearFilters);
  document.getElementById('iaExport').addEventListener('click',exportCsv);
  document.getElementById('iaRefresh').addEventListener('click',()=>location.reload());

  const columnsMenu=document.getElementById('iaColumnsMenu');
  document.getElementById('iaColumnsBtn').addEventListener('click',event=>{event.stopPropagation();columnsMenu.classList.toggle('show');});
  columnsMenu.addEventListener('click',event=>event.stopPropagation());
  document.addEventListener('click',()=>columnsMenu.classList.remove('show'));
  document.getElementById('iaResetCols').addEventListener('click',()=>{localStorage.removeItem(storage);location.reload();});

  table.querySelectorAll('th[draggable="true"]').forEach(header=>{
    header.addEventListener('dragstart',event=>{
      if(event.target.closest('input,select')){event.preventDefault();return;}
      dragHeader=header;
    });
    header.addEventListener('dragover',event=>event.preventDefault());
    header.addEventListener('drop',event=>{
      event.preventDefault();
      if(dragHeader&&dragHeader!==header){
        moveColumn(dragHeader.dataset.column,header.dataset.column);
        const state=readState();
        buildColumnsMenu(state&&state.hidden?state.hidden:[]);
        saveState();
      }
    });
    header.addEventListener('click',event=>{if(!event.target.closest('input,select'))sortBy(header);});
  });

  const interval=document.getElementById('iaInterval');
  const allowed=['60','300','600','1200','1800'];
  const saved=localStorage.getItem(refreshStorage);
  interval.value=allowed.includes(saved)?saved:'1200';
  interval.addEventListener('change',scheduleRefresh);

  loadColumns();
  scheduleRefresh();
  applyFilters();
})();
