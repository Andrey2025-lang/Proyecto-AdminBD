(() => {
    const amounts=new Intl.NumberFormat('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    const number={format:value=>'₡ '+amounts.format(value)};
    const carts=new WeakMap();
    function initCart(editor) {
        if(carts.has(editor)) return;
        const form=editor.closest('form'), sale=editor.dataset.operation==='sale';
        const source=editor.querySelector('#id_repuesto'), search=editor.querySelector('.autocomplete-input');
        const quantity=editor.querySelector('#cantidad'), price=editor.querySelector('#precio_unitario');
        const table=editor.querySelector('.cart-items'), serialized=editor.querySelector('.cart-lines');
        const alert=editor.querySelector('.cart-alert'), submit=editor.querySelector('[data-cart-submit]');
        const supplier=sale?null:form.querySelector('#id_proveedor');
        let lines=JSON.parse(editor.querySelector('.cart-seed').textContent);
        const cents=value=>/^\d{1,8}(?:\.\d{1,2})?$/.test(String(value))?Math.round(Number(value)*100):null;
        function warn(text) { alert.textContent=text; alert.hidden=!text; }
        function sync() {
            let total=0, valid=lines.length>0;
            for(const line of lines) {
                const cost=cents(line.precio);
                if(!Number.isInteger(line.cantidad)||line.cantidad<1||line.cantidad>2147483647||cost===null||(sale&&line.cantidad>line.stock)) valid=false;
                if(!sale&&(!supplier.value||String(line.supplier)!==supplier.value||line.supplierValid===false))valid=false;
                if(cost!==null && Number.isInteger(line.cantidad)) total+=cost*line.cantidad;
            }
            if(total>9999999999) valid=false;
            serialized.value=JSON.stringify(lines.map(line=>({id_repuesto:line.id_repuesto,cantidad:line.cantidad,...(sale?{precio_esperado:String(line.precio)}:{precio_unitario:String(line.precio)})})));
            editor.querySelector('#total-preview').textContent=number.format(total/100);
            submit.disabled=!valid;
            return valid;
        }
        function render() {
            table.replaceChildren();
            if(!lines.length) {
                const row=table.insertRow(); const cell=row.insertCell(); cell.colSpan=4; cell.className='empty-state';cell.textContent='Agregue los repuestos de esta operación.';
            }
            lines.forEach((line,index)=>{
                const row=table.insertRow(), name=row.insertCell(), qty=row.insertCell(), unit=row.insertCell(), action=row.insertCell();
                const label=document.createElement('strong');label.textContent=line.nombre;name.append(label);
                const code=document.createElement('small');code.className='cell-subtitle';code.textContent=line.codigo_barras;name.append(code);
                const subtotal=document.createElement('small');subtotal.className='cart-subtotal';subtotal.textContent=`Subtotal: ${number.format(Number(line.precio)*line.cantidad)}`;name.append(subtotal);
                const input=document.createElement('input');input.type='number';input.className='input cart-quantity';input.min='1';input.max=String(sale?line.stock:2147483647);input.step='1';input.value=String(line.cantidad);input.required=true;input.setAttribute('aria-label',`Cantidad de ${line.nombre}`);
                input.addEventListener('input',()=>{line.cantidad=Number(input.value);subtotal.textContent=`Subtotal: ${number.format(Number(line.precio)*line.cantidad)}`;warn('');sync();});qty.append(input);
                if(sale) unit.textContent=number.format(Number(line.precio));
                else {
                    const input=document.createElement('input');input.type='number';input.className='input cart-price';input.min='0';input.max='99999999.99';input.step='.01';input.required=true;input.value=String(line.precio);input.setAttribute('aria-label',`Precio de ${line.nombre}`);
                    input.addEventListener('input',()=>{line.precio=input.value;subtotal.textContent=`Subtotal: ${number.format(Number(line.precio)*line.cantidad)}`;warn('');sync();});unit.append(input);
                }
                const remove=document.createElement('button');remove.type='button';remove.className='cart-remove';remove.textContent='×';remove.setAttribute('aria-label',`Quitar ${line.nombre}`);
                remove.addEventListener('click',()=>{lines.splice(index,1);warn('');render();});action.append(remove);
                if(sale&&line.cantidad>line.stock) {row.classList.add('cart-shortage');code.textContent+=` · Disponible: ${line.stock}`;}
                if(!sale&&(String(line.supplier)!==supplier.value||line.supplierValid===false)){row.classList.add('cart-shortage');code.textContent+=' · Quite este repuesto y vuelva a seleccionarlo para el proveedor actual';}
            });
            sync();
        }
        editor.querySelector('[data-cart-add]').addEventListener('click',()=>{
            const qty=Number(quantity.value), cost=cents(sale?source.dataset.price:price.value);
            if(!source.value) {warn('Busque y seleccione un repuesto de la lista.');search.focus();return;}
            if(!Number.isInteger(qty)||qty<1||qty>2147483647||cost===null) {warn('Revise la cantidad y el precio del repuesto.');return;}
            const key=Number(source.value), match=lines.find(line=>line.id_repuesto===key&&(sale||(String(line.supplier)===supplier.value&&line.supplierValid!==false&&cents(line.precio)===cost)));
            const combined=qty+(match?.cantidad||0);
            if(combined>2147483647 || (sale&&combined>Number(source.dataset.stock))) {warn('Existencias insuficientes para la cantidad acumulada del repuesto.');return;}
            if(!match&&lines.length>=100) {warn('La operación admite un máximo de 100 líneas.');return;}
            if(match) match.cantidad=combined;
            else lines.push({id_repuesto:key,cantidad:qty,precio:(cost/100).toFixed(2),nombre:source.dataset.label||search.value,codigo_barras:source.dataset.code||'',stock:Number(source.dataset.stock),supplier:supplier?.value||0,supplierValid:true});
            source.closest('.autocomplete').dispatchEvent(new Event('reset-selection'));
            quantity.value='1';if(price)price.value='0.00';warn('');render();search.focus();
        });
        form.addEventListener('submit',event=>{
            if(!sync()||source.value||search.value.trim()) {
                event.preventDefault();event.stopImmediatePropagation();
                warn(source.value||search.value.trim()?'Tiene un repuesto pendiente: agréguelo a la operación o limpie el buscador.':'Revise las cantidades y precios de los repuestos agregados.');
                alert.scrollIntoView({block:'nearest'});
            }
        },true);
        supplier?.addEventListener('change',()=>{
            source.closest('.autocomplete').dispatchEvent(new Event('reset-selection'));
            if(lines.length){warn('Cambió el proveedor. Se conservaron los renglones para que pueda revisarlos; quite los anteriores y agregue los del proveedor actual.');render();}
            else sync();
        });
        editor.querySelector('[data-cart-clear]')?.addEventListener('click',()=>{lines=[];warn('');render();});
        carts.set(editor,true);render();editor.dataset.ready='true';
    }

    const dialog=document.querySelector('#operation-detail');
    let controller=null, opener=null;
    async function openDetail(kind,id,button) {
        if(!dialog) return;
        controller?.abort();controller=new AbortController();const request=controller;opener=button||document.activeElement;
        dialog.innerHTML='<header class="operation-heading"><h2 id="operation-title">Detalle de operación</h2><button type="button" class="detail-close" data-detail-close aria-label="Cerrar detalle">×</button></header><div class="operation-loading" role="status">Cargando información…</div>';
        if(!dialog.open) dialog.showModal();document.body.classList.add('detail-open');dialog.querySelector('button').focus();
        try {
            const response=await fetch(`index.php?view_operation=${encodeURIComponent(kind)}&id=${encodeURIComponent(id)}`,{signal:request.signal});
            const body=await response.text();
            if(request.signal.aborted||controller!==request||!dialog.open)return;
            dialog.innerHTML=body;dialog.querySelector('[data-detail-close]')?.focus();
        } catch(error) {
            if(error.name!=='AbortError') {const text=document.createElement('div');text.className='operation-loading';text.textContent='No se pudo cargar el detalle. Cierre la ventana e intente nuevamente.';dialog.querySelector('.operation-loading')?.replaceWith(text);}
        }
    }
    dialog?.addEventListener('close',()=>{controller?.abort();document.body.classList.remove('detail-open');if(opener?.isConnected)opener.focus();});
    document.addEventListener('click',event=>{
        const show=event.target.closest('[data-detail-kind]');
        if(show){event.preventDefault();openDetail(show.dataset.detailKind,show.dataset.detailId,show);}
        if(event.target.closest('[data-detail-close]'))dialog?.close();
    });
    dialog?.addEventListener('click',event=>{
        if(event.target!==dialog)return;
        const box=dialog.getBoundingClientRect();if(event.clientX<box.left||event.clientX>box.right||event.clientY<box.top||event.clientY>box.bottom)dialog.close();
    });
    function init() {
        document.querySelectorAll('.cart-editor').forEach(initCart);
        const completed=document.querySelector('[data-open-operation]:not([data-opened])');
        if(completed){completed.dataset.opened='true';openDetail(completed.dataset.kind,completed.dataset.id);}
    }
    document.body.addEventListener('htmx:afterSettle',init);document.body.addEventListener('htmx:historyRestore',init);init();
})();
