(function(){
  'use strict';
  const number=new Intl.NumberFormat('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2});
  const make=(tag,text)=>{const el=document.createElement(tag);el.textContent=text;return el;};
  async function decorate({host,well,source}){
    host.replaceChildren(make('h3','Producción Zafiro'),make('p','Consultando el pozo…'));
    try{
      const response=await fetch('well_zafiro_api.php?'+new URLSearchParams({well,source}),{credentials:'same-origin'});
      const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'No se pudo consultar Zafiro.');
      if(!host.isConnected)return;
      host.replaceChildren(make('h3','Producción Zafiro'));
      if(!data.found){host.append(make('p','Sin dato: no se encontró producción Zafiro para este pozo.'));return;}
      const grid=make('dl','');grid.style.cssText='display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:12px';
      for(const [column,label] of [['Petroleo24','Petróleo · 24 h'],['Agua24','Agua · 24 h'],['Liquido24','Líquido · 24 h'],['Gas24','Gas · 24 h']]){
        const field=make('div','');field.className='well-details__field';
        const raw=data.values[column];field.append(make('dt',label),make('dd',typeof raw==='number'&&Number.isFinite(raw)?number.format(raw):'Sin dato'));grid.append(field);
      }
      host.append(grid,make('p','Pozo Zafiro: '+data.matchedWell+' · ID: '+data.pozoId+' · Día operativo: '+(data.operatingDay||'Sin dato')+' · Carga: '+(data.loadedAt||'Sin dato')));
      host.append(make('small','Fuente: Zafiro Q412. Valores de las últimas 24 h informadas por Zafiro.'));
    }catch(error){if(host.isConnected){host.replaceChildren(make('h3','Producción Zafiro'),make('p','Sin dato. '+error.message));}}
  }
  window.CLEAR_WELL_ZAFIRO={decorate};
})();
