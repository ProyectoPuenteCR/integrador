const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Ejecuta el script real de la página con dos alarmas de la misma instalación.
const source = fs.readFileSync(path.join(__dirname, '..', 'instalaciones_top20.php'), 'utf8');
const scripts = Array.from(source.matchAll(/<script>([\s\S]*?)<\/script>/g), match => match[1]);
assert.equal(scripts.length, 1);
const tags = ['BLH002_SBC01_PT001', 'BLH002_TKAG1_LT001', 'INSTALACION:BLH002'];
const cells = tags.map(subject => ({
  subject,
  textContent: 'Sin comentario',
  getAttribute() { return this.subject; },
}));
const rows = cells.map(cell => ({ cells: Array(7).fill({textContent: ''}).concat(cell), hidden: false }));
const listeners = {};
const filterListeners = {};
const filter = {
  value: '',
  tagName: 'INPUT',
  closest() { return {cellIndex: 7}; },
  addEventListener(name, callback) { filterListeners[name] = callback; },
};
const table = {
  tBodies: [{ rows }],
  querySelectorAll(selector) {
    if (selector === '[data-it20-column-filter]') return [filter];
    if (selector === '[data-it20-comment-subject]') return cells;
    throw new Error('Selector inesperado: ' + selector);
  },
};
vm.runInNewContext(scripts[0], {
  document: {getElementById() { return table; }},
  window: {addEventListener(name, callback) { listeners[name] = callback; }},
});
const update = detail => listeners['clear-alarm-comment-updated']({detail});

update({subject: tags[0], comment: 'Presión alta\nRevisar separador'});
assert.equal(cells[0].textContent, 'Presión alta\nRevisar separador');
assert.equal(cells[1].textContent, 'Sin comentario');
assert.equal(cells[2].textContent, 'Sin comentario');

update({subject: tags[1].toLowerCase(), comment: '<img src=x onerror=alert(1)>'});
assert.equal(cells[1].textContent, '<img src=x onerror=alert(1)>');
assert.equal(cells[0].textContent, 'Presión alta\nRevisar separador');

filter.value = 'separador';
filterListeners.input();
assert.equal(rows[0].hidden, false);
assert.equal(rows[1].hidden, true);
update({subject: tags[1], comment: 'Controlar separador'});
assert.equal(rows[1].hidden, false);

update({subject: tags[0], comment: ''});
assert.equal(cells[0].textContent, 'Sin comentario');
assert.equal(rows[0].hidden, true);
update({comment: 'No debe cambiar ninguna fila'});
assert.equal(cells[1].textContent, 'Controlar separador');
console.log('OK: aislamiento por TAG, texto seguro, saltos de línea y filtro al actualizar.');
