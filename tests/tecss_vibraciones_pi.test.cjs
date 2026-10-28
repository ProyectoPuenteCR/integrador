const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require('playwright');
const root=path.join(__dirname,'..');
const php=fs.readFileSync(path.join(root,'tecss_vibraciones.php'),'utf8');
const modal=php.slice(php.indexOf('<div class="vib-modal"'),php.indexOf('<?php clear_alarm_actions_modal();')).replace(/<\?php[\s\S]*?\?>/g,'');
const fixture=`<!doctype html><html lang="es"><meta charset="utf-8"><style>:root{--surface:#fff;--surface-2:#f6f9fc;--line:#dbe4eb;--line-mid:#dbe4eb;--text:#193d48;--text-soft:#46586a;--text-mut:#728596;--font-ui:Arial;--font-head:Arial}body{font-family:Arial;background:#edf2f6}</style><link rel="stylesheet" href="/assets/css/tecss_vibraciones.css"><form id="vibFilterForm"></form><button data-vib-detail data-well="LH-945" data-state="Normal">Ver tendencia</button>${modal}<script src="/assets/js/chart.umd.js"></script><script src="/assets/js/tecss_vibraciones.js"></script></html>`;
function payload(){return {ok:true,state:{ESTADO_ETIQUETA:'Normal',VIBR_ACTUAL:6,GPM_ACTUAL:5.8,VIBR_MEDIANA:166,UMBRAL_ALERTA:415,UMBRAL_CRITICO:581,ALERTAS_48H:0},events:[],series:[{fecha:'2026-10-26 03:46:00.192',vibr:114,gpm:5.8},{fecha:'2026-10-26 06:15:48.654',vibr:77,gpm:5.8},{fecha:'2026-10-26 07:16:15.142',vibr:38,gpm:5.8}],pi:{sync:{available:true,stale:false,covers_range:true,synced_at:'2026-10-26 14:00:00',from:'2026-10-01 00:00:00',through:'2026-10-26 14:00:00'},events:[{id:'EF',start:'2026-10-26 03:46:00.192',end:'2026-10-26 07:16:15.142',vibration_start:114,threshold_start:67.215,notification_at:'2026-10-26 06:46:21',annotations_read:true}]}};}

test('Las preferencias globales no colapsan la línea de tiempo ni eliminan el eje GPM',async()=>{
  const browser=await chromium.launch({channel:'chrome',headless:true});
  try{
    for(const type of ['line','bar','doughnut']){
      const page=await browser.newPage();
      await page.addInitScript(type=>localStorage.setItem('clear_global_chart_preferences_v1',JSON.stringify({type,primary:'#1a596b',secondary:'#e63329',scale:'linear',values:true})),type);
      const customizedFixture=fixture.replace('<script src="/assets/js/tecss_vibraciones.js">','<script src="/assets/js/chart_preferences.js"></script><script src="/assets/js/tecss_vibraciones.js">');
      await page.route('https://clear.test/**',route=>{
        const url=new URL(route.request().url());
        if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:customizedFixture});
        if(url.pathname==='/tecss_vibraciones_api.php')return route.fulfill({json:payload()});
        const file=path.join(root,url.pathname.slice(1));
        if(!file.startsWith(root)||!fs.existsSync(file))return route.abort();
        return route.fulfill({path:file});
      });
      await page.goto('https://clear.test/');
      await page.click('[data-vib-detail]');
      await page.locator('#vibPiEvents').getByText('Evento PI cerrado',{exact:true}).waitFor();
      const actual=await page.evaluate(()=>{
        window.dispatchEvent(new Event('clear-charts-ready'));
        const c=Chart.getChart('vibDetailChart'),p=c.getDatasetMeta(0).data;
        return {type:c.config.type,xType:c.scales.x.type,min:c.scales.x.min,max:c.scales.x.max,
          span:p[p.length-1].x-p[0].x,width:c.chartArea.width,gpm:c.scales.y1?.position,
          piColor:c.data.datasets.find(d=>d.label==='PI · umbral al inicio del evento').borderColor};
      });
      assert.equal(actual.type,'line',type);
      assert.equal(actual.xType,'linear',type);
      assert.equal(actual.min,Date.parse('2026-10-26T03:46:00.192-03:00'));
      assert.equal(actual.max,Date.parse('2026-10-26T07:16:15.142-03:00'));
      assert.ok(actual.span>actual.width*.9,'los puntos deben ocupar el eje de tiempo');
      assert.equal(actual.gpm,'right');
      assert.equal(actual.piColor,'#7650b1');
      await page.close();
    }
  }finally{await browser.close();}
});

