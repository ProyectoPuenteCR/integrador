const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require('playwright');
const root=path.join(__dirname,'..');
const fields={POZO:'YPF.SC.BB-130',BATERIA:'BB 03','QT:RPM':'750',FP:'0,85',ECO:'NORMAL',Fecha:'2026-10-22 12:00','PT:LINEA':'0',ESTADO_RPM:'EN MARCHA','PT:CABEZA':'10.45'};
const config={key:'monitoreo_pozos',title:'Monitoreo Pozos',batteryColumn:'BATERIA',wellHistory:true,wellHistoryReport:true};
function fixture(values=fields,settings=config){
  const styles=fs.readFileSync(path.join(root,'includes/telemetry_grid.php'),'utf8').match(/<style>([\s\S]*?)<\/style>/)[1].split('<?php')[0];
  return '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+['app','well_details','well_history'].map(name=>fs.readFileSync(path.join(root,'assets/css/'+name+'.css'),'utf8')).join('\n')+styles+'</style><table id="tgTable"><thead><tr>'+Object.keys(values).map(column=>'<th data-column="'+column+'" data-report-label="'+column+'">'+column+'</th>').join('')+'</tr></thead><tbody><tr>'+Object.entries(values).map(([column,value])=>'<td data-column="'+column+'" data-raw="'+value+'">'+value+'</td>').join('')+'</tr></tbody></table><script>window.CLEAR_TELEMETRY_GRID='+JSON.stringify(settings)+'</script></html>';
}
const catalog={};for(const column of ['QT:RPM','FP','ECO','ESTADO_RPM','PT:CABEZA'])catalog[column]={available:true,tag:fields.POZO+'(RPC)_'+column+'.PV',unit:column==='QT:RPM'?'rpm':'',type:column==='ECO'?'Digital':'Float32'};
catalog.Fecha={available:false,reason:'Sin tag verificado'};catalog['PT:LINEA']={available:false,reason:'Sin tag verificado'};
function response(columns,intervalSeconds=3600){return {ok:true,well:fields.POZO,intervalSeconds,combinedReportKey:'well_history_combined:'+ 'a'.repeat(40),start:'2026-10-21T00:00:00-03:00',end:'2026-10-22T00:00:00-03:00',series:columns.map((column,index)=>({column,tag:catalog[column].tag,unit:catalog[column].unit,type:catalog[column].type,step:column==='ECO',reportKey:'well_history:'+String(index+1).repeat(40),samples:[0,1,2].map(hour=>({timestamp:'2026-10-21T0'+(hour+3)+':00:00Z',plot_value:column==='ECO'?(hour?1:0):column==='FP'?0.85:750+hour,value:column==='ECO'?(hour?'ALARMA':'NORMAL'):column==='FP'?0.85:750+hour,good:hour!==1,questionable:false,substituted:false})).filter((sample,index)=>column!=='FP'||index!==1)}))};}
// Leer los miembros ZIP almacenados para verificar que la descarga sea XLSX real.
function zipEntries(bytes){const entries={};let at=0;while(bytes.readUInt32LE(at)===0x04034b50){const length=bytes.readUInt32LE(at+18),nameLength=bytes.readUInt16LE(at+26),extra=bytes.readUInt16LE(at+28),name=bytes.subarray(at+30,at+30+nameLength).toString(),start=at+30+nameLength+extra;entries[name]=bytes.subarray(start,start+length).toString();at=start+length;}return entries;}
for(const mobile of [false,true])test('selección, gráficos, escalas, Excel y reporte '+(mobile?'móvil':'escritorio'),async()=>{
  const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'msedge'});
  try{
    const page=await browser.newPage({viewport:mobile?{width:390,height:844}:{width:1440,height:1000},acceptDownloads:true});
    const errors=[],requests=[],reports=[];page.on('pageerror',error=>errors.push(error.message));
    let fail=false,delay=false;const cardRequests=[];
    await page.route('http://clear.test/**',async route=>{
      const url=new URL(route.request().url());
      if(url.pathname.endsWith('/well_history_api.php')){
        if(route.request().method()==='GET')return route.fulfill({json:{ok:true,columns:catalog}});
        const request=route.request().postDataJSON();
        if(request.action==='cards'){cardRequests.push(request);return route.fulfill({json:{ok:true,start:'2026-10-22T14:59:00-03:00',end:'2026-10-22T15:00:00-03:00',cards:[{kind:'superficie',xUnit:'in',yUnit:'lb',points:[{x:0,y:32000,timestamp:'2026-10-22T17:59:10Z'},{x:100,y:60000,timestamp:'2026-10-22T17:59:20Z'},{x:0,y:34000,timestamp:'2026-10-22T17:59:30Z'}]},{kind:'fondo',points:[],error:'Falta verificar ZT:FONDO-POS en PI_Points_Stage.'}]}});}
        requests.push(request);
        if(delay)await new Promise(resolve=>setTimeout(resolve,150));
        return route.fulfill({status:fail?422:200,json:fail?{ok:false,error:'El rango debe ser de hasta 31 días.'}:response(request.columns,request.intervalSeconds)});
      }
      if(url.pathname.endsWith('/novedades_semanales_report_api.php')){reports.push(route.request().postDataJSON());return route.fulfill({json:{ok:true,count:3,message:'Tendencias agregadas al reporte.'}});}
      return route.fulfill({contentType:'text/html; charset=utf-8',body:fixture()});
    });
    await page.addInitScript(()=>localStorage.setItem('clear_global_chart_preferences_v1',JSON.stringify({type:'bar',primary:'#ff0000',secondary:'#ff0000',values:true})));
    await page.goto('http://clear.test/monitoreo_pozos.php');
    for(const file of ['chart.umd.js','chartjs-plugin-zoom.min.js','chart_preferences.js','well_history.js','well_details.js'])await page.addScriptTag({path:path.join(root,'assets/js',file)});
    await page.locator('td[data-column=POZO]').click();
    const detail=page.locator('dialog.well-details:not(.wh-dialog)'),history=detail;
    await page.waitForFunction(()=>document.querySelectorAll('.wh-pick input:enabled').length===5);
    await page.waitForFunction(()=>document.querySelectorAll('.wh-chart canvas').length===1&&document.querySelector('[data-wh-export]').disabled===false);
    assert.deepEqual(requests[0].columns,['ESTADO_RPM','QT:RPM','PT:CABEZA']);
    assert.equal(await detail.getByRole('button',{name:'Restaurar ficha del pozo',exact:true}).getAttribute('aria-pressed'),'true');
    await page.waitForFunction(()=>document.querySelectorAll('.wd-card-plot canvas').length===1);
    assert.equal(cardRequests.length,1);assert.equal(cardRequests[0].mode,'latest');
    for(const column of ['ESTADO_RPM','QT:RPM','PT:CABEZA']){
      const checkbox=detail.getByRole('checkbox',{name:'Seleccionar '+column+' para histórico',exact:true});
      assert.equal(await checkbox.isChecked(),true);await checkbox.uncheck();
    }
    requests.length=0;
    assert.equal(await detail.getByRole('button',{name:'Histórico (0)',exact:true}).isDisabled(),true);
    assert.equal(await detail.getByRole('checkbox',{name:'Seleccionar Fecha para histórico',exact:true}).isDisabled(),true);
    for(const column of ['QT:RPM','FP','ECO'])await detail.getByRole('checkbox',{name:'Seleccionar '+column+' para histórico',exact:true}).check();
    await detail.getByRole('button',{name:'Histórico (3)',exact:true}).click();
    await page.waitForFunction(()=>document.querySelectorAll('.wh-chart canvas').length===1&&document.querySelector('[data-wh-export]').disabled===false);
    assert.equal(requests.length,1);assert.deepEqual(requests[0].columns,['QT:RPM','FP','ECO']);
    assert.equal(await history.locator('.wh-chart').count(),1);
    assert.equal(await history.getByLabel('Frecuencia en minutos',{exact:true}).getAttribute('min'),'0.5');
    assert.equal(await history.locator('.wh-scale').count(),0);
    assert.equal(requests[0].intervalSeconds,3600);
    assert.equal(await page.locator('dialog.wh-dialog').isVisible(),false);
    assert.equal(await history.locator('.wd-kpi').count(),6);
    assert.equal(cardRequests[0].mode,'latest');assert.equal(cardRequests[0].windowSeconds,60);assert.equal(cardRequests[0].well,fields.POZO);
    assert.ok((await history.locator('.wd-cards-host').textContent()).includes('Falta verificar ZT:FONDO-POS'));
    const xy=await history.locator('.wd-card-plot canvas').evaluate(canvas=>Chart.getChart(canvas).data.datasets[0].data);
    assert.deepEqual(xy.map(point=>point.x),[0,100,0]);
    await history.getByLabel('Modo de consulta de cartas',{exact:true}).selectOption('manual');
    assert.equal(await history.getByLabel('Cartas hasta',{exact:true}).isEnabled(),true);
    await history.getByRole('button',{name:'Consultar cartas',exact:true}).click();
    await page.waitForFunction(()=>document.querySelectorAll('.wd-card-plot canvas').length===1);
    assert.equal(cardRequests[1].mode,'manual');
    await history.getByRole('button',{name:'Restaurar ficha del pozo',exact:true}).click();
    const originalBox=await history.boundingBox();
    await history.getByRole('button',{name:'Maximizar ficha del pozo',exact:true}).click();
    const maximized=await history.boundingBox(),viewport=page.viewportSize();
    assert.ok(Math.abs(maximized.width-viewport.width)<=2);assert.ok(Math.abs(maximized.height-viewport.height)<=2);
    assert.equal(await history.getByRole('button',{name:'Restaurar ficha del pozo',exact:true}).getAttribute('aria-pressed'),'true');
    assert.equal(requests.length,1);
    await history.getByRole('button',{name:'Restaurar ficha del pozo',exact:true}).click();
    assert.ok(Math.abs((await history.boundingBox()).width-originalBox.width)<=2);
    assert.equal(await history.getByLabel('Frecuencia en minutos',{exact:true}).inputValue(),'60');
    await page.waitForFunction(()=>{const canvas=document.querySelector('.wh-chart canvas');return Math.abs(Chart.getChart(canvas).width-canvas.parentElement.clientWidth)<2;});
    const chartState=await history.locator('.wh-chart canvas').evaluate(canvas=>{const chart=Chart.getChart(canvas);return chart.data.datasets.map(dataset=>({label:dataset.label,color:dataset.borderColor,axis:dataset.yAxisID,data:dataset.data}));});
    assert.equal(chartState.length,3);assert.equal(new Set(chartState.map(item=>item.color)).size,3);assert.equal(new Set(chartState.map(item=>item.axis)).size,1);
    const options=await history.locator('.wh-chart canvas').evaluate(canvas=>{const chart=Chart.getChart(canvas);return {type:chart.config.type,scales:Object.keys(chart.scales),local:canvas.dataset.clearChartLocal,labels:chart.options.plugins.clearGlobalValues,background:getComputedStyle(canvas.parentElement).backgroundColor};});
    assert.deepEqual(options.scales,['x','y']);assert.equal(options.type,'line');assert.equal(options.local,'true');assert.equal(options.labels,false);assert.equal(options.background,'rgb(255, 255, 255)');
    assert.equal(await history.locator('.clearChartInlineBtn').count(),0);
    // Arrastre real con botón izquierdo: zoom sin ocultar series ni volver a consultar PI.
    await history.locator('.wh-chart canvas').scrollIntoViewIfNeeded();
    const zoomBefore=requests.length;
    const rectangle=await history.locator('.wh-chart canvas').evaluate(canvas=>{const c=Chart.getChart(canvas),b=canvas.getBoundingClientRect(),a=c.chartArea;return {x:b.x+a.left,y:b.y+a.top,w:a.width,h:a.height};});
    await page.mouse.move(rectangle.x+rectangle.w*.15,rectangle.y+rectangle.h*.15);await page.mouse.down({button:'left'});
    await page.mouse.move(rectangle.x+rectangle.w*.65,rectangle.y+rectangle.h*.8,{steps:8});await page.mouse.up({button:'left'});
    await page.waitForFunction(()=>!document.querySelector('.wh-zoom-controls button').disabled);
    const zoomed=await history.locator('.wh-chart canvas').evaluate(canvas=>{const c=Chart.getChart(canvas);return {xMin:c.scales.x.min,xMax:c.scales.x.max,yMin:c.scales.y.min,yMax:c.scales.y.max,visible:c.data.datasets.map((_,i)=>c.isDatasetVisible(i))};});
    assert.ok(zoomed.xMax-zoomed.xMin<2||zoomed.yMax-zoomed.yMin<800);
    assert.deepEqual(zoomed.visible,[true,true,true]);assert.equal(requests.length,zoomBefore);
    await history.getByRole('button',{name:'Restablecer zoom',exact:true}).click();
    assert.equal(await history.getByRole('button',{name:'Restablecer zoom',exact:true}).isDisabled(),true);
    assert.deepEqual(await history.locator('.wh-chart canvas').evaluate(canvas=>{const c=Chart.getChart(canvas);return [c.scales.x.min,c.scales.x.max];}),[0,2]);
    // El plugin ignora eventos durante 500 ms al finalizar el arrastre para evitar un clic accidental.
    await page.waitForTimeout(550);
    const type=history.getByLabel('Tipo de gráfico de tendencias',{exact:true});
    const queryCount=requests.length;
    await history.getByRole('button',{name:'FP',exact:true}).click();
    await type.selectOption('bar');
    assert.equal(await history.locator('.wh-chart canvas').evaluate(canvas=>Chart.getChart(canvas).config.type),'bar');
    assert.equal(await history.getByRole('button',{name:'FP',exact:true}).getAttribute('aria-pressed'),'false');
    await history.getByRole('button',{name:'Cambiar a tema oscuro',exact:true}).click();
    await page.waitForFunction(()=>getComputedStyle(document.querySelector('.wh-canvas')).backgroundColor==='rgb(21, 35, 45)');
    assert.equal(await history.locator('.wh-chart canvas').evaluate(canvas=>Chart.getChart(canvas).options.scales.y.grid.color),'#293e4b');
    assert.equal(await history.getByRole('button',{name:'FP',exact:true}).getAttribute('aria-pressed'),'false');
    await history.getByRole('button',{name:'Cambiar a tema claro',exact:true}).click();
    await page.waitForFunction(()=>getComputedStyle(document.querySelector('.wh-canvas')).backgroundColor==='rgb(255, 255, 255)');
    await type.selectOption('area');assert.equal(await history.locator('.wh-chart canvas').evaluate(canvas=>Chart.getChart(canvas).data.datasets[0].fill),true);
    await type.selectOption('step');assert.equal(await history.locator('.wh-chart canvas').evaluate(canvas=>Chart.getChart(canvas).data.datasets[0].stepped),true);
    await type.selectOption('line');await history.getByRole('button',{name:'Mostrar todas',exact:true}).click();
    assert.equal(requests.length,queryCount);
    assert.deepEqual(chartState[1].data,[0.85,null,0.85]);
    assert.deepEqual(chartState[2].data,[0,null,1]);
    await history.locator('.wh-chart canvas').scrollIntoViewIfNeeded();
    const location=await history.locator('.wh-chart canvas').evaluate(canvas=>{const chart=Chart.getChart(canvas),box=canvas.getBoundingClientRect();return {x:box.x+chart.scales.x.getPixelForValue(1),y:box.y+chart.chartArea.top+20};});
    await page.mouse.move(location.x,location.y);
    await page.waitForFunction(()=>document.querySelector('.wh-inspected-time').textContent.includes('01:00:00'));
    assert.ok((await history.locator('.wh-values').textContent()).includes('Sin calidad válida'));
    const dot=await history.locator('.wh-chart canvas').evaluate(canvas=>{const chart=Chart.getChart(canvas),box=canvas.getBoundingClientRect();return {x:box.x+chart.scales.x.getPixelForValue(0),y:box.y+chart.scales.y.getPixelForValue(750)};});
    await page.mouse.click(dot.x+2,dot.y);
    await page.waitForFunction(()=>document.querySelector('.wh-series').getAttribute('aria-pressed')==='false');
    assert.equal(await history.getByRole('button',{name:'QT:RPM · rpm',exact:true}).getAttribute('aria-pressed'),'false');
    await history.getByRole('button',{name:'Mostrar todas',exact:true}).click();
    const slider=history.getByRole('slider',{name:'Recorrer valores del histórico'});
    await slider.fill('2');await slider.dispatchEvent('input');
    assert.ok((await history.locator('.wh-inspected-time').textContent()).includes('02:00:00'));
    assert.ok((await history.locator('.wh-values').textContent()).includes('752'));
    assert.ok((await history.locator('.wh-tooltip').textContent()).includes('ALARMA'));
    assert.equal(await history.locator('.wh-chart canvas').evaluate(canvas=>Chart.getChart(canvas).$whIndex),2);
    await history.getByRole('button',{name:'FP',exact:true}).click();assert.equal(await history.getByRole('button',{name:'FP',exact:true}).getAttribute('aria-pressed'),'false');
    await history.getByRole('button',{name:'Mostrar todas',exact:true}).click();
    await history.getByLabel('Frecuencia en minutos',{exact:true}).fill('0.4');
    assert.equal(await history.getByLabel('Frecuencia en minutos',{exact:true}).evaluate(input=>input.validity.rangeUnderflow),true);
    await history.getByRole('button',{name:'Traer históricos',exact:true}).click();assert.equal(requests.length,1);
    await history.getByLabel('Frecuencia en minutos',{exact:true}).fill('60');
    const data=await history.locator('.wh-chart canvas').first().evaluate(canvas=>Chart.getChart(canvas).data.datasets[0].data);
    assert.deepEqual(data,[750,null,752]);
    const download=page.waitForEvent('download');await history.getByRole('button',{name:'Exportar Excel',exact:true}).click();
    const file=await download,downloadPath=await file.path();assert.ok(file.suggestedFilename().endsWith('.xlsx'));
    const entries=zipEntries(fs.readFileSync(downloadPath));assert.equal(Object.keys(entries).length,5);
    const sheet=entries['xl/worksheets/sheet1.xml'];assert.ok(sheet.includes('21/10/2026, 00:00'));assert.ok(sheet.includes('<v>750</v>'));assert.ok(sheet.includes('Frecuencia (minutos)'));assert.ok(sheet.includes('Calidad buena'));assert.ok(sheet.includes('NORMAL'));
    if(process.env.WELL_HISTORY_QA_DIR){
      fs.mkdirSync(process.env.WELL_HISTORY_QA_DIR,{recursive:true});
      fs.copyFileSync(downloadPath,path.join(process.env.WELL_HISTORY_QA_DIR,'historicos-'+(mobile?'movil':'escritorio')+'.xlsx'));
      if(mobile)await page.evaluate(()=>document.documentElement.dataset.theme='dark');
      await page.screenshot({path:path.join(process.env.WELL_HISTORY_QA_DIR,'historicos-'+(mobile?'movil':'escritorio')+'.png')});
    }
    await type.selectOption('bar');
    await history.getByRole('button',{name:'Agregar al reporte',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('.wh-status').textContent.includes('agregadas al reporte'));
    assert.equal(reports.length,1);assert.equal(reports[0].items.length,1);
    assert.equal(reports[0].items[0].payload.chart_type,'bar');await type.selectOption('line');
    assert.ok(reports[0].items[0].payload.image_data.startsWith('data:image/png;base64,'));
    assert.deepEqual(reports[0].items[0].payload.scale,{auto:true,independent:false});
    assert.match(reports[0].items[0].key,/^[a-z0-9_-]+:[a-f0-9]{40}$/);assert.equal(reports[0].items[0].payload.datasets.length,3);assert.deepEqual(reports[0].items[0].payload.datasets[0].values,[750,null,752]);
    // La leyenda oculta series sin borrar sus datos ni alterar Excel.
    await history.getByRole('button',{name:'FP',exact:true}).click();
    await history.getByRole('button',{name:'Agregar al reporte',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-wh-report]').disabled===false);
    assert.equal(reports[1].items[0].payload.datasets.length,2);
    await history.locator('.wh-chart canvas').evaluate(canvas=>{const chart=Chart.getChart(canvas);chart.data.datasets.forEach((item,index)=>chart.hide(index));});
    await history.getByRole('button',{name:'Agregar al reporte',exact:true}).click();
    assert.equal(reports.length,2);assert.ok((await history.locator('.wd-history-host>.wh-status').textContent()).includes('Mostrá al menos'));
    await history.getByLabel('Frecuencia en minutos',{exact:true}).fill('0.5');
    await history.getByLabel('Histórico desde',{exact:true}).fill('2026-10-01T00:00');
    await history.getByLabel('Histórico hasta',{exact:true}).fill('2026-10-02T00:00');
    await history.getByRole('button',{name:'Traer históricos',exact:true}).click();
    await page.waitForFunction(()=>document.querySelectorAll('.wh-chart canvas').length===1&&document.querySelector('[data-wh-export]').disabled===false);
    assert.equal(requests[1].intervalSeconds,30);assert.equal(requests[1].from,'2026-10-01T00:00');assert.equal(requests[1].to,'2026-10-02T00:00');
    fail=true;await history.getByRole('button',{name:'Traer históricos',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('.wh-status').textContent.includes('31 días'));
    assert.equal(await history.locator('.wh-chart canvas').count(),0);assert.equal(await history.getByRole('button',{name:'Exportar Excel',exact:true}).isDisabled(),true);
    fail=false;const beforeSelection=requests.length;
    await detail.getByRole('checkbox',{name:'Seleccionar FP para histórico',exact:true}).uncheck();
    assert.equal(requests.length,beforeSelection);assert.equal(await history.locator('.wh-chart canvas').count(),0);
    await detail.getByRole('button',{name:'Histórico (2)',exact:true}).click();
    await page.waitForFunction(()=>document.querySelectorAll('.wh-chart canvas').length===1);
    assert.deepEqual(requests.at(-1).columns,['QT:RPM','ECO']);
    fail=false;delay=true;await history.getByRole('button',{name:'Traer históricos',exact:true}).click();await history.getByRole('button',{name:'Cerrar ficha del pozo',exact:true}).click();
    assert.equal(await history.isVisible(),false);assert.equal(await detail.isVisible(),false);
    await page.locator('td[data-column=POZO]').click();
    await page.waitForFunction(()=>document.querySelectorAll('.wh-pick input:enabled').length===5);
    assert.equal(await detail.getByRole('checkbox',{name:'Seleccionar QT:RPM para histórico',exact:true}).isChecked(),true);
    assert.deepEqual(errors,[]);
  }finally{await browser.close();}
});
test('PCP integra ficha, históricos y alarmas con su fuente y sólo variables verificadas',async()=>{
  const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'msedge'});
  try{
    for(const communicationVerified of [true,false]){
      const page=await browser.newPage();
      const values={POZO:'YPF.SC.PC-101',BATERIA:'PC 01','YT:POZO':'PARADO','PT:FONDO':'0',__COMUNICACION:'Comunicando',PLANTILLA:'PC12',ALM:'2'};
      const settings={...config,key:'telemetria_pcp',title:'Telemetría PCP',wellHistoryReport:false,wellAlarms:true,wellCommentView:false};
      const columns={};
      for(const column of ['YT:POZO','PT:FONDO','__COMUNICACION'])columns[column]={available:true,tag:values.POZO+'(PCP)_'+(column==='__COMUNICACION'?'YAT:COM':column)+'.PV',unit:column==='PT:FONDO'?'kg/cm2':'',type:column==='PT:FONDO'?'Float32':'Digital'};
      if(!communicationVerified)columns.__COMUNICACION={available:false,reason:'Sin tag verificado'};
      const gets=[],posts=[],alarms=[],errors=[];
      page.on('pageerror',error=>errors.push(error.message));
      await page.route('http://clear.test/**',async route=>{
        const request=route.request(),url=new URL(request.url());
        if(url.pathname.endsWith('/well_history_api.php')){
          if(request.method()==='GET'){gets.push(Object.fromEntries(url.searchParams));return route.fulfill({json:{ok:true,columns}});}
          const body=request.postDataJSON();posts.push(body);
          if(body.action==='cards')return route.fulfill({status:422,json:{ok:false,error:'PCP no tiene cartas dinamométricas.'}});
          return route.fulfill({json:{ok:true,well:values.POZO,intervalSeconds:body.intervalSeconds,start:'2026-10-21T00:00:00-03:00',end:'2026-10-22T00:00:00-03:00',series:body.columns.map(column=>({column,...columns[column],step:column!=='PT:FONDO',samples:[0,1,2].map(hour=>({timestamp:'2026-10-21T0'+(hour+3)+':00:00Z',value:column==='YT:POZO'?(hour?'EN MARCHA':'PARADO'):column==='PT:FONDO'?[0,12.5,13][hour]:'NORMAL',plot_value:column==='YT:POZO'?(hour?7:0):column==='PT:FONDO'?[0,12.5,13][hour]:0,good:column!=='YT:POZO'||hour!==1,questionable:false,substituted:false}))}))}});
        }
        if(url.pathname.endsWith('/well_activity_api.php')){
          alarms.push(Object.fromEntries(url.searchParams));return route.fulfill({json:{ok:true,count24h:2,latest:{description:'Estado del pozo',value:'PARADO',status:'ACTIVA',started_at:'21/10/2026 00:00',last_at:'21/10/2026 02:00'}}});
        }
        if(url.pathname==='/telemetria_pcp.php')return route.fulfill({contentType:'text/html; charset=utf-8',body:fixture(values,settings)});
        return route.fulfill({status:404,body:''});
      });
      await page.goto('http://clear.test/telemetria_pcp.php');
      for(const file of ['chart.umd.js','chartjs-plugin-zoom.min.js','well_history.js','well_activity.js','well_details.js'])await page.addScriptTag({path:path.join(root,'assets/js',file)});
      await page.locator('td[data-column=POZO]').click();
      await page.waitForFunction(()=>document.querySelector('.wh-chart canvas')&&!document.querySelector('[data-wh-export]').disabled&&document.querySelector('.wd-alarm-bell strong')?.textContent==='2');
      const detail=page.locator('dialog.well-details:not(.wh-dialog)');
      const expected=communicationVerified?['YT:POZO','PT:FONDO','__COMUNICACION']:['YT:POZO','PT:FONDO'];
      assert.deepEqual(gets,[{well:values.POZO,source:'telemetria_pcp'}]);
      assert.deepEqual(alarms,[{well:values.POZO,source:'telemetria_pcp'}]);
      assert.equal(posts.length,1);assert.equal(posts[0].source,'telemetria_pcp');assert.equal(posts[0].well,values.POZO);assert.deepEqual(posts[0].columns,expected);
      assert.equal(posts.some(item=>item.action==='cards'),false);assert.equal(await detail.locator('.wd-cards-host').count(),0);
      assert.equal(await detail.locator('[data-history-column="PLANTILLA"] input').count(),0);
      const communication=detail.locator('[data-history-column="__COMUNICACION"] input');
      assert.equal(await communication.isChecked(),communicationVerified);assert.equal(await communication.isDisabled(),!communicationVerified);
      assert.deepEqual(await detail.locator('.wh-pick input:checked').evaluateAll(nodes=>nodes.map(node=>node.closest('[data-history-column]').dataset.historyColumn).sort()),[...expected].sort());
      const chart=await detail.locator('.wh-chart canvas').evaluate(canvas=>Chart.getChart(canvas).data.datasets.map(dataset=>({column:dataset.historySeries.column,data:dataset.data,values:dataset.historySamples.map(sample=>sample.value),step:dataset.stepped})));
      assert.deepEqual(chart.find(item=>item.column==='YT:POZO'),{column:'YT:POZO',data:[0,null,7],values:['PARADO','EN MARCHA','EN MARCHA'],step:true});
      assert.deepEqual(chart.find(item=>item.column==='PT:FONDO').data,[0,12.5,13]);
      assert.ok((await detail.locator('.wh-values').textContent()).includes('PARADO'));
      assert.ok((await detail.locator('.wh-values').textContent()).includes('0,00'));
      assert.equal(await detail.locator('[data-wh-report]').isVisible(),false);
      assert.deepEqual(errors,[]);await page.close();
    }
  }finally{await browser.close();}
});
test('el permiso de histórico desactiva la función',async()=>{
  const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'msedge'});
  try{const page=await browser.newPage();await page.setContent('<script>window.CLEAR_TELEMETRY_GRID={key:"monitoreo_pozos",wellHistory:false}</script>');await page.addScriptTag({path:path.join(root,'assets/js/well_history.js')});assert.equal(await page.locator('.wh-dialog').count(),0);}finally{await browser.close();}
});
