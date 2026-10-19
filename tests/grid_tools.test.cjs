// Requiere jsdom: npm install --no-save jsdom@26.1.0
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '..', 'assets/js/grid_tools.js'), 'utf8');
const key = 'clear_global_grid_columns_v1';
const tick = () => new Promise(resolve => setTimeout(resolve, 35));
const table = (id='alarms', cls='') => `<table id="${id}" class="${cls}"><thead><tr><th>TAG ↕</th><th>Comentario</th></tr><tr><th><input></th><th><input></th></tr></thead><tbody><tr><td>BLH002_SBC01_PT001</td><td>Presión alta</td></tr><tr><td colspan="2">Sin datos</td></tr></tbody></table>`;

async function boot(html, stored={}, fetcher, withTopbar=true) {
  const dom = new JSDOM(`${withTopbar?'<button id="gridToolsBtn" hidden>Columnas</button>':''}<main>${html}</main>`, {url:'https://clear.example/instalaciones_top20.php', runScripts:'outside-only', pretendToBeVisual:true});
  const observers=[], NativeObserver=dom.window.MutationObserver;
  dom.window.MutationObserver=class extends NativeObserver {constructor(callback){super(callback);observers.push(this);}};
  dom.close=()=>{observers.forEach(observer=>observer.disconnect());dom.window.close();};
  dom.window.localStorage.setItem(key, JSON.stringify(stored));
  dom.window.fetch = fetcher || (() => Promise.resolve({json:async()=>({ok:true})}));
  dom.window.eval(source);
  await tick();
  return dom;
}
function toggle(dom, name, checked) {
  const label = [...dom.window.document.querySelectorAll('[data-grid-list] label')].find(item => item.textContent === name);
  assert.ok(label, 'Debe existir la opción '+name);
  const input = label.querySelector('input');
  input.checked = checked;
  input.dispatchEvent(new dom.window.Event('change', {bubbles:true}));
  return input;
}

test('muestra controles en tablas sin clase grid y oculta encabezado, filtro y datos', async t => {
  const dom = await boot(table());t.after(()=>dom.close());
  const doc=dom.window.document;
  assert.equal(doc.querySelectorAll('.clearGridColumnsToggle').length,1);
  doc.querySelector('.clearGridColumnsToggle').click();
  toggle(dom,'Comentario',false);
  [...doc.querySelector('#alarms').rows].slice(0,3).forEach(row=>assert.equal(row.cells[1].style.display,'none'));
  assert.equal(doc.querySelector('#alarms tbody tr:last-child td').colSpan,1);
  toggle(dom,'TAG',false); // No permite ocultar la última columna.
  assert.equal(doc.querySelector('#alarms th').style.display,'');
  doc.querySelector('[data-grid-all]').click();
  assert.equal(doc.querySelector('#alarms tbody tr:last-child td').colSpan,2);
});

test('mantiene las preferencias separadas para varias grillas y al volver a cargar', async t => {
  const dom=await boot(table('one')+table('two'));t.after(()=>dom.close());
  dom.window.document.querySelector('.clearGridColumnsToggle').click();toggle(dom,'Comentario',false);
  assert.equal(dom.window.document.querySelector('#two tr').cells[1].style.display,'');
  const stored=JSON.parse(dom.window.localStorage.getItem(key));
  const next=await boot(table('one')+table('two'),stored);t.after(()=>next.close());
  assert.equal(next.window.document.querySelector('#one tr').cells[1].style.display,'none');
  assert.equal(next.window.document.querySelector('#two tr').cells[1].style.display,'');
});

test('reaplica la selección a filas nuevas y a tablas reemplazadas por AJAX', async t => {
  const dom=await boot(table());t.after(()=>dom.close());const doc=dom.window.document;
  doc.querySelector('.clearGridColumnsToggle').click();toggle(dom,'Comentario',false);
  doc.querySelector('tbody').insertAdjacentHTML('beforeend','<tr><td>Otro TAG</td><td>Nuevo</td></tr>');await tick();
  assert.equal(doc.querySelector('tbody tr:last-child').cells[1].style.display,'none');
  doc.querySelector('#alarms').outerHTML=table();await tick();
  assert.equal(doc.querySelectorAll('.clearGridColumnsToggle').length,1);
  assert.equal(doc.querySelector('#alarms tbody tr').cells[1].style.display,'none');
});