test('El detalle ocupa la pantalla y distribuye gráfico y eventos sin desbordes',async()=>{
  const browser=await chromium.launch({channel:'chrome',headless:true});
  try{
    for(const viewport of [{width:1920,height:1080},{width:1366,height:768},{width:390,height:844}]){
      const page=await browser.newPage({viewport});
      const data=payload();
      data.pi.events=Array.from({length:12},(_,i)=>({...data.pi.events[0],id:'EF'+i}));
      await page.route('https://clear.test/**',route=>{
        const url=new URL(route.request().url());
        if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:fixture});
        if(url.pathname==='/tecss_vibraciones_api.php')return route.fulfill({json:data});
        const file=path.join(root,url.pathname.slice(1));
        if(!file.startsWith(root)||!fs.existsSync(file))return route.abort();
        return route.fulfill({path:file});
      });
      await page.goto('https://clear.test/');
      await page.click('[data-vib-detail]');
      await page.locator('#vibPiEvents article').first().waitFor();
      const panel=await page.locator('.vib-modal__panel').boundingBox();
      const chart=await page.locator('#vibChartCard').boundingBox();
      const pi=await page.locator('.vib-pi-panel').boundingBox();
      assert.ok(Math.abs(panel.x)<1&&Math.abs(panel.y)<1);
      assert.ok(Math.abs(panel.width-viewport.width)<1&&Math.abs(panel.height-viewport.height)<1);
      const overflow=await page.locator('.vib-modal__panel').evaluate(e=>e.scrollWidth>e.clientWidth);
      assert.equal(overflow,false,'sin desplazamiento horizontal');
      if(viewport.width>=1000){
        assert.ok(pi.x>=chart.x+chart.width,'eventos al costado');
        assert.ok(chart.width>viewport.width*.6,'el gráfico tiene prioridad');
        assert.ok(chart.y+chart.height<=viewport.height,'gráfico visible completo');
      }else assert.ok(pi.y>=chart.y+chart.height,'en móvil los eventos van debajo del gráfico');
      await page.close();
    }
  }finally{await browser.close();}
});
test('Muestra PI y CLEAR juntos, sin mezclar escalas ni afirmar ausencia sin cobertura',async()=>{
  const browser=await chromium.launch({channel:'chrome',headless:true});
  try{
    // Probar desde otro huso: los eventos deben conservar la hora argentina.
    const page=await browser.newPage({viewport:{width:1440,height:1050},timezoneId:'Europe/Madrid'});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    let data=payload();
    await page.route('https://clear.test/**',route=>{
      const url=new URL(route.request().url());
      if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:fixture});
      if(url.pathname==='/tecss_vibraciones_api.php')return route.fulfill({json:data});
      const file=path.join(root,url.pathname.slice(1));
      if(!file.startsWith(root)||!fs.existsSync(file))return route.abort();
      return route.fulfill({path:file});
    });
    await page.goto('https://clear.test/');
    await page.click('[data-vib-detail]');
    await page.locator('#vibPiEvents').getByText('Evento PI cerrado',{exact:true}).waitFor();
    assert.match(await page.locator('#vibDetailMetrics').textContent(),/Normal/);
    const text=await page.locator('#vibPiEvents').textContent();
    assert.match(text,/114,000/);assert.match(text,/67,215/);assert.match(text,/03:46/);assert.match(text,/06:46/);
    assert.match(await page.locator('#vibDetailEvents').textContent(),/Sin alertas/);
    const chart=await page.evaluate(()=>{const c=Chart.getChart('vibDetailChart');return {type:c.options.scales.x.type,datasets:c.data.datasets.map(d=>({label:d.label,data:d.data}))};});
    assert.equal(chart.type,'linear');
    assert.equal(chart.datasets.find(d=>d.label==='CLEAR · umbral alerta').data[0].y,415);
    const pi=chart.datasets.find(d=>d.label==='PI · umbral al inicio del evento');
    assert.equal(pi.data[0].y,67.215);assert.equal(pi.data[0].x,Date.parse('2026-10-26T03:46:00.192-03:00'));
    assert.equal(pi.data[2].y,null,'no unir umbrales entre eventos');
    if(process.env.TECSS_PI_SCREENSHOT)await page.screenshot({path:process.env.TECSS_PI_SCREENSHOT,fullPage:true});

    data.pi={sync:{available:false,reason:'Sin sincronizar PI'},events:[]};
    await page.locator('#vibDetailForm button').click();
    await page.locator('#vibPiStatus').getByText('Sin sincronizar PI',{exact:true}).waitFor();
    assert.equal(await page.locator('#vibPiEvents').textContent(),'');
    assert.match(await page.locator('#vibDetailMetrics').textContent(),/Normal/);

    data=payload();data.pi.sync.stale=true;data.pi.events[0].end='';data.pi.events[0].notification_at='';data.pi.events[0].annotations_read=false;
    await page.locator('#vibDetailForm button').click();
    await page.locator('#vibPiEvents').getByText('Abierto en la última sincronización',{exact:true}).waitFor();
    assert.match(await page.locator('#vibPiEvents').textContent(),/Registro de envío no disponible/);
    assert.match(await page.locator('#vibPiStatus').textContent(),/Pendiente de actualizar/);

    data.pi.events=[];data.pi.sync.covers_range=false;
    await page.locator('#vibDetailForm button').click();
    await page.locator('#vibPiEvents').getByText(/la cobertura no permite confirmar su ausencia/).waitFor();
    assert.deepEqual(errors,[]);
  }finally{await browser.close();}
});
