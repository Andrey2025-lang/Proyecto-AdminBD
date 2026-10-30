// Verificación real en Edge/Chrome mediante CDP, sin dependencias npm.
// Iniciar un navegador de pruebas con --headless=new --remote-debugging-port=9327.
// node taller_repuestos/tests/interfaz.mjs [directorio-de-capturas]
import assert from 'node:assert/strict';
import { writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';

const port = process.env.CDP_PORT || '9327';
const endpoint = `http://127.0.0.1:${port}`;
const target = await (await fetch(`${endpoint}/json/new?about:blank`, { method: 'PUT' })).json();
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, { once: true }); ws.addEventListener('error', reject, { once: true }); });
let id = 0;
const pending = new Map(), errors = [], posts = [];
ws.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id) {
        const promise = pending.get(message.id); pending.delete(message.id);
        if (message.error) promise?.reject(new Error(JSON.stringify(message.error)));
        else promise?.resolve(message.result);
    }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    if (message.method === 'Network.requestWillBeSent' && message.params.request.method === 'POST') posts.push(message.params.request.url);
});
ws.addEventListener('close', () => { for (const promise of pending.values()) promise.reject(new Error('Navegador cerrado')); pending.clear(); });
function send(method, params = {}) { return new Promise((resolve, reject) => { pending.set(++id, { resolve, reject }); ws.send(JSON.stringify({ id, method, params })); }); }
async function evaluate(expression) {
    const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
    return result.result.value;
}
async function wait(expression) {
    assert.equal(await evaluate(`new Promise(resolve => { const end = Date.now()+10000; const check=()=> { if (${expression}) resolve(true); else if(Date.now()>end) resolve(false); else setTimeout(check,40); }; check(); })`), true, expression);
}
async function navigate(query) {
    await send('Page.navigate', { url: 'http://127.0.0.1:8080/taller_repuestos/index.php?' + query });
    await wait(`document.readyState==='complete' && document.documentElement.classList.contains('js') && document.querySelector('#content') && [...document.querySelectorAll('.autocomplete')].every(x=>x.dataset.ready==='true')`);
}
async function screenshot(name) {
    if (!process.argv[2]) return;
    await evaluate(`Promise.all(document.getAnimations().map(animation=>animation.finished.catch(()=>{})))`);
    await mkdir(process.argv[2], { recursive: true });
    const { data } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    await writeFile(path.join(process.argv[2], name + '.png'), Buffer.from(data, 'base64'));
}
async function type(selector, value) {
    await evaluate(`(() => { const input=document.querySelector(${JSON.stringify(selector)}); input.focus(); input.value=${JSON.stringify(value)}; input.dispatchEvent(new Event('input',{bubbles:true})); })()`);
}
const optionSelector = name => `.autocomplete[data-lookup="${name}"] .autocomplete-option`;
try {
    await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
    await send('Page.bringToFront');
    await send('Emulation.setFocusEmulationEnabled', { enabled: true });
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await navigate('page=clientes');
    assert.equal(await evaluate(`document.querySelector('tbody').rows.length`), 15);
    assert.equal(await evaluate(`document.querySelector('.filter-bar select[name=tam]')===null`), true);
    await evaluate(`document.querySelector('#sidebar-toggle').click()`);
    await wait(`document.body.classList.contains('sidebar-collapsed')`);
    await navigate('page=clientes');
    assert.equal(await evaluate(`document.body.classList.contains('sidebar-collapsed')`), true);
    await evaluate(`document.querySelector('#sidebar-toggle').click()`);
    await evaluate(`(() => {const s=document.querySelector('.pager-size select[name=tam]');s.value='30';s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    await wait(`document.querySelector('tbody').rows.length===30 && location.search.includes('tam=30')`);
    console.log('OK navegador: sidebar plegable persistente y selector de filas al pie');
    await screenshot('clientes');

    await navigate('page=inventario');
    assert.deepEqual(await evaluate(`[...document.querySelectorAll('tbody')].map(t=>t.rows.length)`), [10, 10]);
    await evaluate(`(() => {const s=document.querySelector('select[name=htam]');s.value='30';s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    await wait(`document.querySelectorAll('tbody')[1].rows.length===30`);
    assert.equal(await evaluate(`document.querySelector('tbody').rows.length`), 10);
    console.log('OK navegador: tamaños independientes en existencias e historial');

    await navigate('page=compras');
    assert.equal(await evaluate(`document.querySelector('#id_proveedor').type`), 'hidden');
    await evaluate(`document.querySelector('#search-id_proveedor').focus()`);
    await wait(`document.querySelectorAll('${optionSelector('proveedores')}').length>0`);
    await type('#search-id_proveedor', 'D');
    await wait(`document.querySelector('.autocomplete-status').textContent.includes('2 caracteres')`);
    assert.equal(await evaluate(`document.querySelectorAll('${optionSelector('proveedores')}').length`), 0);
    await type('#search-id_proveedor', 'Distribuidora');
    await wait(`document.querySelectorAll('${optionSelector('proveedores')}').length>0`);
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'ArrowDown', code: 'ArrowDown', windowsVirtualKeyCode: 40 });
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    assert.notEqual(await evaluate(`document.querySelector('#id_proveedor').value`), '');
    const selectedSupplier=await evaluate(`document.querySelector('#id_proveedor').value`);
    await evaluate(`document.querySelector('#cantidad').focus();document.querySelector('#search-id_proveedor').focus()`);
    await wait(`document.querySelectorAll('${optionSelector('proveedores')}').length>0 && !document.querySelector('.autocomplete[data-lookup=proveedores] .autocomplete-more').disabled`);
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    assert.equal(await evaluate(`document.querySelector('#id_proveedor').value`), selectedSupplier);
    await evaluate(`document.querySelector('#search-id_repuesto').focus()`);
    await wait(`document.querySelectorAll('${optionSelector('repuestos')}').length===20`);
    await evaluate(`document.querySelector('.autocomplete[data-lookup=repuestos] .autocomplete-more').click()`);
    await wait(`document.querySelectorAll('${optionSelector('repuestos')}').length===40`);
    await type('#search-id_repuesto', 'Hilux');
    await wait(`document.querySelectorAll('${optionSelector('repuestos')}').length>0 && [...document.querySelectorAll('${optionSelector('repuestos')} strong')].every(x=>x.textContent.includes('Hilux'))`);
    await screenshot('compras-buscador');
    await evaluate(`document.querySelector('${optionSelector('repuestos')}').click()`);
    assert.notEqual(await evaluate(`document.querySelector('#id_repuesto').value`), '');
    assert.equal(await evaluate(`document.querySelector('#precio_unitario').value===document.querySelector('#id_repuesto').dataset.price`), true);
    await type('#search-id_repuesto', 'Toy');
    assert.equal(await evaluate(`document.querySelector('#id_repuesto').value`), '');
    assert.equal(await evaluate(`document.querySelector('#search-id_repuesto').checkValidity()`), false);
    console.log('OK navegador: sugerencias, mínimo de 2 letras, ver más, teclado e invalidación de selecciones editadas');

    await navigate('page=ventas');
    await type('#search-id_cliente', 'Daniela');
    await wait(`document.querySelectorAll('${optionSelector('clientes')}').length>0`);
    await evaluate(`document.querySelector('${optionSelector('clientes')}').click()`);
    assert.notEqual(await evaluate(`document.querySelector('#id_cliente').value`), '');
    await type('#search-id_repuesto', 'Filtro');
    await wait(`document.querySelectorAll('${optionSelector('repuestos')}').length>0`);
    await evaluate(`document.querySelector('${optionSelector('repuestos')}').click()`);
    await evaluate(`(() => {const q=document.querySelector('#cantidad');q.value=String(Number(document.querySelector('#id_repuesto').dataset.stock)+1);q.dispatchEvent(new Event('input',{bubbles:true}));})()`);
    assert.equal(await evaluate(`document.querySelector('#cantidad').checkValidity()`), false);
    assert.equal(await evaluate(`document.querySelector('.autocomplete[data-lookup=repuestos] .autocomplete-menu').hidden`), true);
    console.log('OK navegador: selección de cliente sin desplegable adicional');

    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await navigate('page=inicio');
    assert.equal(await evaluate(`document.querySelector('#sidebar').inert`), true);
    await evaluate(`document.querySelector('#sidebar-toggle').click()`);
    assert.equal(await evaluate(`document.body.classList.contains('sidebar-open') && !document.querySelector('#sidebar').inert`), true);
    await screenshot('menu-movil');
    await evaluate(`document.querySelector('.nav-link[data-page=reportes]').click()`);
    await wait(`location.search.includes('page=reportes') && document.querySelector('.report-form')`);
    assert.equal(await evaluate(`document.querySelector('#sidebar').inert && !document.body.classList.contains('sidebar-open')`), true);
    assert.equal(await evaluate(`document.documentElement.scrollWidth<=innerWidth`), true);
    console.log('OK navegador: menú móvil, cierre al navegar y contenido sin desbordamiento');
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    const now = new Date().toISOString().slice(0, 10);
    await send('Page.navigate', { url: `http://127.0.0.1:8080/taller_repuestos/index.php?export=ventas&format=print&desde=${now.slice(0,8)}01&hasta=${now}` });
    await wait(`document.readyState==='complete' && document.querySelector('.summary-grid') && document.querySelector('.grand-total')`);
    await screenshot('documento');
    assert.deepEqual(errors, []);
    assert.deepEqual(posts, []);
    console.log('OK navegador: documento formateado y ninguna excepción JavaScript ni escritura POST');
} catch (error) {
    console.error('Estado de la interfaz:', await evaluate(`({url:location.href,ready:document.readyState,focus:document.activeElement?.id,widgets:[...document.querySelectorAll('.autocomplete')].map(x=>x.dataset.ready), errors:document.querySelectorAll('.notification').length, status:[...document.querySelectorAll('.autocomplete-status')].map(x=>x.textContent), expanded:[...document.querySelectorAll('.autocomplete-input')].map(x=>({expanded:x.getAttribute('aria-expanded'),validity:x.validationMessage}))})`).catch(()=>null));
    console.error('Excepciones:', errors);
    await screenshot('error').catch(()=>{});
    throw error;
} finally {
    if (ws.readyState===WebSocket.OPEN) await new Promise(resolve=>{ws.addEventListener('close',resolve,{once:true});ws.close();});
    await fetch(`${endpoint}/json/close/${target.id}`).catch(() => {});
}