test('el menú excluye textos de filtros y controles del encabezado', async t => {
  const dom=await boot('<table><thead><tr><th>Estado<select><option>Todos</option><option>Parado</option></select><span class="column-drag-handle">Mover</span></th><th>TAG</th></tr></thead><tbody></tbody></table>');t.after(()=>dom.close());
  dom.window.document.querySelector('.clearGridColumnsToggle').click();
  assert.deepEqual([...dom.window.document.querySelectorAll('[data-grid-list] label')].map(label=>label.textContent),['Estado','TAG']);
});

test('no duplica selectores propios de telemetría ni de novedades', async t => {
  const dom=await boot('<button id="tgColumnsBtn">Columnas</button>'+table('tgTable','tg-table')+'<button id="colbtn">Columnas</button>'+table('table','tel-table')+'<section class="nsTableCard"><button data-ns-columns-toggle>Columnas</button><div data-ns-columns-menu></div>'+table('ns').replace('<table','<table data-ns-custom-columns')+'</section>');t.after(()=>dom.close());
  assert.equal(dom.window.document.querySelectorAll('.clearGridColumnsToggle').length,0);
  assert.equal(dom.window.document.getElementById('gridToolsBtn').hidden,true);
});

test('funciona en históricos sin barra superior y cierra con Escape', async t => {
  const dom=await boot(table(),{},null,false);t.after(()=>dom.close());const doc=dom.window.document;
  doc.querySelector('.clearGridColumnsToggle').click();
  doc.dispatchEvent(new dom.window.KeyboardEvent('keydown',{key:'Escape'}));
  assert.equal(doc.getElementById('clearGridColumnsPanel').hidden,true);
  assert.equal(doc.querySelector('.clearGridColumnsToggle').getAttribute('aria-expanded'),'false');
});

test('carga preferencias remotas sin pisar una selección que acaba de hacer el usuario', async t => {
  let resolveRemote;
  const dom=await boot(table(),{},()=>new Promise(resolve=>{resolveRemote=resolve;}));t.after(()=>dom.close());
  const doc=dom.window.document;doc.querySelector('.clearGridColumnsToggle').click();toggle(dom,'Comentario',false);
  resolveRemote({json:async()=>({ok:true,value:JSON.stringify({'instalaciones_top20:alarms':[]})})});await tick();
  assert.equal(doc.querySelector('#alarms tr').cells[1].style.display,'none');
});

test('conserva la columna oculta cuando se reordenan encabezados y celdas', async t => {
  const dom=await boot(table());t.after(()=>dom.close());const doc=dom.window.document;
  doc.querySelector('.clearGridColumnsToggle').click();toggle(dom,'Comentario',false);
  [...doc.querySelector('#alarms').rows].slice(0,3).forEach(row=>row.insertBefore(row.cells[1],row.cells[0]));
  doc.dispatchEvent(new dom.window.CustomEvent('clear-grid-columns-reordered'));await tick();
  assert.equal(doc.querySelector('#alarms tbody tr').cells[0].style.display,'none');
  assert.equal(doc.querySelector('#alarms tbody tr').cells[1].style.display,'');
});

test('las columnas agregadas después aparecen visibles y sin duplicar el botón', async t => {
  const dom=await boot(table());t.after(()=>dom.close());const doc=dom.window.document;
  doc.querySelector('#alarms thead tr').insertAdjacentHTML('beforeend','<th>Prioridad</th>');
  doc.querySelector('#alarms tbody tr').insertAdjacentHTML('beforeend','<td>Alta</td>');await tick();
  doc.querySelector('.clearGridColumnsToggle').click();
  assert.equal(doc.querySelectorAll('.clearGridColumnsToggle').length,1);
  assert.ok([...doc.querySelectorAll('[data-grid-list] label')].some(label=>label.textContent==='Prioridad'));
});
