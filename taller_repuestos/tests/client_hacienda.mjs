import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

class Node {
    constructor() { this.hidden = false; this.textContent = ''; this.className = ''; this.children = []; this.isConnected = true; this.map = new Map(); }
    querySelector(selector) { return this.map.get(selector) || null; }
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren(...nodes) { this.children = nodes; }
}
const listeners = new Map();
const document = {
    addEventListener(type, listener) { listeners.set(type, listener); },
    createElement() { return new Node(); },
};
const panel = new Node();
const status = new Node();
const details = new Node();
const activityList = new Node();
for (const selector of ['[data-hacienda-tipo]','[data-hacienda-regimen]','[data-hacienda-estado]','[data-hacienda-moroso]','[data-hacienda-omiso]','[data-hacienda-administracion]']) panel.map.set(selector, new Node());
panel.map.set('[data-hacienda-status]', status);
panel.map.set('[data-hacienda-details]', details);
panel.map.set('[data-hacienda-activities]', activityList);
const form = new Node();
const cedula = new Node();
const nombre = new Node();
const telefono = new Node();
const correo = new Node();
cedula.value = '210-004 2005';
cedula.closest = selector => selector.startsWith('input') ? cedula : form;
nombre.value = '';
telefono.value = '8888-0000';
correo.value = 'cliente@example.com';
form.map.set('input[name="cedula"]', cedula);
form.map.set('input[name="nombre"]', nombre);
form.map.set('input[name="telefono"]', telefono);
form.map.set('input[name="email"]', correo);
form.map.set('[data-client-hacienda-panel]', panel);
let calls = 0;
let payload = { ok: true, cached: false, data: {
    nombre: 'ESTADO-MINISTERIO DE HACIENDA', tipo_identificacion: '02', regimen: { codigo: 0, descripcion: 'No tiene' },
    situacion: { estado: 'Inscrito', moroso: 'NO', omiso: 'NO', administracion_tributaria: 'San José' }, actividades: [],
}};
const context = {
    document, window: { location: { href: 'http://localhost/taller_repuestos/index.php?page=clientes' } },
    URL, AbortController, console,
    setTimeout(callback) { queueMicrotask(callback); return 1; }, clearTimeout() {},
    fetch: async () => { calls++; return { ok: true, json: async () => payload }; },
};
vm.runInNewContext(await readFile(new URL('../assets/client-hacienda.js', import.meta.url), 'utf8'), context);
listeners.get('input')({ target: cedula });
await new Promise(resolve => setImmediate(resolve));
await new Promise(resolve => setImmediate(resolve));
assert.equal(calls, 1, 'una identificación válida realiza una sola consulta');
assert.equal(nombre.value, payload.data.nombre, 'la respuesta completa el nombre vacío');
assert.equal(details.hidden, false, 'la información tributaria se muestra');
assert.match(status.textContent, /Información encontrada/, 'se muestra el estado exitoso');
cedula.value = 'abc';
listeners.get('input')({ target: cedula });
await new Promise(resolve => setImmediate(resolve));
assert.equal(calls, 1, 'una identificación inválida no consulta el endpoint');
assert.match(status.textContent, /inválida/, 'la identificación inválida muestra un mensaje seguro');
payload = { ok: true, cached: false, data: {
    nombre: 'CLIENTE NO INSCRITO', tipo_identificacion: '01', regimen: { codigo: 0, descripcion: 'No tiene' },
    situacion: { estado: 'No inscrito', moroso: 'NO', omiso: 'NO', administracion_tributaria: '' }, actividades: [],
}};
cedula.value = '3101006829';
nombre.value = '';
listeners.get('input')({ target: cedula });
await new Promise(resolve => setImmediate(resolve));
await new Promise(resolve => setImmediate(resolve));
assert.equal(calls, 2, 'un contribuyente no inscrito también consulta el endpoint');
assert.equal(nombre.value, 'CLIENTE NO INSCRITO', 'No inscrito completa el nombre');
assert.match(status.textContent, /No inscrito/, 'No inscrito se muestra como advertencia');
assert.match(status.className, /is-warning/, 'No inscrito usa estado visual de advertencia');
assert.equal(telefono.value, '8888-0000', 'teléfono permanece intacto');
assert.equal(correo.value, 'cliente@example.com', 'correo permanece intacto');
console.log('OK: cliente Hacienda frontend simulado');
