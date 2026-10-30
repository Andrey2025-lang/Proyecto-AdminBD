// Solo una recarga real: no altera enlaces, historial, exportaciones ni peticiones parciales.
(() => {
    if(performance.getEntriesByType('navigation')[0]?.type!=='reload')return;
    const url=new URL(location.href);
    const page=url.searchParams.get('page')||'inicio';
    if(!['inicio','repuestos','clientes','proveedores','compras','ventas','inventario','reportes'].includes(page))return;
    const filters=['q','categoria','estado','nivel','desde','hasta','tipo','vendedor','repuesto',
        'f_repuesto','f_cliente','f_proveedor','referencia_desde','referencia_hasta','tam','htam','p','h','edit','detail'];
    if([...url.searchParams.keys()].some(key=>key!=='page'&&!filters.includes(key)))return;
    if([...url.searchParams.keys()].some(key=>filters.includes(key))) {
        url.search='';url.searchParams.set('page',page);
        location.replace(url.href);
        return;
    }
    window.addEventListener('pageshow',()=>{
        document.querySelectorAll('.filter-bar').forEach(form=>form.reset());
    },{once:true});
})();
