(() => {
    const dialog=document.querySelector('#record-editor');
    if(!dialog)return;
    let opener,controller,busy=false;
    const header='<header class="operation-heading"><h2 id="edit-title">Editar registro</h2><button type="button" class="detail-close" data-edit-close aria-label="Cerrar edición">×</button></header><div class="operation-body"></div>';
    function showForm(html) {
        const doc=new DOMParser().parseFromString(html,'text/html');
        const panel=doc.querySelector('.form-panel');
        if(!panel)throw new Error('No se pudo cargar el formulario.');
        const title=panel.querySelector('h2')?.textContent||'Editar registro';
        dialog.querySelector('#edit-title').textContent=title;
        const body=dialog.querySelector('.operation-body');body.replaceChildren();
        doc.querySelectorAll('.notification.is-danger').forEach(el=>body.append(el));
        body.append(panel);
        const form=panel.querySelector('form');
        for(const attribute of [...form.attributes])if(attribute.name.startsWith('hx-'))form.removeAttribute(attribute.name);
        form.addEventListener('submit',save);
        body.querySelector('input:not([type=hidden]),select')?.focus({preventScroll:true});
    }
    async function save(event) {
        event.preventDefault();if(busy)return;busy=true;
        const form=event.currentTarget;
        const values=new URLSearchParams(new FormData(form));
        dialog.querySelectorAll('button').forEach(el=>el.disabled=true);
        try {
            const response=await fetch(form.action,{method:'POST',body:values,headers:{'HX-Request':'true'}});
            const html=await response.text();
            if(!response.ok) {showForm(html);return;}
            const notice=new DOMParser().parseFromString(html,'text/html').querySelector('.notification.is-success');
            // Recargar el mismo listado, no la URL del formulario: conserva filtros y página.
            const list=await fetch(location.href,{headers:{'HX-Request':'true'}});
            if(!list.ok)throw new Error('El registro se guardó, pero no se pudo actualizar el listado. Recargue la página.');
            const content=document.querySelector('#content'),scroll=window.scrollY;
            content.innerHTML=await list.text();
            if(notice)content.prepend(notice);
            window.htmx?.process(content);
            document.body.dispatchEvent(new CustomEvent('htmx:afterSettle',{detail:{target:content}}));
            busy=false;dialog.close();window.scrollTo({top:scroll,behavior:'instant'});
        } catch(error) {
            const message=document.createElement('div');message.className='notification is-danger is-light';message.setAttribute('role','alert');
            message.textContent=error.message==='Failed to fetch'?'No se pudo confirmar el guardado. Compruebe la conexión y revise el listado antes de reintentar.':error.message;
            dialog.querySelector('.operation-body').prepend(message);
        } finally {busy=false;dialog.querySelectorAll('button').forEach(el=>el.disabled=false);}
    }
    document.addEventListener('click',async event=>{
        const link=event.target.closest('[data-edit-record]');
        if(link) {
            event.preventDefault();opener=link;controller?.abort();controller=new AbortController();const request=controller;
            dialog.innerHTML=header;dialog.querySelector('.operation-body').textContent='Cargando información…';
            dialog.showModal();document.body.classList.add('detail-open');
            try {
                const response=await fetch(link.href,{headers:{'HX-Request':'true'},signal:request.signal});
                const html=await response.text();
                if(!response.ok)throw new Error('No se pudo cargar la información.');
                if(dialog.open&&request===controller)showForm(html);
            } catch(error) {if(error.name!=='AbortError')dialog.querySelector('.operation-body').textContent=error.message;}
        }
        if(event.target.closest('[data-edit-close]')&&!busy)dialog.close();
    });
    dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
    dialog.addEventListener('click',event=>{
        if(event.target!==dialog||busy)return;
        const rect=dialog.getBoundingClientRect();
        if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)dialog.close();
    });
    dialog.addEventListener('close',()=>{controller?.abort();document.body.classList.remove('detail-open');if(opener?.isConnected)opener.focus({preventScroll:true});});
})();
