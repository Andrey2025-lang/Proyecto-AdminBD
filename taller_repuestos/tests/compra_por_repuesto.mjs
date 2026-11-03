// Prueba de solo lectura: navegador CDP en 9327 y servidor PHP en 8080.
import assert from 'node:assert/strict';
const endpoint=`http://127.0.0.1:${process.env.CDP_PORT||9327}`;
const target=await (await fetch(`${endpoint}/json/new?about:blank`,{method:'PUT'})).json();
const ws=new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
let id=0;const pending=new Map(),errors=[],posts=[];
ws.addEventListener('message',event=>{
    const msg=JSON.parse(event.data);
    if(msg.id){const call=pending.get(msg.id);pending.delete(msg.id);if(msg.error)call.reject(new Error(JSON.stringify(msg.error)));else call.resolve(msg.result);}
    if(msg.method==='Runtime.exceptionThrown')errors.push(msg.params.exceptionDetails.text);
    if(msg.method==='Network.requestWillBeSent'&&msg.params.request.method==='POST')posts.push(msg.params.request.url);
});
function send(method,params={}){return new Promise((resolve,reject)=>{pending.set(++id,{resolve,reject});ws.send(JSON.stringify({id,method,params}));});}
async function evaluate(expression){const result=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(result.exceptionDetails)throw new Error(JSON.stringify(result.exceptionDetails));return result.result.value;}
async function wait(expression){assert.equal(await evaluate(`new Promise(resolve=>{const end=Date.now()+10000;function check(){if(${expression})resolve(true);else if(Date.now()>end)resolve(false);else setTimeout(check,40);}check();})`),true,expression);}
try {
    await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
    await send('Page.navigate',{url:'http://127.0.0.1:8080/taller_repuestos/?page=compras'});
    await wait(`document.readyState==='complete'&&document.querySelector('.cart-editor[data-ready="true"]')`);
    assert.equal(await evaluate(`!!document.querySelector('[name=confirmar_iva]')`),false);
    assert.equal(await evaluate(`[...document.querySelector('[name=tarifa_compra]').options].every(x=>Number(x.value)>0)`),true);
    assert.match(await evaluate(`document.querySelector('[data-tax-note]').textContent`),/incluyen IVA/);
    const part=await evaluate(`(async()=>{const data=await(await fetch('index.php?lookup=repuestos&operation=purchase&term=&lp=1')).json();for(const part of data.items){const suppliers=await(await fetch('index.php?part_suppliers='+part.id)).json();if(suppliers.items.length)return part;}return null;})()`);
    assert.ok(part,'Se necesita un repuesto habilitado en al menos un proveedor');
    assert.equal(await evaluate(`document.querySelectorAll('.cart-editor .autocomplete').length`),1,'Un único buscador de repuestos');
    assert.equal(await evaluate(`document.querySelectorAll('[name=discover_part]').length`),0,'Sin buscadores de repuesto duplicados');
    assert.equal(await evaluate(`document.querySelector('[data-supplier-picker]').hidden`),false,'Búsqueda directa de proveedor disponible');
    await evaluate(`document.querySelector('[name=id_repuesto]').closest('.autocomplete').dispatchEvent(new CustomEvent('select-item',{detail:${JSON.stringify(part)}}))`);
    await wait(`document.querySelector('[data-part-suppliers] button')`);
    const provider=await evaluate(`document.querySelector('[data-part-suppliers] strong').textContent`);
    await evaluate(`document.querySelector('[data-part-suppliers] button').click()`);
    await wait(`document.querySelector('#id_repuesto').value===${JSON.stringify(String(part.id))}`);
    assert.equal(await evaluate(`document.querySelector('[data-supplier-name]').textContent`),provider);
    assert.equal(await evaluate(`Number(document.querySelector('#id_repuesto').value)`),Number(part.id));
    assert.equal(await evaluate(`document.querySelector('#search-id_repuesto').value`),part.label,'El repuesto se conserva al elegir proveedor');
    assert.equal(await evaluate(`document.querySelector('[data-part-suppliers]').textContent`),'');
    await evaluate(`document.querySelector('#precio_unitario').value='113.00';document.querySelector('[data-cart-add]').click()`);
    assert.equal(await evaluate(`document.querySelector('[data-cart-submit]').disabled`),false);
    assert.equal(await evaluate(`JSON.parse(document.querySelector('.cart-lines').value).length`),1);
    assert.equal(await evaluate(`document.querySelector('[data-tax-base]').textContent`),'₡ 100.00');
    assert.equal(await evaluate(`document.querySelector('[data-tax-amount]').textContent`),'₡ 13.00');
    assert.equal(await evaluate(`document.querySelector('[data-change-supplier]').disabled`),true,'No mezclar proveedores en una compra');
    // El mismo buscador limita las siguientes partidas al proveedor elegido.
    await evaluate(`document.querySelector('#search-id_repuesto').dispatchEvent(new Event('focus'))`);
    await wait(`document.querySelector('#choices-id_repuesto .autocomplete-option')`);
    const nextIds=await evaluate(`[...document.querySelectorAll('#choices-id_repuesto .autocomplete-option')].map(x=>x.querySelector('strong').textContent)`);
    const allowed=await evaluate(`(async()=>{const data=await(await fetch('index.php?lookup=repuestos_compra&operation=purchase&lp=1&proveedor='+document.querySelector('#id_proveedor').value)).json();return data.items.map(x=>x.label);})()`);
    assert.deepEqual(nextIds,allowed);
    await evaluate(`document.querySelector('[name=id_repuesto]').closest('.autocomplete').dispatchEvent(new Event('reset-selection'))`);
    assert.equal(await evaluate(`document.querySelector('[data-part-suppliers]').textContent`),'');
    await evaluate(`document.querySelector('[data-cart-clear]').click();document.querySelector('[data-change-supplier]').click()`);
    assert.equal(await evaluate(`document.querySelector('#id_proveedor').value`),'');
    assert.equal(await evaluate(`document.querySelector('#id_repuesto').value`),'');
    // El buscador vuelve a ofrecer todos los repuestos al cambiar proveedor.
    await evaluate(`document.querySelector('[name=id_repuesto]').closest('.autocomplete').dispatchEvent(new CustomEvent('select-item',{detail:${JSON.stringify(part)}}))`);
    await wait(`document.querySelector('[data-part-suppliers] button')`);
    // Elegir un proveedor directamente también conserva el repuesto si lo ofrece.
    const supplierItem=await evaluate(`(async()=>{const data=await(await fetch('index.php?part_suppliers='+document.querySelector('#id_repuesto').value)).json();return data.items[0];})()`);
    await evaluate(`document.querySelector('#id_proveedor').closest('.autocomplete').dispatchEvent(new CustomEvent('select-item',{detail:${JSON.stringify(supplierItem)}}))`);
    await wait(`document.querySelector('#id_repuesto').value===${JSON.stringify(String(part.id))}`);
    assert.equal(await evaluate(`document.querySelector('#search-id_repuesto').value`),part.label);
    // Proveedor primero: usar el autocompletado real y luego su catálogo.
    await evaluate(`(()=>{document.querySelector('[data-change-supplier]').click();const input=document.querySelector('#search-id_proveedor');input.focus();input.dispatchEvent(new Event('focus'));})()`);
    await wait(`document.querySelector('#choices-id_proveedor .autocomplete-option')`);
    await evaluate(`(()=>{const input=document.querySelector('#search-id_proveedor');input.value=${JSON.stringify(supplierItem.label)};input.dispatchEvent(new Event('input',{bubbles:true}));})()`);
    await wait(`document.querySelector('#choices-id_proveedor .autocomplete-option strong')?.textContent===${JSON.stringify(supplierItem.label)}`);
    await evaluate(`document.querySelector('#choices-id_proveedor .autocomplete-option').click()`);
    assert.equal(await evaluate(`document.querySelector('#id_proveedor').value`),String(supplierItem.id));
    assert.equal(await evaluate(`document.querySelector('#id_repuesto').value`),'');
    await evaluate(`document.querySelector('#search-id_repuesto').dispatchEvent(new Event('focus'))`);
    await wait(`document.querySelector('#choices-id_repuesto .autocomplete-option')`);
    await evaluate(`document.querySelector('#choices-id_repuesto .autocomplete-option').click();document.querySelector('#precio_unitario').value='113.00';document.querySelector('[data-cart-add]').click()`);
    assert.equal(await evaluate(`JSON.parse(document.querySelector('.cart-lines').value).length`),1);
    assert.equal(await evaluate(`document.querySelector('[data-cart-submit]').disabled`),false);
    assert.equal(await evaluate(`document.querySelector('[data-supplier-picker]').hidden`),true);
    // Un proveedor ajeno al repuesto no conserva una selección inválida.
    const other=await evaluate(`(async()=>{const people=await(await fetch('index.php?lookup=proveedores&operation=purchase&lp=1')).json();for(const person of people.items){const offers=await(await fetch('index.php?part_suppliers=${part.id}&supplier='+person.id)).json();if(!offers.items.length)return person;}return null;})()`);
    assert.ok(other,'Se necesita un proveedor que no ofrezca el repuesto para comprobar el rechazo');
    await evaluate(`document.querySelector('[data-cart-clear]').click();document.querySelector('[data-change-supplier]').click();document.querySelector('#id_repuesto').closest('.autocomplete').dispatchEvent(new CustomEvent('select-item',{detail:${JSON.stringify(part)}}));document.querySelector('#id_proveedor').closest('.autocomplete').dispatchEvent(new CustomEvent('select-item',{detail:${JSON.stringify(other)}}))`);
    await wait(`document.querySelector('.cart-alert').textContent.includes('no tiene habilitado')`);
    assert.equal(await evaluate(`document.querySelector('#id_repuesto').value`),'');
    assert.equal(await evaluate(`document.querySelector('[data-cart-submit]').disabled`),true);
    assert.deepEqual(errors,[]);assert.deepEqual(posts,[]);
    console.log('OK: repuesto primero o proveedor primero, selección conectada y carrito con IVA incluido; sin escrituras.');
} finally {
    ws.close();await fetch(`${endpoint}/json/close/${target.id}`);
}
