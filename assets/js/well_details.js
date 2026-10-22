(function(){
  'use strict';
  const cfg=window.CLEAR_TELEMETRY_GRID||{};
  if(cfg.key!=='monitoreo_pozos')return;
  const table=document.getElementById('tgTable');
  if(!table||!table.tBodies[0])return;

  const dialog=document.createElement('dialog');
  dialog.className='well-details';
  dialog.setAttribute('aria-labelledby','wellDetailsTitle');
  dialog.innerHTML='<header class="well-details__head"><div><div class="well-details__eyebrow">Ficha del pozo</div><h2 id="wellDetailsTitle"></h2><p class="well-details__subtitle"></p></div><button type="button" class="tg-btn" data-well-close aria-label="Cerrar ficha del pozo" autofocus>×</button></header><div class="well-details__body"></div><footer class="well-details__footer"><span>Datos de la grilla · incluye columnas ocultas</span><div class="well-details__actions"></div><button type="button" class="tg-btn" data-well-close>Cerrar</button></footer>';
  document.body.append(dialog);
  const title=dialog.querySelector('h2');
  const subtitle=dialog.querySelector('.well-details__subtitle');
  const body=dialog.querySelector('.well-details__body');
  const actions=dialog.querySelector('.well-details__actions');
  const titleLine=document.createElement('div');titleLine.className='well-details__title-line';
  title.before(titleLine);titleLine.append(title);
  const titleIcons=document.createElement('div');titleIcons.className='well-details__title-icons';titleLine.append(titleIcons);
  const identity=titleLine.parentElement;
  identity.classList.add('well-details__identity');
  const pump=document.createElement('img');pump.className='well-details__pump';
  pump.src=document.querySelector('.tg-hero__visual img')?.getAttribute('src')||'assets/img/telemetry/monitoreo_pozos.png';
  pump.alt='Bombeo mecánico';pump.addEventListener('error',()=>pump.hidden=true);
  identity.prepend(pump);
  const monitorSvg='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="1"/><path d="M7 20h10M9 16v4M15 16v4"/></svg>';
  let opener=null;
  const interactive='a,button,input,select,textarea,label,[contenteditable="true"]';
  const groupFor=column=>{
    if(['POZO',cfg.batteryColumn||'BATERIA','Estado Zafiro','Método Zafiro','Zafiro Activo'].includes(column))return 'Identificación';
    if(/^PRODUCCION_|^FT:(AGUA|OIL|PROD)/.test(column))return 'Producción';
    if(['ALM','__COMUNICACION','ESTADO','ESTADO-GRAL','ESTADO_RPM','AF-ESTADO-PARO-REMOTO','Fecha','COMENTARIO'].includes(column))return 'Operación';
    return 'Variables de telemetría';
  };
  const originalColumns=Array.from(table.tHead.rows[0].cells).map(header=>({column:header.dataset.column,label:header.dataset.reportLabel||header.dataset.column}));

  function restoreFocus(){
    if(dialog.open)return;
    document.body.classList.remove('well-details-open');
    const previous=opener;opener=null;
    if(previous?.isConnected)previous.focus({preventScroll:true});
  }

  function close(){dialog.close();restoreFocus();}

  function addAction(source,label,{target=actions,screen=false,iconOnly=false}={}){
    if(!source)return;
    const link=source.cloneNode(false);
    link.removeAttribute('id');
    link.className=iconOnly?'tg-link well-details__screen':'tg-btn';
    link.setAttribute('aria-label',label);link.title=label;
    if(screen){link.innerHTML=monitorSvg;if(!iconOnly)link.append(document.createTextNode(label));}
    else link.textContent=label;
    link.addEventListener('click',()=>close());
    target.append(link);
  }

  function open(row){
    if(dialog.open)return;
    opener=row;
    const cells=new Map(Array.from(row.cells).map(cell=>[cell.dataset.column,cell]));
    const value=column=>cells.get(column)?.dataset.raw?.trim()||'';
    title.textContent=value('POZO')||'Pozo sin nombre';
    titleIcons.replaceChildren();
    const communication=(row.dataset.commStatus||value('__COMUNICACION')).normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim().toLowerCase();
    if(communication==='sin comunicacion'){
      const warning=document.createElement('span');warning.className='well-details__comm-fail';
      warning.setAttribute('role','img');warning.setAttribute('aria-label','Falla de comunicación');warning.title='Sin comunicación';
      warning.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 3l18 18M2 8.8a16 16 0 0 1 3-1.7M8.5 5.4A16 16 0 0 1 22 8.8M5 12a11 11 0 0 1 3.5-1.8M13 10a11 11 0 0 1 6 2M8.5 15.5a5 5 0 0 1 7 0"/><circle cx="12" cy="19" r="1"/></svg>';
      titleIcons.append(warning);
    }
    addAction(cells.get('PANTALLA')?.querySelector('a'),'Abrir pantalla de PI Vision',{target:titleIcons,screen:true,iconOnly:true});
    subtitle.textContent=[cfg.title||'Monitoreo de Pozos',value(cfg.batteryColumn||'BATERIA')].filter(Boolean).join(' · ');
    body.replaceChildren();actions.replaceChildren();
    const groups=new Map(['Identificación','Operación','Producción','Variables de telemetría'].map(name=>[name,[]]));
    originalColumns.forEach(({column,label})=>{
      if(!column||column==='__REPORTE'||column==='PANTALLA'||column==='CARTAS')return;
      const cell=cells.get(column);
      if(!cell)return;
      const field=document.createElement('div');field.className='well-details__field';
      field.dataset.historyColumn=column;
      const term=document.createElement('dt');term.textContent=column==='ALM'?'Alarmas en las últimas 24 h':(label==='COM'?'Comunicación':label);
      const description=document.createElement('dd');
      const badge=cell.querySelector('.tg-badge');
      if(badge)description.append(badge.cloneNode(true));
      else description.textContent=(column==='COMENTARIO'?cell.textContent.trim():cell.dataset.raw?.trim())||'Sin dato';
      field.append(term,description);groups.get(groupFor(column)).push(field);
    });
    groups.forEach((fields,name)=>{
      if(!fields.length)return;
      const section=document.createElement('section');section.className='well-details__section';
      const heading=document.createElement('h3');heading.textContent=name;
      const list=document.createElement('dl');list.append(...fields);section.append(heading,list);body.append(section);
    });
    addAction(row.querySelector('.tg-alarm-link'),'Ver alarmas ('+(value('ALM')||'0')+')');
    addAction(cells.get('PANTALLA')?.querySelector('a'),'Abrir pantalla',{screen:true});
    addAction(cells.get('CARTAS')?.querySelector('a'),'Abrir carta');
    if(window.CLEAR_WELL_HISTORY)window.CLEAR_WELL_HISTORY.decorate({well:value('POZO'),body,actions});
    dialog.showModal();document.body.classList.add('well-details-open');
  }

  table.tBodies[0].querySelectorAll('tr').forEach(row=>{
    if(!row.querySelector('td[data-column="POZO"]'))return;
    row.tabIndex=0;row.classList.add('well-details-row');
    row.setAttribute('aria-haspopup','dialog');
    row.setAttribute('aria-label','Ver ficha del pozo '+(row.querySelector('td[data-column="POZO"]').dataset.raw||'')+'. Doble clic o Enter.');
  });
  table.tBodies[0].addEventListener('dblclick',event=>{
    if(event.target.closest(interactive))return;
    const row=event.target.closest('.well-details-row');if(row)open(row);
  });
  table.tBodies[0].addEventListener('keydown',event=>{
    if(event.key!=='Enter'||event.target.closest(interactive))return;
    const row=event.target.closest('.well-details-row');if(row){event.preventDefault();open(row);}
  });
  dialog.querySelectorAll('[data-well-close]').forEach(button=>button.addEventListener('click',()=>close()));
  dialog.addEventListener('click',event=>{
    if(event.target!==dialog)return;
    const bounds=dialog.getBoundingClientRect();
    if(event.clientX<bounds.left||event.clientX>bounds.right||event.clientY<bounds.top||event.clientY>bounds.bottom)close();
  });
  dialog.addEventListener('keydown',event=>{
    if(event.key!=='Tab')return;
    const buttons=Array.from(dialog.querySelectorAll('button,a[href]')).filter(item=>item.getClientRects().length);
    const first=buttons[0],last=buttons[buttons.length-1];
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
  });
  dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
  dialog.addEventListener('close',restoreFocus);
})();
