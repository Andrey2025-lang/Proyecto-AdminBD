(() => {
    const dialog=document.querySelector('#supplier-catalog');if(!dialog)return;
    let controller,opener,saving=false;
    async function load(url,options={}) {
        controller?.abort();controller=new AbortController();const request=controller;
        try {
            const response=await fetch(url,{...options,signal:request.signal});const html=await response.text();
            if(request!==controller||!dialog.open)return;
            dialog.innerHTML=html;
            document.body.dispatchEvent(new CustomEvent('htmx:afterSettle',{detail:{target:dialog}}));
            dialog.querySelector('.notification[role=alert]')?.scrollIntoView({block:'nearest'});
        } catch(error) {
            if(error.name!=='AbortError') {
                const message=document.createElement('div');message.className='notification is-danger is-light';message.textContent='No se pudo confirmar la operación. Recargue el catálogo antes de reintentar.';
                dialog.querySelector('.operation-body')?.prepend(message);
            }
        } finally {saving=false;dialog.querySelectorAll('[data-catalog-saving]').forEach(button=>{button.disabled=false;button.removeAttribute('data-catalog-saving');});}
    }
    document.addEventListener('click',event=>{
        const show=event.target.closest('[data-supplier-catalog]');
        if(show) {
            event.preventDefault();
            opener=[...document.querySelectorAll('[data-supplier-catalog]')].find(el=>el.dataset.supplierCatalog===show.dataset.supplierCatalog&&!el.closest('dialog'))||show;
            document.querySelector('#record-editor[open]')?.close();
            dialog.innerHTML='<header class="operation-heading"><h2 id="supplier-catalog-title">Repuestos del proveedor</h2><button type="button" class="detail-close" data-supplier-close aria-label="Cerrar catálogo">×</button></header><div class="operation-body">Cargando catálogo…</div>';
            dialog.showModal();document.body.classList.add('detail-open');load(`index.php?catalog_supplier=${encodeURIComponent(show.dataset.supplierCatalog)}`);
        }
        const link=event.target.closest('#supplier-catalog [data-supplier-fetch]');
        if(link&&!saving){event.preventDefault();load(link.href);}
        if(event.target.closest('[data-supplier-close]')&&!saving)dialog.close();
    });
    dialog.addEventListener('submit',event=>{
        const form=event.target;if(!form.matches('.supplier-part-form,.supplier-catalog-search'))return;
        event.preventDefault();if(saving)return;
        const values=new URLSearchParams(new FormData(form));
        if(form.matches('.supplier-part-form')) {
            saving=true;form.querySelectorAll('button').forEach(button=>{if(!button.disabled){button.disabled=true;button.setAttribute('data-catalog-saving','');}});
            load(form.action,{method:'POST',body:values});
        } else load('index.php?'+values);
    });
    dialog.addEventListener('cancel',event=>{if(saving)event.preventDefault();});
    dialog.addEventListener('click',event=>{
        if(event.target!==dialog||saving)return;
        const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)dialog.close();
    });
    dialog.addEventListener('close',()=>{controller?.abort();document.body.classList.toggle('detail-open',!!document.querySelector('dialog[open]'));if(opener?.isConnected)opener.focus({preventScroll:true});});
})();
