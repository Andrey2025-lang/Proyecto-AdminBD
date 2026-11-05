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
        const supplierPicker=form.querySelector('[data-supplier-picker]');
        const taxSelect=form.querySelector('[name=tarifa_compra]');
        const taxRates=JSON.parse(editor.querySelector('.tax-rates').textContent);
        let lines=JSON.parse(editor.querySelector('.cart-seed').textContent);
        let supplierRequest=null, supplierSequence=0;
        const cents=value=>/^\d{1,8}(?:\.\d{1,2})?$/.test(String(value))?Math.round(Number(value)*100):null;
        function warn(text) { alert.textContent=text; alert.hidden=!text; }
        function sync() {
            let total=0, base=0,valid=lines.length>0;
            const rate=sale?Number(editor.dataset.taxRate):taxRates.find(rate=>String(rate.id_tarifa)===taxSelect.value)?.porcentaje;
            const ratePoints=rate===undefined?null:Math.round(Number(rate)*100);
            for(const line of lines) {
                const cost=cents(line.precio);
                if(!Number.isInteger(line.cantidad)||line.cantidad<1||line.cantidad>2147483647||cost===null||(sale&&line.cantidad>line.stock)) valid=false;
                if(!sale&&(!supplier.value||String(line.supplier)!==supplier.value||line.supplierValid===false))valid=false;
                if(cost!==null && Number.isInteger(line.cantidad)) {
                    const amount=cost*line.cantidad;total+=amount;
                    if(ratePoints!==null)base+=Math.round(amount*10000/(10000+ratePoints));
                }
            }
            if(total>9999999999) valid=false;
            serialized.value=JSON.stringify(lines.map(line=>({id_repuesto:line.id_repuesto,cantidad:line.cantidad,...(sale?{precio_esperado:String(line.precio)}:{precio_unitario:String(line.precio)})})));
            editor.querySelector('#total-preview').textContent=number.format(total/100);
            editor.querySelector('[data-tax-note]').textContent=ratePoints===null?'Desglose de IVA no registrado.':`Los precios incluyen IVA (${Number(rate).toFixed(2)}%). No se suma otra vez al total.`;
            editor.querySelector('[data-tax-breakdown]').hidden=ratePoints===null;
            editor.querySelector('[data-tax-base]').textContent=number.format(base/100);
            editor.querySelector('[data-tax-amount]').textContent=number.format((total-base)/100);
            if(!sale&&(ratePoints===null||taxSelect.value==='0'))valid=false;
            if(!sale) {
                editor.querySelector('[data-selected-supplier]').hidden=!supplier.value;
                supplierPicker.hidden=!!supplier.value;
                editor.querySelector('[data-supplier-name]').textContent=supplier.dataset.label||form.querySelector('#search-id_proveedor').value;
                editor.querySelector('[data-change-supplier]').disabled=lines.length>0;
            }
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
            if(!sale&&!supplier.value) {warn('Elija uno de los proveedores del repuesto antes de agregarlo.');return;}
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
        supplier?.addEventListener('change',async()=>{
            supplierRequest?.abort();const sequence=++supplierSequence;
            const pendingPart=source.value, selectedSupplier=supplier.value;
            source.closest('.autocomplete').dispatchEvent(new Event('reset-selection'));
            if(lines.length){warn('Cambió el proveedor. Se conservaron los renglones para que pueda revisarlos; quite los anteriores y agregue los del proveedor actual.');render();}
            else sync();
            if(!pendingPart||!selectedSupplier)return;
            warn('Comprobando si el proveedor ofrece el repuesto elegido…');
            supplierRequest=new AbortController();
            try {
                const response=await fetch(`index.php?part_suppliers=${encodeURIComponent(pendingPart)}&supplier=${encodeURIComponent(selectedSupplier)}`,{signal:supplierRequest.signal});
                const data=await response.json();if(!response.ok)throw new Error(data.error);
                if(sequence!==supplierSequence||!editor.isConnected||supplier.value!==selectedSupplier||source.value||search.value.trim())return;
                const item=data.items[0];
                if(!item){warn('El proveedor elegido no tiene habilitado ese repuesto. Busque otro en su catálogo o cambie de proveedor.');return;}
                source.closest('.autocomplete').dispatchEvent(new CustomEvent('select-item',{detail:{id:item.id_repuesto,label:item.nombre,codigo_barras:item.codigo_barras,stock_actual:item.stock_actual,precio_compra:item.costo_referencia}}));
                warn('');quantity.focus();
            } catch(error) {
                if(error.name!=='AbortError'&&sequence===supplierSequence&&editor.isConnected)warn('No se pudo comprobar el repuesto. Búsquelo en el catálogo del proveedor o vuelva a elegir el proveedor.');
            }
        });
        editor.querySelector('[data-cart-clear]')?.addEventListener('click',()=>{lines=[];warn('');render();});
        editor.querySelector('[data-change-supplier]')?.addEventListener('click',()=>{
            if(lines.length)return;
            supplier.closest('.autocomplete').dispatchEvent(new Event('reset-selection'));
            warn('');form.querySelector('#search-id_proveedor').focus();
        });
        taxSelect?.addEventListener('change',sync);
        carts.set(editor,true);render();editor.dataset.ready='true';
    }

    const discoveries=new WeakSet();
    function initDiscovery(section) {
        if(discoveries.has(section))return;
        discoveries.add(section);
        const form=section.closest('form'), part=section.querySelector('[name=id_repuesto]');
        const supplier=form.querySelector('[name=id_proveedor]');
        const results=section.querySelector('[data-part-suppliers]');
        let controller=null, sequence=0;
        async function load(page=1) {
            controller?.abort();const request=++sequence;
            const selected=part.value;
            if(page===1)results.replaceChildren();
            if(!selected||supplier.value)return;
            const status=document.createElement('p');status.setAttribute('role','status');status.textContent='Buscando proveedores…';results.append(status);
            controller=new AbortController();
            try {
                const response=await fetch(`index.php?part_suppliers=${encodeURIComponent(selected)}&sp=${page}`,{signal:controller.signal});
                const data=await response.json();
                if(!response.ok)throw new Error(data.error);
                if(request!==sequence||!section.isConnected)return;
                status.remove();
                if(!data.items.length&&page===1){results.textContent='Este repuesto no tiene proveedores habilitados. Confirme su oferta desde Proveedores → Repuestos.';return;}
                data.items.forEach(item=>{
                    const row=document.createElement('div');row.className='box mb-2 p-3';
                    const label=document.createElement('strong');label.textContent=item.label;row.append(label);
                    const cost=document.createElement('p');cost.className='help';cost.textContent=item.costo_referencia===null?'Sin costo de referencia: indique el precio final con IVA.':`Costo de referencia: ${number.format(Number(item.costo_referencia))} · Confirme el precio final con IVA.`;
                    if(item.codigo_proveedor)cost.textContent+=` · Código: ${item.codigo_proveedor}`;
                    row.append(cost);
                    const button=document.createElement('button');button.type='button';button.className='button is-small is-primary is-light mt-2';button.textContent='Comprar a este proveedor';
                    button.addEventListener('click',()=>{
                        if(supplier.value||part.value!==String(item.id_repuesto))return;
                        supplier.closest('.autocomplete').dispatchEvent(new CustomEvent('select-item',{detail:{id:item.id,label:item.label}}));
                    });row.append(button);results.append(row);
                });
                if(data.has_more){const more=document.createElement('button');more.type='button';more.className='button is-small is-light';more.textContent='Mostrar más proveedores';more.addEventListener('click',()=>{more.remove();load(page+1);});results.append(more);}
            } catch(error) {
                if(error.name!=='AbortError'&&request===sequence){status.textContent='No se pudieron cargar los proveedores.';const retry=document.createElement('button');retry.type='button';retry.className='button is-small is-light';retry.textContent='Reintentar';retry.addEventListener('click',()=>{status.remove();retry.remove();load(page);});results.append(retry);}
            }
        }
        part.addEventListener('change',()=>load());
        supplier.addEventListener('change',()=>load());
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
        document.querySelectorAll('[data-purchase-discovery]').forEach(initDiscovery);
        const completed=document.querySelector('[data-open-operation]:not([data-opened])');
        if(completed){completed.dataset.opened='true';openDetail(completed.dataset.kind,completed.dataset.id);}
    }
    document.body.addEventListener('htmx:afterSettle',init);document.body.addEventListener('htmx:historyRestore',init);init();
})();
