// Requiere Node.js, Playwright y Chromium. Ejecutar desde cualquier directorio.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge' });
  try {
    const page = await browser.newPage();
    let html = fs.readFileSync(path.join(__dirname, '../mis_reportes.php'), 'utf8');
    // Aislar el formulario real sin credenciales, SQL Server ni envíos SMTP.
    html = html.replace(/<\?php[\s\S]*?\?>/g, '').replace(/<script src=[^>]*><\/script>/g, '').replace(/<link[^>]*>/g, '');
    await page.route('http://clear.test/**', async route => route.fulfill({contentType: 'text/html', body: html}));
    await page.goto('http://clear.test/');
    const inputs = page.locator('#mrTimes input');
    assert.equal(await inputs.count(), 1);
    assert.equal(await inputs.first().inputValue(), '08:00');
    assert.equal(await page.locator('#mrTimes button').first().isDisabled(), true);
    await page.locator('#mrAddTime').click();
    await inputs.nth(1).fill('16:00');
    await page.locator('#mrNombre').fill('Dos horarios');
    await page.locator('#mrDestinatarios').fill('prueba@example.com');
    await page.locator('#mrAddTime').click();
    assert.equal(await page.locator('#mrForm').evaluate(form => form.checkValidity()), false);
    await inputs.nth(2).fill('08:00');
    let payload;
    await page.route('**/mis_reportes_api.php', async route => {
      payload = route.request().postData();
      await route.fulfill({status: 400, contentType: 'application/json', body: JSON.stringify({ok:false,error:'Respuesta simulada'})});
    });
    await page.locator('button[type=submit]').click();
    await page.waitForFunction(() => document.querySelector('#mrMessage').textContent.includes('Respuesta simulada'));
    assert.match(payload, /name="horarios"\r\n\r\n08:00,16:00/);
    await page.evaluate(() => mrEdit({ID:4,HORARIOS:'16:00,8:00,08:00',FRECUENCIA:'custom',DIAS_SEMANA:'1,3',ACTIVO:1}));
    assert.deepEqual(await inputs.evaluateAll(items => items.map(input => input.value)), ['08:00','16:00']);
    assert.equal(await page.locator('#mrFrecuencia').inputValue(), 'custom');
    await page.locator('#mrTimes button').nth(1).click();
    assert.equal(await inputs.count(), 1);
    assert.equal(await page.locator('#mrTimes button').first().isDisabled(), true);
    await page.evaluate(() => mrEdit({HORARIOS:'08:00',FRECUENCIA:'monthly'}));
    assert.equal(await inputs.first().inputValue(), '08:00');
    assert.equal(await page.locator('#mrFrecuencia').inputValue(), 'monthly');
    await page.evaluate(() => mrEdit({HORARIOS:Array.from({length:33},(_,i)=>'00:'+String(i).padStart(2,'0')).join(',')}));
    assert.equal(await inputs.count(), 33);
    assert.equal(await page.locator('#mrAddTime').isDisabled(), true);
    console.log('OK: selector, validación, guardado CSV, edición, eliminación y límite de horarios');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
