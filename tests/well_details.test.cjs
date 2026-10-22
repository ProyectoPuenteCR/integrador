// Requiere Playwright y Microsoft Edge. Ejecutar: node --test tests/well_details.test.cjs
const {test}=require('node:test');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const {chromium}=require('playwright');
const root=path.join(__dirname,'..');
const gridStyle=fs.readFileSync(path.join(root,'includes/telemetry_grid.php'),'utf8').match(/<style>([\s\S]*?)<\/style>/)[1].split('<?php')[0];
const escape=value=>String(value).replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;');
const fields={POZO:'YPF.SC.BB-113',BATERIA:'CE 22',ALM:'0',__COMUNICACION:'Comunicando',ESTADO:'Bombeo normal',PRODUCCION_PETROLEO:'0,20','PT:LINEA':'12,4',FP:'0','FT:AGUA_ACU':'0.79493647813797','ZT:CARRERA-ACTUAL':'149.41000366211',Fecha:'',COMENTARIO:'Información restringida',PANTALLA:'https://screen.example/',CARTAS:'https://cartas.example/'};
function html(key='monitoreo_pozos'){
  const columns=Object.keys(fields);
  return `<!doctype html><html lang="es"><head><meta charset="utf-8"><style>${gridStyle}${fs.readFileSync(path.join(root,'assets/css/app.css'),'utf8')}${fs.readFileSync(path.join(root,'assets/css/well_details.css'),'utf8')}</style></head><body><table class="tg-table" id="tgTable"><thead><tr>${columns.map(c=>`<th data-column="${c}" data-report-label="${c==='__COMUNICACION'?'COM':c}">${c}</th>`).join('')}</tr></thead><tbody>${['YPF.SC.BB-113','Otro <pozo>'].map(well=>`<tr>${columns.map(c=>`<td data-column="${c}" data-raw="${escape(c==='POZO'?well:fields[c])}" ${c==='PT:LINEA'?'style="display:none"':''}>${c==='PANTALLA'||c==='CARTAS'?`<a href="${fields[c]}" data-telemetry-popup-url="${fields[c]}">Abrir</a>`:c==='COMENTARIO'?'<span>Sin permiso</span>':c==='ALM'?'<a class="tg-alarm-link" href="/alarmas" data-telemetry-modal-url="/alarmas?embed=1">0</a><button class="comment">Comentario</button><input type="checkbox">':c==='__COMUNICACION'?'<span class="tg-badge info" style="color:rgb(20, 90, 120)">Comunicando</span>':escape(c==='POZO'?well:fields[c])}</td>`).join('')}</tr>`).join('')}</tbody></table><script>window.CLEAR_TELEMETRY_GRID={key:'${key}',title:'Monitoreo Pozos',batteryColumn:'BATERIA'};</script></body></html>`;
}
for(const mobile of [false,true])test(`ficha de pozo: ${mobile?'móvil':'escritorio'}`,async()=>{
  const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'msedge'});
  try{
    const page=await browser.newPage({viewport:mobile?{width:390,height:844}:{width:1440,height:1000}});
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.setContent(html());await page.addScriptTag({path:path.join(root,'assets/js/well_details.js')});
    const row=page.locator('#tgTable tbody tr').first(),dialog=page.locator('dialog.well-details');
    assert.equal(await dialog.isVisible(),false);
    await row.locator('.comment').dblclick();assert.equal(await dialog.isVisible(),false);
    await row.locator('td[data-column=POZO]').click();assert.equal(await dialog.isVisible(),true);
    assert.equal(await dialog.locator('h2').textContent(),'YPF.SC.BB-113');
    const text=await dialog.textContent();
    for(const expected of ['Identificación','Operación','Producción','Variables de telemetría','PT:LINEA','12,4','Sin dato','Sin permiso','0,20'])assert.ok(text.includes(expected),expected);
    assert.ok(!text.includes('Información restringida'));
    assert.equal(await dialog.locator('[data-history-column="FT:AGUA_ACU"] dd').textContent(),'0,79');
    assert.equal(await dialog.locator('[data-history-column="ZT:CARRERA-ACTUAL"] dd').textContent(),'149,41');
    assert.equal(await dialog.locator('[data-history-column="FP"] dd').textContent(),'0,00');
    assert.equal(await row.locator('[data-column="FT:AGUA_ACU"]').getAttribute('data-raw'),'0.79493647813797');
    const groups=await dialog.locator('.wd-data-groups .well-details__section').evaluateAll(nodes=>nodes.map(node=>{const r=node.getBoundingClientRect();return {top:r.top,bottom:r.bottom,width:r.width};}));
    assert.ok(groups.every((group,index)=>!index||group.top>=groups[index-1].bottom));
    assert.ok(groups.every(group=>Math.abs(group.width-groups[0].width)<2));
    assert.equal(await dialog.locator('.well-details__field').count(),Object.keys(fields).length-2);
    assert.equal(await dialog.locator('.tg-badge').textContent(),'Comunicando');
    assert.equal(await dialog.locator('a[data-telemetry-popup-url]').count(),3);
    assert.equal(await dialog.locator('.well-details__screen svg').count(),1);
    assert.equal(await dialog.locator('.well-details__comm-fail').count(),0);
    assert.equal(await dialog.locator('.well-details__pump').getAttribute('src'),'assets/img/telemetry/monitoreo_pozos.png');
    const bounds=await dialog.boundingBox();assert.ok(Math.abs(bounds.width-(mobile?390:1440))<2);assert.ok(Math.abs(bounds.height-(mobile?844:1000))<2);
    await page.keyboard.press('Escape');assert.equal(await dialog.isVisible(),false);
    assert.equal(await row.evaluate(node=>node===document.activeElement),true);
    await page.keyboard.press('Enter');assert.equal(await dialog.isVisible(),true);
    await dialog.locator('[aria-label="Cerrar ficha del pozo"]').click();
    await page.locator('#tgTable tbody tr').nth(1).evaluate(row=>row.dataset.commStatus='Sin comunicación');
    await page.locator('#tgTable tbody tr').nth(1).focus();await page.keyboard.press('Enter');
    assert.equal(await dialog.locator('h2').textContent(),'Otro <pozo>');
    assert.equal(await dialog.locator('pozo').count(),0);
    assert.equal(await dialog.locator('[aria-label="Falla de comunicación"]').count(),1);
    assert.equal(await dialog.locator('.well-details__comm-fail').evaluate(node=>getComputedStyle(node).animationName),'wd-comm-pulse');
    assert.ok((await dialog.locator('.well-details__comm-fail').boundingBox()).width>=(mobile?48:64));
    await page.emulateMedia({reducedMotion:'reduce'});assert.equal(await dialog.locator('.well-details__comm-fail').evaluate(node=>getComputedStyle(node).animationName),'none');
    await page.evaluate(()=>document.addEventListener('click',event=>{if(event.target.closest('[data-telemetry-modal-url]')){event.preventDefault();window.alarmOpened=true;}}));
    await dialog.locator('a[data-telemetry-modal-url]').click();
    assert.equal(await dialog.isVisible(),false);assert.equal(await page.evaluate(()=>window.alarmOpened),true);
    await row.focus();await page.keyboard.press('Enter');
    await page.keyboard.press('Escape');assert.equal(await dialog.isVisible(),false);
    await page.waitForFunction(()=>!document.body.classList.contains('well-details-open'));
    assert.equal(await page.locator('body').evaluate(node=>node.classList.contains('well-details-open')),false);
    await page.evaluate(()=>document.documentElement.dataset.theme='dark');
    await row.focus();await page.keyboard.press('Enter');
    const close=dialog.locator('[aria-label="Cerrar ficha del pozo"]');await close.focus();await page.keyboard.press('Shift+Tab');
    assert.equal(await dialog.evaluate(node=>node.contains(document.activeElement)),true);
    assert.deepEqual(errors,[]);
  }finally{await browser.close();}
});
test('otras telemetrías no reciben la ficha',async()=>{
  const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'msedge'});
  try{const page=await browser.newPage();await page.setContent(html('telemetria_pcp'));await page.addScriptTag({path:path.join(root,'assets/js/well_details.js')});assert.equal(await page.locator('dialog').count(),0);assert.equal(await page.locator('.well-details-row').count(),0);}finally{await browser.close();}
});
