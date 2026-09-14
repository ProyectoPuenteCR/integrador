import fs from 'node:fs';import path from 'node:path';import http from 'node:http';import assert from 'node:assert/strict';import {pipeline} from 'node:stream/promises';import {createBrotliDecompress} from 'node:zlib';
import tar from '../patch_test_tools/node_modules/tar-fs/index.js';
import {chromium} from '/opt/codex/runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs';
const out=path.resolve('patch_validation/browser_runtime');fs.mkdirSync(out,{recursive:true});
const bin=path.resolve('patch_test_tools/node_modules/@sparticuz/chromium/bin');
if(!fs.existsSync(out+'/chromium'))await pipeline(fs.createReadStream(bin+'/chromium.br'),createBrotliDecompress(),fs.createWriteStream(out+'/chromium',{mode:0o700}));
if(!fs.existsSync(out+'/libEGL.so'))await pipeline(fs.createReadStream(bin+'/swiftshader.tar.br'),createBrotliDecompress(),tar.extract(out));
const catalog=JSON.parse(fs.readFileSync('patch_validation/catalog.json','utf8')),report=new Map(),requests=[];
const server=http.createServer(async(req,res)=>{
 const u=new URL(req.url,'http://localhost');const send=(data)=>{res.setHeader('Content-Type','application/json');res.end(JSON.stringify(data));};
 if(u.pathname.endsWith('novedades_gestion_api.php')){
  if(u.searchParams.get('accion')==='catalogo')return send({ok:true,catalog});
  let body='';for await(const c of req)body+=c;requests.push({path:req.url,body});
  if(u.searchParams.get('accion')==='guardar_responsable'){const name=(body.match(/name="NOMBRE"\r\n\r\n([^\r]*)/)||[])[1];if(!name)return send({ok:false,error:'Nombre vacío'});const existing=catalog.people.GUARDADO.includes(name);if(!existing)catalog.people.GUARDADO.push(name);return send({ok:true,result:{id:2,nombre:name,existing}});}
  if(u.searchParams.get('accion')==='historial')return send({ok:true,history:{rows:[],more:false}});
  // Validation stop: keep the form visible and inspect the real multipart body.
  return send({ok:false,error:'PRUEBA: formulario recibido sin escribir en SQL real.'});
 }
 if(u.pathname.endsWith('novedades_semanales_report_api.php')){
  if(req.method==='GET')return send({ok:true,keys:[...report.keys()],count:report.size});
  let b='';for await(const c of req)b+=c;const x=JSON.parse(b);if(x.action==='batch')for(const i of x.items)report.set(i.key,i);if(x.action==='toggle'){if(x.active)report.set(x.key,x);else report.delete(x.key);}return send({ok:true,count:report.size,processed:(x.items||[]).length,message:'Agregado al reporte.'});
 }
 if(u.pathname.includes('user_prefs_api'))return send({ok:true,value:'{}'});
 if(u.pathname==='/novedades_semanales_auditoria.php'||u.pathname==='/novedades_semanales_requerimientos.php'){
  const f=u.pathname.includes('auditoria')?'AUDITORIA':'REQUERIMIENTO';res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(fs.readFileSync('patch_validation/'+f+'.html'));
 }
 if(u.pathname==='/novedades_semanales_reporte.php'){res.setHeader('Content-Type','text/html');return res.end('<h1>Reporte de prueba</h1>');}
 if(u.pathname.startsWith('/assets/')){const file=path.resolve('clear_patch_work/clear','.'+u.pathname);if(file.startsWith(path.resolve('clear_patch_work/clear/assets')+'/')&&fs.existsSync(file)){const ext=path.extname(file);res.setHeader('Content-Type',({'.js':'text/javascript','.css':'text/css','.woff2':'font/woff2','.svg':'image/svg+xml','.png':'image/png','.jpg':'image/jpeg'})[ext]||'application/octet-stream');return res.end(fs.readFileSync(file));}}
 res.writeHead(404);res.end('not found');
});await new Promise(r=>server.listen(0,'127.0.0.1',r));
const url='http://127.0.0.1:'+server.address().port;
const browser=await chromium.launch({executablePath:out+'/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--use-gl=angle','--use-angle=swiftshader','--enable-unsafe-swiftshader'],env:{...process.env,LD_LIBRARY_PATH:out}});
const page=await browser.newPage({viewport:{width:1440,height:1100}}),errors=[],checks=[];
page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
const ok=(v,l)=>{assert.ok(v,l);checks.push(l);};
const options=async selector=>page.locator(selector+' option').evaluateAll(os=>os.map(o=>o.value).filter(Boolean));
try{
 await page.goto(url+'/novedades_semanales_requerimientos.php');await page.waitForFunction(()=>!!window.CLEAR_NS_REPORT);await page.waitForTimeout(150);
 ok(await page.locator('[data-ng-row="0"]').isVisible(),'pending row visible');ok(!await page.locator('[data-ng-row="1"]').isVisible(),'completed default hidden');
 await page.click('#ngAdd');await page.waitForFunction(()=>document.getElementById('ngCatalogStatus').textContent.includes('usuarios activos'));
 await page.selectOption('[name=RESPONSABLE_TIPO]','SUPERVISOR');ok((await options('[name=RESPONSABLE]')).includes('Ana Campo'),'supervisor candidates loaded');
 await page.selectOption('[name=RESPONSABLE]','Ana Campo');ok(JSON.stringify(await options('[name=JEFE_PRODUCCION]'))===JSON.stringify(['Jefa Norte','Jefe Sur']),'supervisor with two chiefs filters correctly');
 await page.selectOption('[name=JEFE_PRODUCCION]','Jefe Sur');
 await page.selectOption('[name=RESPONSABLE]','Bruno Campo');ok(await page.inputValue('[name=JEFE_PRODUCCION]')==='Jefa Norte','unique chief autocompletes');
 await page.selectOption('[name=RESPONSABLE_TIPO]','JEFE_PRODUCCION');await page.selectOption('[name=RESPONSABLE]','Jefa Norte');
 ok(JSON.stringify(await options('#ngRelatedSupervisors'))===JSON.stringify(['Ana Campo','Bruno Campo']),'chief filters supervisor list');
 await page.selectOption('#ngRelatedSupervisors',['Ana Campo','Bruno Campo']);ok(await page.locator('#ngRelatedSupervisors option:checked').count()===2,'multiple supervisors selected');
 await page.fill('[name=REQUERIMIENTO]','Revisión de comunicación de la instalación');await page.fill('[name=OBSERVACIONES]','Validación con datos de demostración.');
 await page.screenshot({path:'patch_validation/requerimientos.png',fullPage:true});
 await page.click('#ngSave');await page.waitForSelector('#ngError:not([hidden])');
 const posted=requests.at(-1).body;ok((posted.match(/name="SUPERVISORES\[\]"/g)||[]).length===2,'multipart includes all selected supervisors');ok(posted.includes('JEFE_PRODUCCION'),'multipart preserves responsible type');
 await page.selectOption('[name=RESPONSABLE]','Jefe Sur');ok(JSON.stringify(await options('#ngRelatedSupervisors'))===JSON.stringify(['Ana Campo','Carlos Campo']),'chief change replaces choices');ok(await page.locator('#ngRelatedSupervisors option:checked').count()===0,'chief change clears previous selections');
 await page.selectOption('[name=RESPONSABLE_TIPO]','PLATAFORMA');ok(JSON.stringify(await options('[name=RESPONSABLE]'))===JSON.stringify(['operador1','tester']),'active platform users');ok(!await page.locator('#ngRelatedSupervisorsControl').isVisible(),'platform hides hierarchy');
 await page.selectOption('[name=RESPONSABLE_TIPO]','GUARDADO');await page.fill('#ngNewResponsible','Nuevo Responsable');await page.click('#ngSaveResponsible');await page.waitForFunction(()=>document.getElementById('ngResponsibleStatus').textContent.includes('guardado.'));
 ok(await page.inputValue('[name=RESPONSABLE]')==='Nuevo Responsable','new saved contact selected');
 await page.fill('#ngNewResponsible','Nuevo Responsable');await page.click('#ngSaveResponsible');await page.waitForFunction(()=>document.getElementById('ngResponsibleStatus').textContent.includes('Ya existía'));
 ok((await options('[name=RESPONSABLE]')).filter(x=>x==='Nuevo Responsable').length===1,'contact not duplicated');
 await page.reload();await page.click('#ngAdd');await page.waitForFunction(()=>document.getElementById('ngCatalogStatus').textContent.includes('usuarios activos'));await page.selectOption('[name=RESPONSABLE_TIPO]','GUARDADO');ok((await options('[name=RESPONSABLE]')).includes('Nuevo Responsable'),'contact loaded after refresh');
 await page.click('#ngCancel');await page.click('#ngReset');await page.click('[data-ng-edit="1"]');ok(await page.inputValue('[name=RESPONSABLE]')==='Nombre anterior','legacy contact preserved');ok(await page.inputValue('[name=RESPONSABLE_TIPO]')==='LEGADO','legacy type preserved');
 await page.click('#ngCancel');await page.click('[data-ng-edit="0"]');ok(await page.locator('#ngRelatedSupervisors option:checked').count()===2,'editing restores multi selection');
 await page.setInputFiles('#ngFile',{name:'too-large.pdf',mimeType:'application/pdf',buffer:Buffer.alloc(5242881)});ok(!(await page.locator('#ngFile').evaluate(e=>e.validity.valid)),'5 MB limit enforced in browser');await page.click('#ngClearFile');
 await page.setInputFiles('#ngFile',{name:'allowed.pdf',mimeType:'application/pdf',buffer:Buffer.alloc(5242880)});ok(await page.locator('#ngFile').evaluate(e=>e.validity.valid),'exact 5 MiB accepted in browser');await page.click('#ngClearFile');await page.click('#ngCancel');
 await page.selectOption('[data-ng-filter=RESPONSABLE_CLASE]','Jefe de producción');await page.check('#ngSelectAll');await page.click('#ngReport');await page.waitForFunction(()=>document.getElementById('ngStatus').textContent.includes('agregado'));
 ok(report.size===1,'add selected visible rows only');const item=[...report.values()][0];ok(item.payload.columns.SUPERVISORES==='Ana Campo; Bruno Campo','report includes multiple supervisors');ok(item.payload.columns['TIPO DE RESPONSABLE']==='Jefe de producción','report includes responsible type');
 await page.click('[data-ns-report-open]');ok(await page.locator('.nsReportPopup').isVisible(),'report popup opens');await page.click('.nsReportPopup button[aria-label="Cerrar reporte"]');
 await page.click('[data-ns-columns-toggle]');ok(await page.locator('[data-ns-columns-menu] input[type=checkbox]').count()>=10,'columns menu available');await page.click('[data-ns-columns-toggle]');
 await page.goto(url+'/novedades_semanales_auditoria.php');await page.click('#ngAdd');await page.waitForFunction(()=>document.getElementById('ngCatalogStatus').textContent.includes('usuarios activos'));
 await page.selectOption('[name=ZONA]','LHCG');await page.selectOption('[name=JEFE_PRODUCCION]','Jefa Norte');ok(JSON.stringify(await options('[name=SUPERVISOR]'))===JSON.stringify(['Ana Campo','Bruno Campo']),'audit chief filters supervisors');await page.selectOption('[name=SUPERVISOR]','Bruno Campo');ok(JSON.stringify(await options('[name=JEFE_PRODUCCION]'))===JSON.stringify(['Jefa Norte']),'audit supervisor filters chief');
 await page.selectOption('[name=ZONA]','CED I');ok(await page.inputValue('[name=SUPERVISOR]')===''&&await page.inputValue('[name=JEFE_PRODUCCION]')==='','zone clears both assignments');await page.fill('[name=BATERIA]','BAT 04');await page.locator('[name=BATERIA]').dispatchEvent('change');ok(await page.inputValue('[name=SUPERVISOR]')==='Carlos Campo'&&await page.inputValue('[name=JEFE_PRODUCCION]')==='Jefe Sur','installation fills assignment');
 await page.screenshot({path:'patch_validation/auditoria.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});ok(await page.locator('#ngFormPanel').isVisible(),'mobile form visible');const size=await page.evaluate(()=>({w:innerWidth,d:document.documentElement.scrollWidth}));ok(size.d<=size.w+2,'mobile no page horizontal overflow');
 ok(errors.length===0,'no browser JavaScript exceptions: '+errors.join(' | '));
 console.log('Browser checks passed:',checks.length);fs.writeFileSync('patch_validation/browser-results.json',JSON.stringify({checks,errors,report:[...report.values()]},null,2));
}catch(e){await page.screenshot({path:'patch_validation/failure.png',fullPage:true});console.error('Browser failure:',e,errors);process.exitCode=1;}
finally{await browser.close();server.close();}
