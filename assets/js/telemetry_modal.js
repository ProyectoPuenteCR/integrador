(function(){
  'use strict';
  const modal=document.getElementById('telemetryModal');
  if(!modal)return;
  const frame=document.getElementById('telemetryModalFrame');
  const title=document.getElementById('telemetryModalTitle');
  const subtitle=document.getElementById('telemetryModalSubtitle');
  const eyebrow=document.getElementById('telemetryModalEyebrow');
  const openPage=document.getElementById('telemetryModalOpenPage');
  const maximize=document.getElementById('telemetryModalMaximize');
  const loading=document.getElementById('telemetryModalLoading');
  let lastFocused=null;

  function setLoading(show){
    if(!loading)return;
    loading.classList.toggle('is-hidden',!show);
  }


  function openBrowserPopup(trigger){
    const url=trigger.dataset.telemetryPopupUrl||trigger.getAttribute('href');
    if(!url)return false;
    const margin=24;
    const availableWidth=window.screen&&window.screen.availWidth?window.screen.availWidth:window.innerWidth;
    const availableHeight=window.screen&&window.screen.availHeight?window.screen.availHeight:window.innerHeight;
    const width=Math.max(900,availableWidth-(margin*2));
    const height=Math.max(650,availableHeight-(margin*2));
    const left=Math.max(0,Math.round((availableWidth-width)/2));
    const top=Math.max(0,Math.round((availableHeight-height)/2));
    const name=(trigger.dataset.telemetryPopupName||'CLEAR_TELEMETRY_POPUP').replace(/[^a-z0-9_]/gi,'_');
    const features=['popup=yes','resizable=yes','scrollbars=yes','location=yes','status=no','toolbar=no','menubar=no','width='+width,'height='+height,'left='+left,'top='+top].join(',');
    const popup=window.open(url,name,features);
    if(!popup)return false;
    try{
      popup.opener=null;
      popup.moveTo(left,top);
      popup.resizeTo(width,height);
      popup.focus();
    }catch(error){}
    return true;
  }

  function open(trigger){
    const url=trigger.dataset.telemetryModalUrl||trigger.getAttribute('href');
    if(!url)return;
    lastFocused=trigger;
    title.textContent=trigger.dataset.telemetryModalTitle||trigger.getAttribute('title')||'Vista rápida';
    subtitle.textContent=trigger.dataset.telemetryModalSubtitle||'';
    eyebrow.textContent=trigger.dataset.telemetryModalEyebrow||'Vista rápida';
    openPage.href=trigger.dataset.telemetryModalOpenUrl||trigger.getAttribute('href')||url;
    modal.hidden=false;
    modal.setAttribute('aria-hidden','false');
    modal.classList.remove('is-maximized');
    maximize.textContent='Maximizar';
    document.body.classList.add('telemetry-modal-open');
    setLoading(true);
    frame.src='about:blank';
    window.requestAnimationFrame(function(){frame.src=url;});
    maximize.focus({preventScroll:true});
  }

  function close(){
    modal.hidden=true;
    modal.setAttribute('aria-hidden','true');
    modal.classList.remove('is-maximized');
    document.body.classList.remove('telemetry-modal-open');
    frame.src='about:blank';
    setLoading(true);
    if(lastFocused&&typeof lastFocused.focus==='function')lastFocused.focus({preventScroll:true});
  }

  frame.addEventListener('load',function(){setLoading(false);});
  document.addEventListener('click',function(event){
    const popupTrigger=event.target.closest('[data-telemetry-popup-url]');
    if(popupTrigger){
      event.preventDefault();
      if(!openBrowserPopup(popupTrigger))window.open(popupTrigger.href,'_blank');
      return;
    }
    const trigger=event.target.closest('[data-telemetry-modal-url]');
    if(trigger){event.preventDefault();open(trigger);return;}
    if(event.target.closest('[data-telemetry-modal-close]'))close();
  });
  maximize.addEventListener('click',function(){
    const active=modal.classList.toggle('is-maximized');
    maximize.textContent=active?'Restaurar':'Maximizar';
    maximize.setAttribute('aria-label',active?'Restaurar ventana':'Maximizar ventana');
  });
  document.addEventListener('keydown',function(event){
    if(modal.hidden)return;
    if(event.key==='Escape')close();
  });
  window.CLEAR_TELEMETRY_MODAL={openPopup:openBrowserPopup,open:function(url,options){
    const virtual=document.createElement('a');
    virtual.href=url;
    virtual.dataset.telemetryModalUrl=url;
    virtual.dataset.telemetryModalOpenUrl=(options&&options.openUrl)||url;
    virtual.dataset.telemetryModalTitle=(options&&options.title)||'Vista rápida';
    virtual.dataset.telemetryModalSubtitle=(options&&options.subtitle)||'';
    open(virtual);
  },close:close};
})();
