const {test}=require('node:test');
const assert=require('node:assert/strict');
const {chromium}=require('playwright');
const path=require('node:path');
const fs=require('node:fs');
const root=path.join(__dirname,'..');
test('comentario editable, alarma y campana del pozo',async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 try{
  const page=await browser.newPage();let saved='Pendiente: falla sensor RPM',posted='';
  await page.route('http://clear.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname.endsWith('alarm_events_api.php')){if(route.request().method()==='POST'){posted=route.request().postData();saved='Sensor revisado';await route.fulfill({json:{ok:true}});}else await route.fulfill({json:{ok:true,comment:saved,author:'Operador CLEAR'}});}
   else if(url.pathname.endsWith('well_activity_api.php'))await route.fulfill({json:{ok:true,count24h:3,latest:{description:'Estado Comunicación',started_at:'2026-10-22 18:00:43',last_at:'2026-10-22 18:05:00',value:'FALLA',status:'ALM'}}});
   else await route.fulfill({contentType:'text/html',body:`<html><head><style>${fs.readFileSync(path.join(root,'assets/css/well_details.css'),'utf8')}</style></head><body><table id="tgTable"><thead><tr><th data-column="POZO">POZO</th><th data-column="COMENTARIO">COMENTARIO</th><th data-column="ALM">ALM</th></tr></thead><tbody><tr><td data-column="POZO" data-raw="YPF.SC.BB-106">YPF.SC.BB-106</td><td data-column="COMENTARIO"><span data-comment-subject="POZO:YPF.SC.BB-106" data-comment-preview>Anterior</span></td><td data-column="ALM" data-raw="3"><button data-alarm-comment="true" data-subject="POZO:YPF.SC.BB-106" data-event-key="existing-key" data-can-create="1">Comentario</button></td></tr></tbody></table><script>window.CLEAR_TELEMETRY_GRID={key:'monitoreo_pozos',wellCommentView:true,wellAlarms:true,wellAlarmsHistory:true};document.addEventListener('click',e=>{if(e.target.closest('[data-telemetry-modal-url]')){e.preventDefault();window.historyUrl=e.target.closest('a').dataset.telemetryModalUrl;}});</script></body></html>`});
  });
  await page.goto('http://clear.test/monitoreo_pozos.php');
  await page.addScriptTag({path:path.join(root,'assets/js/well_activity.js')});await page.addScriptTag({path:path.join(root,'assets/js/well_details.js')});
  await page.locator('[data-column="POZO"]').last().click();const dialog=page.locator('dialog');
  await page.waitForFunction(()=>document.querySelector('.wd-comment-preview')?.textContent.includes('falla sensor'));
  assert.equal(await dialog.locator('.wd-alarm-bell strong').textContent(),'3');assert.ok((await dialog.locator('.wd-last-alarm').textContent()).includes('FALLA'));
  await dialog.getByRole('button',{name:'Editar comentario',exact:true}).click();
  assert.equal(await dialog.locator('textarea').inputValue(),saved);
  await dialog.locator('textarea').fill('Sensor revisado');await dialog.locator('.wd-comment-form input').fill('Operador CLEAR');await dialog.getByRole('button',{name:'Guardar comentario'}).click();
  await page.waitForFunction(()=>document.querySelector('.wd-activity-status')?.textContent==='Comentario guardado.');
  assert.ok(posted.includes('POZO:YPF.SC.BB-106'));assert.ok(posted.includes('existing-key'));assert.equal(await page.locator('#tgTable [data-comment-preview]').textContent(),'Sensor revisado');
  await dialog.locator('.wd-alarm-bell').click();assert.equal(await dialog.isVisible(),false);
  const url=await page.evaluate(()=>window.historyUrl);assert.ok(url.startsWith('pozos_todas_alarmas.php?'));assert.ok(url.includes('pozo_exacto=1'));assert.ok(url.includes('pozo=YPF.SC.BB-106'));
  await page.evaluate(()=>{window.CLEAR_TELEMETRY_GRID.wellCommentView=false;window.CLEAR_TELEMETRY_GRID.wellAlarms=false;document.querySelector('[data-alarm-comment]').dataset.canCreate='0';});
  await page.locator('#tgTable td[data-column="POZO"]').click();
  assert.equal(await dialog.locator('.wd-comment-preview').textContent(),'Sin permiso para ver comentarios');assert.equal(await dialog.locator('.wd-activity textarea').count(),0);assert.equal(await dialog.locator('.wd-alarm-bell').count(),0);
 }finally{await browser.close();}
});
