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
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);
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
    for(let attempt=0;attempt<4;attempt++) {
        try {
            assert.equal(await evaluate(`new Promise(resolve => { const end = Date.now()+10000; const check=()=> { if (${expression}) resolve(true); else if(Date.now()>end) resolve(false); else setTimeout(check,40); }; check(); })`), true, expression);
            return;
        } catch(error) {
            if(attempt===3||!/navigated or closed|Execution context was destroyed|Cannot find context/.test(error.message))throw error;
        }
    }
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
const optionSelector = name => `.autocomplete[data-lookup${name==='repuestos'?'^':''}="${name}"] .autocomplete-option`;
try {
    await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
    await send('Page.bringToFront');
    await send('Emulation.setFocusEmulationEnabled', { enabled: true });
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await navigate('page=clientes');
    await evaluate(`localStorage.removeItem('repuesto.sidebar.pinned')`);
    await navigate('page=clientes');
    assert.equal(await evaluate(`document.querySelector('tbody').rows.length`), 15);
    await type('.filter-bar input[name=q]','D');
    await evaluate(`new Promise(resolve=>setTimeout(resolve,500))`);
    assert.equal(await evaluate(`location.search.includes('q=')`),false);
    assert.equal(await evaluate(`document.querySelector('[data-search-status]').textContent.includes('2 caracteres')`),true);
    await type('.filter-bar input[name=q]','Daniela');
    await wait(`location.search.includes('q=Daniela') && document.querySelector('tbody').textContent.includes('Daniela')`);
    assert.equal(await evaluate(`document.activeElement.id`),'list-search-clientes');
    assert.equal(await evaluate(`document.querySelector('.filter-bar button:not(noscript button)')===null`),true);
    await type('.filter-bar input[name=q]','NO-EXISTE-987654321');
    await type('.filter-bar input[name=q]','Daniela');
    await wait(`document.querySelector('.filter-bar input[name=q]').value==='Daniela' && !document.querySelector('[data-search-status]').textContent.includes('Buscando')`);
    await type('.filter-bar input[name=q]','');
    await wait(`new URLSearchParams(location.search).get('q')==='' && document.querySelector('tbody').rows.length===15`);
    await navigate('page=clientes');
    console.log('OK navegador: búsqueda automática desde 2 caracteres, foco conservado y borrado recupera el listado');
    await navigate('page=repuestos&tam=30&p=2');
    await type('.filter-bar input[name=q]','Filtro');
    await wait(`location.search.includes('q=Filtro') && !new URLSearchParams(location.search).has('p') && !document.querySelector('.htmx-settling')`);
    assert.equal(await evaluate(`document.querySelector('.pager-jump input[type=number]').value`),'1');
    assert.equal(await evaluate(`document.querySelector('.pager-size select[name=tam]').value`),'30');
    await evaluate(`(()=>{const s=document.querySelector('.filter-bar select[name=estado]');s.value='ACTIVO';s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    await wait(`location.search.includes('estado=ACTIVO')`);
    assert.equal(await evaluate(`document.querySelector('.filter-bar input[name=q]').value`),'Filtro');
    await navigate('page=ventas');
    await type('.filter-bar input[name=desde]','2026-10-01');
    await wait(`location.search.includes('desde=2026-10-01')`);
    await navigate('page=clientes');
    console.log('OK navegador: filtros de estado y fecha automáticos; búsqueda reinicia página y conserva tamaño');
    await navigate('page=repuestos&q=Filtro&estado=ACTIVO&tam=30&p=2');
    assert.equal(await evaluate(`document.querySelector('.filter-bar input[name=q]').value`),'Filtro');
    assert.equal(await evaluate(`(()=>{const form=document.querySelector('.filter-bar');return [...form.querySelectorAll('.input,.filter-clear')].every(x=>Math.abs(x.getBoundingClientRect().height-40)<1)&&form.querySelector('.filter-help').getBoundingClientRect().top>=Math.max(...[...form.querySelectorAll('.input,.filter-clear')].map(x=>x.getBoundingClientRect().bottom));})()`),true);
    await send('Page.reload');
    await wait(`document.readyState==='complete' && location.search==='?page=repuestos' && document.querySelector('.filter-bar input[name=q]')?.value==='' && document.querySelector('.pager-size select[name=tam]')?.value==='15'`);
    assert.equal(await evaluate(`document.querySelector('.filter-bar select[name=estado]').value`),'');
    await navigate('page=inventario&q=Filtro&tam=30&htam=30&p=2&h=2');
    await send('Page.reload');
    await wait(`document.readyState==='complete' && location.search==='?page=inventario' && document.querySelector('.pager-size select[name=tam]')?.value==='10' && document.querySelector('.pager-size select[name=htam]')?.value==='10'`);
    await navigate('page=ventas&f_cliente=1&vendedor=1&desde=2026-10-01&tam=30&p=2');
    await send('Page.reload');
    await wait(`document.readyState==='complete' && location.search==='?page=ventas' && document.querySelector('.filter-bar input[name=desde]')?.value===''`);
    await navigate('page=clientes');
    console.log('OK navegador: controles alineados y recarga restablece búsqueda, persona, fechas y paginación');
    assert.equal(await evaluate(`document.querySelector('.filter-bar select[name=tam]')===null`), true);
    assert.equal(await evaluate(`document.querySelector('#sidebar-toggle').closest('#sidebar')!==null`),true);
    assert.equal(await evaluate(`document.querySelector('.topbar #sidebar-toggle')===null`),true);
    const workspaceLeft=await evaluate(`document.querySelector('.workspace').getBoundingClientRect().left`);
    const iconBefore=await evaluate(`(()=>{const r=document.querySelector('.nav-link>span').getBoundingClientRect();return {left:r.left,top:r.top};})()`);
    assert.equal(await evaluate(`getComputedStyle(document.querySelector('.sidebar')).transitionDuration===getComputedStyle(document.querySelector('.workspace')).transitionDuration`),true);
    assert.equal(await evaluate(`getComputedStyle(document.querySelector('.sidebar')).transitionDuration`),'0.3s');
    await send('Input.dispatchMouseEvent',{type:'mouseMoved',x:35,y:180});
    await wait(`document.body.classList.contains('sidebar-peek')`);
    await wait(`document.querySelector('.workspace').getBoundingClientRect().left>${workspaceLeft}+100`);
    assert.deepEqual(await evaluate(`(()=>{const r=document.querySelector('.nav-link>span').getBoundingClientRect();return {left:r.left,top:r.top};})()`),iconBefore);
    await send('Input.dispatchMouseEvent',{type:'mouseMoved',x:700,y:140});
    assert.equal(await evaluate(`document.body.classList.contains('sidebar-peek')`),true);
    await send('Input.dispatchMouseEvent',{type:'mouseMoved',x:35,y:180});
    assert.equal(await evaluate(`document.body.classList.contains('sidebar-peek')`),true);
    await screenshot('menu-fluido');
    await send('Input.dispatchMouseEvent',{type:'mouseMoved',x:700,y:140});
    await wait(`!document.body.classList.contains('sidebar-peek')`);
    console.log('OK navegador: menú abre con el mouse y el contenido se adapta a su ancho');
    await evaluate(`document.querySelector('#sidebar-toggle').click()`);
    await wait(`!document.body.classList.contains('sidebar-collapsed')`);
    await navigate('page=clientes');
    assert.equal(await evaluate(`document.body.classList.contains('sidebar-collapsed')`), false);
    await evaluate(`document.querySelector('#sidebar-toggle').click()`);
    await evaluate(`(() => {const s=document.querySelector('.pager-size select[name=tam]');s.value='30';s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    await wait(`document.querySelector('tbody').rows.length===30 && location.search.includes('tam=30')`);
    const pagerWidth=await evaluate(`document.querySelector('.pager-pages').getBoundingClientRect().width`);
    await evaluate(`document.querySelector('[aria-label="Página siguiente"]').click()`);
    await wait(`document.querySelector('.pager-jump input[type=number]').value==='2'`);
    assert.equal(await evaluate(`document.querySelector('.pager-pages').getBoundingClientRect().width`),pagerWidth);
    const listLocation=await evaluate(`location.href`);
    await evaluate(`document.querySelector('[data-edit-record]').click()`);
    await wait(`document.querySelector('#record-editor').open && document.querySelector('#record-editor form')`);
    assert.equal(await evaluate(`location.href`),listLocation);
    assert.equal(await evaluate(`document.querySelector('#record-editor input[name=telefono]').value.match(/^[0-9]{4}-[0-9]{4}$/)!==null`),true);
    await screenshot('editar-cliente');
    await evaluate(`document.querySelector('[data-edit-close]').click()`);
    await wait(`!document.querySelector('#record-editor').open`);
    await evaluate(`window.scrollTo(0,document.body.scrollHeight);document.querySelector('.nav-link[data-page=proveedores]').click()`);
    await wait(`location.search.includes('page=proveedores') && scrollY===0`);
    console.log('OK navegador: paginación estable, edición superpuesta y cambio de módulo desde arriba');
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
    await evaluate(`document.querySelector('#search-id_repuesto').focus()`);
    await wait(`document.querySelector('.autocomplete[data-lookup=repuestos_compra] .autocomplete-status').textContent.includes('Seleccione primero el proveedor')`);
    assert.equal(await evaluate(`document.querySelectorAll('${optionSelector('repuestos_compra')}').length`), 0);
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
    await evaluate(`document.querySelector('.autocomplete[data-lookup^=repuestos] .autocomplete-more').click()`);
    await wait(`document.querySelectorAll('${optionSelector('repuestos')}').length===40`);
    await type('#search-id_repuesto', 'Hilux');
    await wait(`document.querySelectorAll('${optionSelector('repuestos')}').length>0 && [...document.querySelectorAll('${optionSelector('repuestos')} strong')].every(x=>x.textContent.includes('Hilux'))`);
    await screenshot('compras-buscador');
    await evaluate(`document.querySelector('${optionSelector('repuestos')}').click()`);
    assert.notEqual(await evaluate(`document.querySelector('#id_repuesto').value`), '');
    assert.equal(await evaluate(`document.querySelector('#precio_unitario').value===document.querySelector('#id_repuesto').dataset.price`), true);
    await type('#cantidad','2');
    await evaluate(`document.querySelector('[data-cart-add]').click()`);
    assert.equal(await evaluate(`JSON.parse(document.querySelector('.cart-lines').value).length`),1);
    assert.equal(await evaluate(`document.querySelector('#id_repuesto').value`),'');
    await type('#search-id_repuesto','Hilux');
    await wait(`document.querySelectorAll('${optionSelector('repuestos')}').length>1`);
    await evaluate(`document.querySelectorAll('${optionSelector('repuestos')}')[1].click();document.querySelector('[data-cart-add]').click()`);
    assert.equal(await evaluate(`JSON.parse(document.querySelector('.cart-lines').value).length`),2);
    await type('.cart-quantity','3');
    assert.equal(await evaluate(`JSON.parse(document.querySelector('.cart-lines').value)[0].cantidad`),3);
    await evaluate(`document.querySelector('.cart-remove').click()`);
    assert.equal(await evaluate(`JSON.parse(document.querySelector('.cart-lines').value).length`),1);
    assert.equal(await evaluate(`document.querySelector('[data-cart-submit]').disabled`),false);
    await evaluate(`(()=>{const s=document.querySelector('[name=tarifa_compra]');s.value=s.options[1].value;s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    assert.equal(await evaluate(`document.querySelector('[data-cart-submit]').disabled`),true);
    await evaluate(`(()=>{const c=document.querySelector('[name=confirmar_iva]');c.checked=true;c.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    assert.equal(await evaluate(`document.querySelector('[data-cart-submit]').disabled`),false);
    assert.equal(await evaluate(`document.querySelector('[data-tax-note]').textContent.includes('Los precios incluyen IVA')`),true);
    await evaluate(`(()=>{const s=document.querySelector('[name=tarifa_compra]');s.value='0';s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    assert.equal(await evaluate(`document.querySelector('[data-tax-breakdown]').hidden`),true);
    console.log('OK navegador: carrito de compra agrega varios repuestos, modifica cantidades y elimina partidas');
    await screenshot('compra-carrito');
    await type('#search-id_repuesto', 'Toy');
    assert.equal(await evaluate(`document.querySelector('#id_repuesto').value`), '');
    assert.equal(await evaluate(`document.querySelector('#search-id_repuesto').checkValidity()`), false);
    console.log('OK navegador: sugerencias, mínimo de 2 letras, ver más, teclado e invalidación de selecciones editadas');

    await navigate('page=proveedores');
    await wait(`document.querySelector('[data-supplier-catalog]')`);
    await evaluate(`document.querySelector('[data-supplier-catalog]').click()`);
    await wait(`document.querySelector('#supplier-catalog').open && document.querySelector('#supplier-catalog .supplier-part-form')`);
    assert.equal(await evaluate(`document.querySelector('#supplier-catalog .operation-heading h2').textContent.trim().length>0`), true);
    assert.equal(await evaluate(`document.querySelectorAll('#supplier-catalog tbody tr').length>0`), true);
    await evaluate(`document.querySelector('#supplier-catalog [data-supplier-fetch]').click()`);
    await wait(`document.querySelector('#supplier-catalog .supplier-part-form input[name=catalog_version]')?.value.length>0`);
    assert.equal(await evaluate(`document.querySelector('#supplier-catalog .supplier-part-form input[name=confirmar_oferta]')!==null`), true);
    assert.equal(await evaluate(`document.querySelector('#supplier-catalog .supplier-part-form input[name=catalog_version]').value.length>0`), true);
    await screenshot('catalogo-proveedor');
    await evaluate(`document.querySelector('#supplier-catalog [data-supplier-close]').click()`);
    await wait(`!document.querySelector('#supplier-catalog').open && document.activeElement.hasAttribute('data-supplier-catalog')`);
    assert.equal(await evaluate(`document.activeElement.hasAttribute('data-supplier-catalog')`), true);
    console.log('OK navegador: catálogo del proveedor se abre sobre el listado, muestra solo sus repuestos y conserva el foco');

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
    await evaluate(`document.querySelector('[data-cart-add]').click()`);
    assert.equal(await evaluate(`JSON.parse(document.querySelector('.cart-lines').value).length`),0);
    assert.equal(await evaluate(`document.querySelector('.cart-alert').hidden`),false);
    await type('#cantidad','1');
    await evaluate(`document.querySelector('[data-cart-add]').click()`);
    assert.equal(await evaluate(`JSON.parse(document.querySelector('.cart-lines').value).length`),1);
    assert.ok(await evaluate(`JSON.parse(document.querySelector('.cart-lines').value)[0].precio_esperado`));
    assert.equal(await evaluate(`(()=>{const n=s=>Math.round(Number(document.querySelector(s).textContent.replace(/[^0-9.]/g,''))*100);return n('[data-tax-base]')+n('[data-tax-amount]')===n('#total-preview');})()`),true);
    assert.equal(await evaluate(`document.querySelector('[data-tax-note]').textContent.includes('Los precios incluyen IVA')`),true);
    console.log('OK navegador: IVA ya incluido en total de venta y confirmación explícita en compras');
    console.log('OK navegador: selección de cliente sin desplegable adicional');

    for(const kind of ['ventas','compras']) {
        await navigate(`page=${kind}&tam=30&p=2`);
        const previous=await evaluate(`location.href`);
        await evaluate(`document.querySelector('[data-detail-kind]').click()`);
        await wait(`document.querySelector('#operation-detail').open && document.querySelector('#operation-detail .detail-total')`);
        assert.equal(await evaluate(`location.href`),previous);
        assert.equal(await evaluate(`document.querySelector('#operation-detail a[href*="format=xlsx"]')!==null`),true);
        assert.equal(await evaluate(`document.querySelector('#operation-detail .detail-data-grid').children.length`),4);
        await screenshot(`${kind}-detalle`);
        await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
        await wait(`!document.querySelector('#operation-detail').open && document.activeElement.hasAttribute('data-detail-kind')`);
        assert.equal(await evaluate(`location.href`),previous);
    }
    console.log('OK navegador: ventanas de venta y compra conservan filtros, muestran comprobantes y cierran con Escape devolviendo el foco');

    await navigate('page=ventas');
    const exportLocation=await evaluate('location.href');
    await evaluate(`document.querySelector('.export-links a').click()`);
    await wait(`document.querySelector('#download-dialog .download-form') && !document.querySelector('#download-dialog [data-download-submit]').disabled`);
    await type('#download-dialog input[name=cantidad]','25');
    await type('#download-dialog input[name=desde_resultado]','5');
    await wait(`!document.querySelector('#download-dialog [data-download-submit]').disabled && document.querySelector('.download-preview').textContent.includes('Se seleccionan 25')`);
    assert.equal(await evaluate('location.href'),exportLocation);
    await type('#download-dialog input[name=desde]','2026-10-29');
    await type('#download-dialog input[name=hasta]','2026-10-01');
    await wait(`document.querySelector('.download-preview').textContent.includes('fecha inicial')`);
    assert.equal(await evaluate(`document.querySelector('#download-dialog [data-download-submit]').disabled`),true);
    await type('#download-dialog input[name=desde]','2026-10-01');
    await type('#download-dialog input[name=hasta]','2026-10-29');
    await wait(`!document.querySelector('#download-dialog [data-download-submit]').disabled`);
    await screenshot('filtros-descarga');
    await evaluate(`document.querySelector('[data-download-close]').click()`);
    await wait(`!document.querySelector('#download-dialog').open`);
    console.log('OK navegador: filtros de descarga, vista previa por cantidad y rechazo de fechas invertidas sin perder el módulo');

    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await navigate('page=inicio');
    assert.equal(await evaluate(`document.querySelector('#sidebar').inert`), true);
    await evaluate(`document.querySelector('#sidebar-mobile-toggle').click()`);
    assert.equal(await evaluate(`document.body.classList.contains('sidebar-open') && !document.querySelector('#sidebar').inert`), true);
    await screenshot('menu-movil');
    await evaluate(`document.querySelector('.nav-link[data-page=compras]').click()`);
    await wait(`location.search.includes('page=compras') && document.querySelector('.cart-editor')`);
    assert.equal(await evaluate(`document.querySelector('#sidebar').inert && !document.body.classList.contains('sidebar-open')`), true);
    assert.equal(await evaluate(`document.documentElement.scrollWidth<=innerWidth`), true);
    await navigate('page=ventas');
    assert.equal(await evaluate(`document.documentElement.scrollWidth<=innerWidth`),true);
    await evaluate(`document.querySelector('[data-detail-kind]').click()`);
    await wait(`document.querySelector('#operation-detail .detail-total')`);
    assert.equal(await evaluate(`document.querySelector('#operation-detail').getBoundingClientRect().width<=innerWidth`),true);
    await screenshot('detalle-movil');
    await evaluate(`document.querySelector('[data-detail-close]').click()`);
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
