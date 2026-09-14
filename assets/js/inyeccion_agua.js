(function(){
  'use strict';

  const cfg=window.CLEAR_INYECCION_AGUA||{};
  const table=document.getElementById('iaTable');
  if(!table)return;

  const tbody=table.tBodies[0];
  const search=document.getElementById('iaSearch');
  const visible=document.getElementById('iaVisible');
  const shown=document.getElementById('iaShown');
  const pageSizeSelect=document.getElementById('iaPageSize');
  const storage='clear_inyeccion_agua_columns_v325';
  /* Clave nueva: no reutiliza intervalos agresivos de versiones anteriores. */
  const refreshStorage='clear_inyeccion_agua_refresh_safe_v2';
  const pageSizeStorage='clear_inyeccion_agua_page_size_v326';
  const pressureColumn=cfg.pressureColumn||'Presión Inyeccion';
  const comboColumns=['PLANTA','SATELITE','ZONA'];
  const emptyComboValue='__IA_EMPTY__';
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
  const raw=(row,column)=>String(cell(row,column)?.dataset.raw??'').trim();

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

  function combo(column){
    return table.querySelector('.ia-column-filter[data-column="'+attrValue(column)+'"]');
  }

  function decodeComboValue(value){
    if(value==='')return null;
    return value===emptyComboValue?'':String(value);
  }

  function comboSelected(column){
    const select=combo(column);
    return select?decodeComboValue(String(select.value??'')):null;
  }

  function setComboOptions(select,sourceRows,selectedValue){
    if(!select)return;
    const counts=new Map();
    const column=select.dataset.column;

    sourceRows.forEach(row=>{
      const value=raw(row,column);
      counts.set(value,(counts.get(value)||0)+1);
    });

    const fragment=document.createDocumentFragment();
    const all=document.createElement('option');
    all.value='';
    all.textContent='Todos';
    fragment.appendChild(all);

    Array.from(counts.keys())
      .sort((a,b)=>a.localeCompare(b,'es',{numeric:true,sensitivity:'base'}))
      .forEach(value=>{
        const option=document.createElement('option');
        option.value=value===''?emptyComboValue:value;
        option.textContent=(value===''?'Sin dato':value)+' ('+counts.get(value)+')';
        fragment.appendChild(option);
      });

    select.replaceChildren(fragment);
    const encoded=selectedValue===null?'':(selectedValue===''?emptyComboValue:selectedValue);
    select.value=Array.from(select.options).some(option=>option.value===encoded)?encoded:'';
  }

  function ensureHeaderCombos(){
    comboColumns.forEach(column=>{
      const header=table.querySelector('th[data-column="'+attrValue(column)+'"]');
      if(!header)return;

      const previous=header.querySelector('.ia-column-filter');
      const selected=previous?decodeComboValue(String(previous.value??'')):null;
      let select=previous;

      if(!select||select.tagName!=='SELECT'){
        select=document.createElement('select');
        select.className='ia-column-filter ia-column-select';
        select.dataset.column=column;
        select.dataset.filterMode='exact';
        select.setAttribute('aria-label','Filtrar por '+column);
        if(previous)previous.replaceWith(select);
        else header.appendChild(select);
      }

      setComboOptions(select,rows(),selected);
    });
  }

  function selectedGroups(){
    const output={};
    document.querySelectorAll('.ia-multi:checked').forEach(input=>{
      (output[input.dataset.column]??=[]).push(normalize(input.value));
    });
    return output;
  }

  function rowMatchesParent(row,column,groups){
    const selected=comboSelected(column);
    const target=raw(row,column);
    if(selected!==null&&normalize(target)!==normalize(selected))return false;
    const groupValues=groups[column]||[];
    if(groupValues.length&&!groupValues.includes(normalize(target)))return false;
    return true;
  }

  function refreshDependentCombos(changedColumn){
    const groups=selectedGroups();
    const satellite=combo('SATELITE');
    const zone=combo('ZONA');

    if(changedColumn==='PLANTA'||changedColumn==='ALL'){
      const previousSatellite=comboSelected('SATELITE');
      const satelliteRows=rows().filter(row=>rowMatchesParent(row,'PLANTA',groups));
      setComboOptions(satellite,satelliteRows,previousSatellite);
    }

    if(changedColumn==='PLANTA'||changedColumn==='SATELITE'||changedColumn==='ALL'){
      const previousZone=comboSelected('ZONA');
      const zoneRows=rows().filter(row=>
        rowMatchesParent(row,'PLANTA',groups)&&
        rowMatchesParent(row,'SATELITE',groups)
      );
      setComboOptions(zone,zoneRows,previousZone);
    }
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

  function currentPageSize(){
    if(!pageSizeSelect||pageSizeSelect.value==='all')return Infinity;
    const value=parseInt(pageSizeSelect.value||'50',10);
    return Number.isFinite(value)&&value>0?value:50;
  }

  function applyPageLimit(){
    const matched=visibleRows();
    const limit=currentPageSize();
    rows().forEach(row=>row.classList.remove('ia-page-hidden'));
    matched.forEach((row,index)=>row.classList.toggle('ia-page-hidden',index>=limit));
    if(visible)visible.textContent=String(matched.length);
    if(shown)shown.textContent=String(Math.min(matched.length,limit));
  }

  function updateSummary(){
    const filtered=visibleRows();
    const totals={};
    (cfg.sumColumns||[]).forEach(column=>totals[column]=0);
    let negatives=0;

    filtered.forEach(row=>{
      (cfg.sumColumns||[]).forEach(column=>{
        const number=parseNumber(raw(row,column));
        if(Number.isFinite(number))totals[column]+=number;
      });
      const pressure=parseNumber(raw(row,pressureColumn));
      if(Number.isFinite(pressure)&&pressure<0)negatives++;
    });

    const values={
      'Caudal Inst':['iaKpiCaudal','iaSummaryCaudal'],
      'Acumulado Hoy':['iaKpiAcumulado','iaSummaryAcumulado'],
      'Cierre':['iaKpiCierre','iaSummaryCierre']
    };
    Object.keys(values).forEach(column=>{
      values[column].forEach(id=>{
        const element=document.getElementById(id);
        if(element)element.textContent=formatNumber(totals[column]||0);
      });
    });

    ['iaKpiNegative','iaSummaryNegative'].forEach(id=>{
      const element=document.getElementById(id);
      if(element)element.textContent=String(negatives);
    });
    const records=document.getElementById('iaKpiRecords');
    if(records)records.textContent=String(filtered.length);
  }

  function applyFilters(){
    const global=normalize(search.value);
    const groups=selectedGroups();
    const columnFilters={};

    document.querySelectorAll('.ia-column-filter').forEach(filter=>{
      const rawValue=String(filter.value??'');
      if(rawValue!==''){
        columnFilters[filter.dataset.column]={
          value:rawValue===emptyComboValue?'':normalize(rawValue),
          exact:filter.dataset.filterMode==='exact',
          empty:rawValue===emptyComboValue
        };
      }
    });

    rows().forEach(row=>{
      let show=!global||Array.from(row.cells).some(item=>normalize(item.dataset.raw).includes(global));

      if(show){
        for(const column of Object.keys(groups)){
          const target=normalize(raw(row,column));
          if(!groups[column].includes(target)){show=false;break;}
        }
      }

      if(show){
        for(const column of Object.keys(columnFilters)){
          const target=normalize(raw(row,column));
          const filter=columnFilters[column];
          const matches=filter.empty?target==='':(filter.exact?target===filter.value:target.includes(filter.value));
          if(!matches){show=false;break;}
        }
      }

      if(show&&quickNegative){
        const pressure=parseNumber(raw(row,pressureColumn));
        show=Number.isFinite(pressure)&&pressure<0;
      }

      row.hidden=!show;
    });

    const card=document.getElementById('iaNegativeCard');
    if(card)card.classList.toggle('is-active',quickNegative);
    updatePanelSummaries();
    updateSummary();
    applyPageLimit();
  }

  function clearFilters(){
    search.value='';
    document.querySelectorAll('.ia-multi').forEach(input=>input.checked=false);
    document.querySelectorAll('.ia-column-filter').forEach(input=>input.value='');
    quickNegative=false;
    refreshDependentCombos('ALL');
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
      const left=raw(leftRow,column);
      const right=raw(rightRow,column);
      if(type==='number'){
        const a=parseNumber(left),b=parseNumber(right);
        const safeA=Number.isFinite(a)?a:-Infinity;
        const safeB=Number.isFinite(b)?b:-Infinity;
        return (safeA-safeB)*sortDirection;
      }
      return String(left).localeCompare(String(right),'es',{numeric:true,sensitivity:'base'})*sortDirection;
    }).forEach(row=>tbody.appendChild(row));
    applyPageLimit();
  }

  function exportCsv(){
    const headers=Array.from(table.tHead.rows[0].cells).filter(header=>header.style.display!=='none');
    const columns=headers.map(header=>header.dataset.column);
    const quote=value=>'"'+String(value??'').replace(/\r?\n/g,' ').replace(/"/g,'""')+'"';
    const lines=[columns.map(quote).join(';')];
    visibleRows().forEach(row=>lines.push(columns.map(column=>quote(raw(row,column))).join(';')));
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

  const satelliteModal=document.getElementById('iaSatelliteModal');
  const satelliteBody=document.getElementById('iaSatelliteBody');
  const satelliteTable=document.getElementById('iaSatelliteTable');
  const satelliteExportButton=document.getElementById('iaSatelliteExport');
  let satelliteReturnFocus=null;
  let activeSatellite='';
  let activePlant='';

  function createModalCell(value,className){
    const td=document.createElement('td');
    if(className)td.className=className;
    td.textContent=value;
    return td;
  }

  function setModalTotal(ids,value){
    ids.forEach(id=>{
      const element=document.getElementById(id);
      if(element)element.textContent=formatNumber(value);
    });
  }

  function openSatelliteModal(trigger){
    if(!satelliteModal||!satelliteBody)return;
    const satellite=String(trigger.dataset.satellite??'').trim();
    const plant=String(trigger.dataset.plant??'').trim();
    const satelliteRows=rows()
      .filter(row=>normalize(raw(row,'SATELITE'))===normalize(satellite)&&normalize(raw(row,'PLANTA'))===normalize(plant))
      .sort((a,b)=>raw(a,'POZO').localeCompare(raw(b,'POZO'),'es',{numeric:true,sensitivity:'base'}));

    const totals={'Caudal Inst':0,'Acumulado Hoy':0,'Proyectado':0,'Cierre':0};
    satelliteBody.innerHTML='';

    satelliteRows.forEach(row=>{
      const tr=document.createElement('tr');
      const pressure=parseNumber(raw(row,'Presión Inyeccion'));
      if(Number.isFinite(pressure)&&pressure<0)tr.classList.add('is-negative');

      tr.appendChild(createModalCell(raw(row,'POZO')));

      const alarmCell=document.createElement('td');
      alarmCell.className='ia-satellite-alarm-cell';
      const sourceBell=cell(row,'POZO')?.querySelector('.ia-alarm-bell');
      if(sourceBell)alarmCell.appendChild(sourceBell.cloneNode(true));
      else alarmCell.textContent='0';
      const sourceComment=cell(row,'POZO')?.querySelector('[data-alarm-comment="true"]');
      if(sourceComment)alarmCell.appendChild(sourceComment.cloneNode(true));
      tr.appendChild(alarmCell);

      tr.appendChild(createModalCell(raw(row,'ZONA')));
      tr.appendChild(createModalCell(formatNumber(pressure),'num'));

      ['Caudal Inst','Acumulado Hoy','Proyectado','Cierre'].forEach(column=>{
        const value=parseNumber(raw(row,column));
        if(Number.isFinite(value))totals[column]+=value;
        tr.appendChild(createModalCell(formatNumber(value),'num'));
      });

      const screenCell=document.createElement('td');
      const screenUrl=raw(row,'PANTALLA');
      if(/^https?:\/\//i.test(screenUrl)){
        const link=document.createElement('a');
        link.className='ia-satellite-screen-link';
        link.href=screenUrl;
        link.target='_blank';
        link.rel='noopener';
        link.textContent='Abrir';
        link.title='Abrir pantalla del pozo';
        screenCell.appendChild(link);
      }
      tr.appendChild(screenCell);
      satelliteBody.appendChild(tr);
    });

    const title=document.getElementById('iaSatelliteTitle');
    const subtitle=document.getElementById('iaSatelliteSubtitle');
    const count=document.getElementById('iaSatelliteWellCount');
    if(title)title.textContent='Satélite '+satellite;
    if(subtitle)subtitle.textContent=plant?'Planta '+plant:'Detalle del satélite';
    if(count)count.textContent=String(satelliteRows.length);

    setModalTotal(['iaSatelliteTotalCaudal','iaSatelliteCardCaudal'],totals['Caudal Inst']);
    setModalTotal(['iaSatelliteTotalAcumulado','iaSatelliteCardAcumulado'],totals['Acumulado Hoy']);
    setModalTotal(['iaSatelliteTotalProyectado','iaSatelliteCardProyectado'],totals['Proyectado']);
    setModalTotal(['iaSatelliteTotalCierre','iaSatelliteCardCierre'],totals['Cierre']);

    activeSatellite=satellite;
    activePlant=plant;
    satelliteReturnFocus=trigger;
    satelliteModal.hidden=false;
    satelliteModal.setAttribute('aria-hidden','false');
    document.body.classList.add('ia-satellite-modal-open');
    satelliteModal.querySelector('.ia-satellite-dialog__close')?.focus();
  }

  function closeSatelliteModal(){
    if(!satelliteModal||satelliteModal.hidden)return;
    satelliteModal.hidden=true;
    satelliteModal.setAttribute('aria-hidden','true');
    document.body.classList.remove('ia-satellite-modal-open');
    satelliteReturnFocus?.focus();
    satelliteReturnFocus=null;
    activeSatellite='';
    activePlant='';
  }

  function escapeExcelHtml(value){
    return String(value??'')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;');
  }

  function exportSatelliteExcel(){
    if(!satelliteTable||!activeSatellite)return;
    const cloned=satelliteTable.cloneNode(true);
    cloned.querySelectorAll('a,button').forEach(element=>{
      const text=String(element.textContent??'').trim();
      element.replaceWith(document.createTextNode(text));
    });
    cloned.querySelectorAll('svg').forEach(svg=>svg.remove());

    const generated=new Intl.DateTimeFormat('es-AR',{dateStyle:'short',timeStyle:'medium'}).format(new Date());
    const title='Inyección de Agua · Satélite '+activeSatellite;
    const subtitle=(activePlant?'Planta '+activePlant+' · ':'')+'Exportado '+generated;
    const html='<!doctype html><html><head><meta charset="utf-8">'+
      '<style>body{font-family:Arial,sans-serif;color:#17313d}h1{font-size:20px;margin:0 0 5px}p{font-size:12px;margin:0 0 15px;color:#536b75}table{border-collapse:collapse;width:100%}th,td{border:1px solid #9fb0b8;padding:6px 8px;font-size:11px}thead th,tfoot th{background:#eaf1f4;font-weight:700}.num{text-align:right}</style></head><body>'+ 
      '<h1>'+escapeExcelHtml(title)+'</h1><p>'+escapeExcelHtml(subtitle)+'</p>'+cloned.outerHTML+'</body></html>';
    const blob=new Blob(['﻿'+html],{type:'application/vnd.ms-excel;charset=utf-8'});
    const url=URL.createObjectURL(blob);
    const anchor=document.createElement('a');
    const safe=value=>String(value||'').trim().replace(/[^a-z0-9_-]+/gi,'_').replace(/^_+|_+$/g,'');
    anchor.href=url;
    anchor.download='inyeccion_agua_'+safe(activePlant)+'_'+safe(activeSatellite)+'_'+new Date().toISOString().slice(0,19).replace(/[:T]/g,'-')+'.xls';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(()=>URL.revokeObjectURL(url),500);
  }

  ensureHeaderCombos();
  refreshDependentCombos('ALL');

  search.addEventListener('input',applyFilters);
  document.querySelectorAll('.ia-column-filter').forEach(filter=>{
    const eventName=filter.tagName==='SELECT'?'change':'input';
    filter.addEventListener(eventName,()=>{
      if(filter.dataset.column==='PLANTA')refreshDependentCombos('PLANTA');
      else if(filter.dataset.column==='SATELITE')refreshDependentCombos('SATELITE');
      applyFilters();
    });
    filter.addEventListener('click',event=>event.stopPropagation());
  });

  document.querySelectorAll('.ia-multi').forEach(input=>input.addEventListener('change',()=>{
    if(input.dataset.column==='PLANTA')refreshDependentCombos('PLANTA');
    else if(input.dataset.column==='SATELITE')refreshDependentCombos('SATELITE');
    applyFilters();
  }));

  document.querySelectorAll('[data-all]').forEach(button=>button.addEventListener('click',()=>{
    const panel=button.closest('.ia-panel');
    panel.querySelectorAll('.ia-multi').forEach(input=>input.checked=true);
    const group=panel.dataset.group;
    if(group==='PLANTA')refreshDependentCombos('PLANTA');
    else if(group==='SATELITE')refreshDependentCombos('SATELITE');
    applyFilters();
  }));

  document.querySelectorAll('[data-clear]').forEach(button=>button.addEventListener('click',()=>{
    const panel=button.closest('.ia-panel');
    panel.querySelectorAll('.ia-multi').forEach(input=>input.checked=false);
    const group=panel.dataset.group;
    if(group==='PLANTA')refreshDependentCombos('PLANTA');
    else if(group==='SATELITE')refreshDependentCombos('SATELITE');
    applyFilters();
  }));

  document.querySelectorAll('.ia-satellite-link').forEach(button=>{
    button.addEventListener('click',event=>{
      event.stopPropagation();
      openSatelliteModal(button);
    });
  });
  satelliteModal?.querySelectorAll('[data-ia-satellite-close]').forEach(element=>element.addEventListener('click',closeSatelliteModal));
  satelliteExportButton?.addEventListener('click',exportSatelliteExcel);
  document.addEventListener('keydown',event=>{
    if(event.key==='Escape'&&satelliteModal&&!satelliteModal.hidden)closeSatelliteModal();
  });

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
    header.addEventListener('click',event=>{if(!event.target.closest('input,select,button,a'))sortBy(header);});
  });

  if(pageSizeSelect){
    const allowedPageSizes=['50','100','all'];
    const savedPageSize=localStorage.getItem(pageSizeStorage);
    pageSizeSelect.value=allowedPageSizes.includes(savedPageSize)?savedPageSize:'50';
    pageSizeSelect.addEventListener('change',()=>{
      localStorage.setItem(pageSizeStorage,pageSizeSelect.value);
      applyPageLimit();
      const wrap=table.closest('.ia-table-wrap');
      if(wrap)wrap.scrollTop=0;
    });
  }

  const interval=document.getElementById('iaInterval');
  const allowed=['0','300','600','1200','1800'];
  const saved=localStorage.getItem(refreshStorage);
  interval.value=allowed.includes(saved)?saved:'0';
  interval.addEventListener('change',scheduleRefresh);

  loadColumns();
  scheduleRefresh();
  applyFilters();
})();
