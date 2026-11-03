(function(){
  'use strict';
  const cfg=window.CLEAR_TELEMETRY_GRID||{};
  if(cfg.key!=='telemetria_bes')return;
  const table=document.getElementById('tgTable');
  if(!table||!table.tBodies[0])return;

  const dialog=document.createElement('dialog');
  dialog.className='well-details wd-dashboard';
  dialog.setAttribute('aria-labelledby','wellDetailsTitle');
  dialog.innerHTML='<header class="well-details__head"><div><div class="well-details__eyebrow">Centro de operación · Ficha del pozo</div><h2 id="wellDetailsTitle"></h2><p class="well-details__subtitle"></p></div><div class="wd-head-actions"><button type="button" class="tg-btn" data-well-theme aria-label="Cambiar a tema oscuro" title="Tema oscuro" aria-pressed="false">☾</button><button type="button" class="tg-btn wd-choose" data-well-choose>Elegir variables</button><button type="button" class="tg-btn" data-well-maximize aria-label="Maximizar ficha del pozo" aria-pressed="false">⛶</button><button type="button" class="tg-btn" data-well-close aria-label="Cerrar ficha del pozo" autofocus>×</button></div></header><div class="well-details__body"></div><footer class="well-details__footer"><span>Datos de la grilla · incluye columnas ocultas</span><div class="well-details__actions"></div><button type="button" class="tg-btn" data-well-close>Cerrar</button></footer>';
  document.body.append(dialog);
  const title=dialog.querySelector('h2');
  const subtitle=dialog.querySelector('.well-details__subtitle');
  const liveStatus=document.createElement('div');liveStatus.className='wd-live-status';subtitle.after(liveStatus);
  const body=dialog.querySelector('.well-details__body');
  const actions=dialog.querySelector('.well-details__actions');
  const activityHost=document.createElement('div');activityHost.className='wd-header-activity';dialog.querySelector('.wd-head-actions').before(activityHost);
  const titleLine=document.createElement('div');titleLine.className='well-details__title-line';
  title.before(titleLine);titleLine.append(title);
  const titleIcons=document.createElement('div');titleIcons.className='well-details__title-icons';titleLine.append(titleIcons);
  const identity=titleLine.parentElement;
  identity.classList.add('well-details__identity');
  const pump=document.createElement('img');pump.className='well-details__pump';
  pump.src=document.querySelector('.tg-hero__visual img')?.getAttribute('src')||'assets/img/telemetry/telemetria_bes.png';
  pump.alt='Bombeo BES';pump.addEventListener('error',()=>pump.hidden=true);
  identity.prepend(pump);
  const monitorSvg='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="1"/><path d="M7 20h10M9 16v4M15 16v4"/></svg>';
  let opener=null;
  const interactive='a,button,input,select,textarea,label,[contenteditable="true"]';
  const groupFor=column=>{
    if(['POZO',cfg.batteryColumn||'BATERIA','Estado Zafiro','Método Zafiro','Zafiro Activo'].includes(column))return 'Identificación';
    if(/^PRODUCCION_|^FT:(AGUA|OIL|PROD)/.test(column))return 'Producción';
    if(['PT:FONDO','TT:FONDO','VT-Z','PAL:INTAKE'].includes(column))return 'Intake';
    if(['TT:MOTOR','N-ET:MOTOR','N-IT:MOTOR','N-FP:MOTOR','N-IT:LC','N-IT:MOTOR-AVG','N-IT:MOTOR-MARCHA','N-IT:MOTOR-RATIO'].includes(column))return 'Motor';
    if(['N-FQ:MOTOR','N-IT:DH','N-ET:OUT'].includes(column))return 'Variador';
    if(['YT:SISTEMA','YT:MOD-CONTROL','RUN:BQ','AF:RUN:BQ','PT:CABEZA','PT:LINEA','PID:PT-FONDO','FEHA','YT:POZO','YAT:COM','PLANTILLA','ALM','__COMUNICACION','ESTADO','ESTADO-GRAL','ESTADO_RPM','AF-ESTADO-PARO-REMOTO','Fecha','COMENTARIO'].includes(column))return 'Operación';
    return 'Variables de telemetría';
  };
  const isNoData=text=>/^(?:sin\s+datos?|[—–-])?$/i.test(String(text).trim());
  const readingFormat=new Intl.NumberFormat('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2,useGrouping:false});
  function displayValue(column,raw){
    if(!raw)return 'Sin dato';
    if(!/^(PRODUCCION_|(?:PT|QT|FT|WT|ZT|ET|TT):|N-|VT-|FP$)/.test(column))return raw;
    if(!/^[+-]?\d+(?:[.,]\d+)?(?:e[+-]?\d+)?$/i.test(raw))return raw;
    const value=Number(raw.replace(',','.'));return Number.isFinite(value)?readingFormat.format(value):raw;
  }
  const besLabels={'YT:SISTEMA':'Tipo de controlador','YT:MOD-CONTROL':'Modo de control','RUN:BQ':'Estado bomba de químicos','AF:RUN:BQ':'Bomba de químicos · AF','PT:FONDO':'Presión de fondo','PT:CONSUMO':'Presión de consumo de la bomba','PT:CABEZA':'Presión boca de pozo','PT:LINEA':'Presión de línea','TT:FONDO':'Temperatura de fondo','TT:MOTOR':'Temperatura del motor','PAL:INTAKE':'Alarma intake','PID:PT-FONDO':'Consigna presión de fondo','N-IT:LC':'Corriente de pérdida','N-IT:DH':'Corriente VSD','N-ET:OUT':'Tensión de salida','N-ET:MOTOR':'Tensión de motor','N-IT:MOTOR':'Corriente Motor','N-FQ:MOTOR':'Frecuencia del motor','N-FP:MOTOR':'Factor de potencia','VT-Z':'Vibración del eje Z'};
  const originalColumns=Array.from(table.tHead.rows[0].cells).map(header=>({column:header.dataset.column,label:besLabels[header.dataset.column]||header.dataset.reportLabel||header.dataset.column}));

  function restoreFocus(){
    if(dialog.open)return;
    document.body.classList.remove('well-details-open');
    const previous=opener;opener=null;
    if(previous?.isConnected)previous.focus({preventScroll:true});
  }

  const themeButton=dialog.querySelector('[data-well-theme]');
  function syncTheme(){const dark=document.documentElement.dataset.theme==='dark';themeButton.textContent=dark?'☀':'☾';themeButton.setAttribute('aria-label',dark?'Cambiar a tema claro':'Cambiar a tema oscuro');themeButton.title=dark?'Tema claro':'Tema oscuro';themeButton.setAttribute('aria-pressed',String(dark));}
  themeButton.addEventListener('click',()=>{
    const platformButton=document.getElementById('themeToggleBtn');
    if(platformButton){platformButton.click();return;}
    const theme=document.documentElement.dataset.theme==='dark'?'light':'dark';
    document.documentElement.dataset.theme=theme;document.documentElement.style.colorScheme=theme;
    try{localStorage.setItem(window.CLEAR_THEME_USER_KEY||'clear_theme',theme);}catch(error){}
    window.dispatchEvent(new CustomEvent('clear-theme-change',{detail:{theme}}));
  });
  new MutationObserver(syncTheme).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});syncTheme();
  const maximize=dialog.querySelector('[data-well-maximize]');
  function resize(maximized){dialog.classList.toggle('is-maximized',maximized);maximize.setAttribute('aria-pressed',String(maximized));maximize.setAttribute('aria-label',maximized?'Restaurar ficha del pozo':'Maximizar ficha del pozo');maximize.textContent=maximized?'❐':'⛶';}
  maximize.addEventListener('click',()=>resize(!dialog.classList.contains('is-maximized')));
  function close(){window.CLEAR_BES_ACTIVITY?.detach?.();window.CLEAR_BES_HISTORY?.detach?.();dialog.close();resize(false);restoreFocus();}

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


  function zafiro(well){
    if(!window.CLEAR_ZAFIRO_READY)window.CLEAR_ZAFIRO_READY=new Promise((resolve,reject)=>{const s=document.createElement('script');s.src='assets/js/well_zafiro.js?v=20261103-1';s.onload=resolve;s.onerror=()=>{delete window.CLEAR_ZAFIRO_READY;reject(Error('No se pudo cargar Producción Zafiro.'));};document.head.append(s);});
    const host=document.createElement('section');host.className='well-details__section wz-production';host.textContent='Consultando producción Zafiro…';body.prepend(host);
    window.CLEAR_ZAFIRO_READY.then(()=>{if(host.isConnected)window.CLEAR_WELL_ZAFIRO.decorate({host,well,source:cfg.key});}).catch(e=>{if(host.isConnected)host.textContent=e.message;});
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
    liveStatus.replaceChildren();
    ['__COMUNICACION','ESTADO'].forEach(column=>{if(!value(column))return;const badge=document.createElement('span');badge.textContent=value(column);if(column==='__COMUNICACION'&&communication==='sin comunicacion')badge.className='is-error';liveStatus.append(badge);});
    body.replaceChildren();actions.replaceChildren();
    const groups=new Map(['Identificación','Operación','Intake','Motor','Variador','Producción','Variables de telemetría'].map(name=>[name,[]]));
    originalColumns.forEach(({column,label})=>{
      if(!column||column==='__REPORTE'||column==='PANTALLA'||column==='CARTAS')return;
      const cell=cells.get(column);
      if(!cell)return;
      const field=document.createElement('div');field.className='well-details__field';
      field.dataset.historyColumn=column;if(column==='COMENTARIO')field.classList.add('wd-field-comment');
      const term=document.createElement('dt');term.textContent=column==='ALM'?'Alarmas en las últimas 24 h':(label==='COM'?'Comunicación':label);
      const description=document.createElement('dd');
      const badge=cell.querySelector('.tg-badge');
      if(badge)description.append(badge.cloneNode(true));
      else description.textContent=displayValue(column,(column==='COMENTARIO'?cell.textContent.trim():cell.dataset.raw?.trim())||'');
      field.classList.toggle('is-no-data',isNoData(description.textContent));
      field.append(term,description);groups.get(groupFor(column)).push(field);
    });
    const missing=document.createElement('section');missing.className='well-details__section';
    const missingTitle=document.createElement('h3');missingTitle.textContent='Datos de la referencia pendientes de vincular';missing.append(missingTitle);
    const missingList=document.createElement('dl');
    ['Modelo','Yacimiento','Último shutdown','Tiempo de marcha','Tiempo para reinicio','Nivel tanque de químicos','Voltaje de bus'].forEach(label=>{const field=document.createElement('div');field.className='well-details__field is-no-data';const dt=document.createElement('dt');dt.textContent=label;const dd=document.createElement('dd');dd.textContent='Sin variable PI verificada';field.append(dt,dd);missingList.append(field);});
    missing.append(missingList);
    const kpis=document.createElement('div');kpis.className='wd-kpis';
    [['Estado','ESTADO'],['Frecuencia del motor','N-FQ:MOTOR'],['Corriente Motor','N-IT:MOTOR'],['Presión de fondo','PT:FONDO'],['Temperatura de fondo','TT:FONDO'],['Alarmas 24 h','ALM']].forEach(([label,column])=>{
      const card=document.createElement('article');card.className='wd-kpi';const heading=document.createElement('span');heading.textContent=label;
      const reading=document.createElement('strong');reading.textContent=displayValue(column,value(column));reading.dataset.liveColumn=column;
      card.classList.toggle('is-no-data',isNoData(reading.textContent));
      card.append(heading,reading);kpis.append(card);
    });body.append(kpis);
    const workspace=document.createElement('div');workspace.className='wd-workspace';
    const historyHost=document.createElement('section');historyHost.className='wd-history-host';historyHost.setAttribute('aria-label','Tendencias seleccionadas');
    const cardsHost=document.createElement('aside');cardsHost.className='wd-cards-host';cardsHost.setAttribute('aria-label','Cartas dinamométricas');
    workspace.append(historyHost);workspace.style.gridTemplateColumns='minmax(0,1fr)';body.append(workspace);
    const details=document.createElement('div');details.className='wd-data-groups';details.tabIndex=-1;body.append(details);
    dialog.querySelector('[data-well-choose]').onclick=()=>{details.scrollIntoView({block:'start',behavior:'smooth'});details.focus({preventScroll:true});};
    groups.forEach((fields,name)=>{
      if(!fields.length)return;
      const section=document.createElement('section');section.className='well-details__section';
      const heading=document.createElement('h3');heading.textContent=name==='Producción'?'Producción de la grilla · INFOIL Q164':name;
      const list=document.createElement('dl');list.append(...fields);section.append(heading,list);details.append(section);
    });
    details.append(missing);
    addAction(row.querySelector('.tg-alarm-link'),'Ver alarmas ('+(value('ALM')||'0')+')');
    addAction(cells.get('PANTALLA')?.querySelector('a'),'Abrir pantalla',{screen:true});
    addAction(cells.get('CARTAS')?.querySelector('a'),'Abrir carta');
    if(window.CLEAR_BES_HISTORY)window.CLEAR_BES_HISTORY.decorate({well:value('POZO'),body,actions,historyHost});
    if(!window.CLEAR_BES_HISTORY){historyHost.textContent='Históricos no disponibles para este usuario.';cardsHost.textContent='Cartas PI no disponibles para este usuario.';}
    window.CLEAR_BES_ACTIVITY?.decorate({row,body,activityHost,well:value('POZO'),close});
    zafiro(value('POZO'));
    const live=document.createElement('p');live.className='wh-catalog-status';live.textContent='Valores iniciales de la grilla. Consultando PI…';body.prepend(live);
    const refresh=document.createElement('button');refresh.type='button';refresh.className='tg-btn';refresh.textContent='Actualizar PI';actions.prepend(refresh);
    const update=async()=>{refresh.disabled=true;try{const r=await fetch('bes_history_api.php?mode=snapshot&well='+encodeURIComponent(value('POZO')),{credentials:'same-origin'});const data=await r.json();if(!r.ok||!data.ok)throw Error(data.error||'No se pudo leer PI');if(!live.isConnected)return;for(const [column,point] of Object.entries(data.values)){body.querySelectorAll('[data-live-column]').forEach(el=>{if(el.dataset.liveColumn===column)el.textContent=point.good?displayValue(column,String(point.value??'')):'Sin dato';});body.querySelectorAll('[data-history-column]').forEach(el=>{if(el.dataset.historyColumn!==column)return;const dd=el.querySelector('dd');dd.textContent=point.good?displayValue(column,String(point.value??'')):'Sin dato';dd.title=(point.tag||'')+' · '+(point.timestamp||'')+(point.good?'':' · Calidad PI no válida');});}live.textContent='Lectura PI: '+new Date().toLocaleString('es-AR')+'. Consultá la fecha y calidad de cada variable.';}catch(e){if(live.isConnected){live.textContent='Se conservan los valores de la grilla. '+e.message;live.classList.add('is-error');}}finally{refresh.disabled=false;}};
    refresh.onclick=update;if(cfg.wellHistory)update();else live.textContent='Valores de la grilla. Sin permiso de consulta PI.';
    resize(true);dialog.showModal();body.scrollTop=0;document.body.classList.add('well-details-open');
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
    const buttons=Array.from(dialog.querySelectorAll('button,a[href],input,select,textarea')).filter(item=>!item.disabled&&item.getClientRects().length);
    const first=buttons[0],last=buttons[buttons.length-1];
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
  });
  dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
  dialog.addEventListener('close',restoreFocus);
})();
