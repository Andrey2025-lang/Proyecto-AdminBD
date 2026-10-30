// Escrituras acotadas a clientes UI-<id> propios; limpieza al finalizar.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {randomUUID} from 'node:crypto';
const prefix='UI-'+randomUUID().slice(0,8);
const sql=query=>execFileSync('sqlcmd',['-S','localhost','-E','-C','-b','-d','TallerRepuestosDB','-f','65001','-h','-1','-w','65535','-Q','SET NOCOUNT ON; '+query],{encoding:'utf8'}).trim();
const endpoint=`http://127.0.0.1:${process.env.CDP_PORT||9327}`;
let target,ws,id=0;const pending=new Map(),errors=[];
function send(method,params={}){return new Promise((resolve,reject)=>{pending.set(++id,{resolve,reject});ws.send(JSON.stringify({id,method,params}));});}
async function evaluate(expression){const result=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw new Error(JSON.stringify(result.exceptionDetails));return result.result.value;}
async function wait(expression){assert.equal(await evaluate(`new Promise(resolve=>{const end=Date.now()+15000;const check=()=>{if(${expression})resolve(true);else if(Date.now()>end)resolve(false);else setTimeout(check,40);};check();})`),true,expression);}
try {
    sql(`INSERT RP05_CLIENTES(cedula,nombre,telefono,email) SELECT '${prefix}'+RIGHT('00'+CONVERT(varchar(2),N.n),2),'${prefix} '+RIGHT('00'+CONVERT(varchar(2),N.n),2),'88880000',NULL FROM (VALUES(1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),(12),(13),(14),(15),(16)) N(n);`);
    target=await(await fetch(endpoint+'/json/new?about:blank',{method:'PUT'})).json();
    ws=new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
    ws.addEventListener('message',event=>{const msg=JSON.parse(event.data);if(msg.id){const p=pending.get(msg.id);pending.delete(msg.id);if(msg.error)p?.reject(new Error(JSON.stringify(msg.error)));else p?.resolve(msg.result);}if(msg.method==='Runtime.exceptionThrown')errors.push(msg.params.exceptionDetails.text);});
    await send('Page.enable');await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await send('Page.navigate',{url:`http://127.0.0.1:8080/taller_repuestos/index.php?page=clientes&q=${prefix}&p=2&tam=15`});
    await wait(`document.readyState==='complete'&&document.querySelector('[data-edit-record]')`);
    const original=await evaluate('location.href');
    await evaluate(`document.querySelector('[data-edit-record]').click()`);
    await wait(`document.querySelector('#record-editor form')`);
    await evaluate(`document.querySelector('#record-editor input[name=cedula]').value='${prefix}01';document.querySelector('#record-editor form').requestSubmit()`);
    await wait(`document.querySelector('#record-editor .notification.is-danger')`);
    assert.equal(await evaluate(`document.querySelector('#record-editor').open`),true);
    assert.equal(await evaluate('location.href'),original);
    assert.equal(sql(`SELECT COUNT(*) FROM RP05_CLIENTES WHERE cedula='${prefix}16';`),'1');
    await evaluate(`document.querySelector('#record-editor input[name=cedula]').value='${prefix}16';document.querySelector('#record-editor input[name=nombre]').value='${prefix} 16 editado';document.querySelector('#record-editor form').requestSubmit()`);
    await wait(`!document.querySelector('#record-editor').open&&document.querySelector('#content .notification.is-success')`);
    assert.equal(await evaluate('location.href'),original);
    assert.equal(await evaluate(`document.querySelector('.pager-jump input[type=number]').value`),'2');
    assert.equal(sql(`SELECT COUNT(*) FROM RP05_CLIENTES WHERE cedula='${prefix}16' AND nombre='${prefix} 16 editado' AND telefono='8888-0000';`),'1');
    assert.deepEqual(errors,[]);
    console.log('OK: edición en ventana conserva página y filtros, mantiene errores dentro del formulario y guarda sin duplicar registros.');
} finally {
    if(ws?.readyState===WebSocket.OPEN)await new Promise(resolve=>{ws.addEventListener('close',resolve,{once:true});ws.close();});
    if(target)await fetch(`${endpoint}/json/close/${target.id}`).catch(()=>{});
    sql(`DELETE FROM RP05_CLIENTES WHERE cedula LIKE '${prefix}%';`);
}
