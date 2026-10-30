(() => {
    const dialog=document.querySelector('#download-dialog');if(!dialog)return;
    let opener,loader,previewRequest,timer;
    function initForm() {
        const form=dialog.querySelector('form');if(!form)return;
        const status=form.querySelector('.download-preview'),submit=form.querySelector('[data-download-submit]');let verified='';
        const signature=()=>new URLSearchParams(new FormData(form)).toString();
        async function preview() {
            previewRequest?.abort();const request=new AbortController();previewRequest=request;
            const parameters=new URLSearchParams(new FormData(form));const stamp=parameters.toString();
            parameters.set('preview_export',parameters.get('export'));parameters.delete('export');
            submit.disabled=true;verified='';status.textContent='Comprobando selección…';status.className='download-preview notification is-light';
            try {
                const response=await fetch('index.php?'+parameters,{signal:request.signal});const data=await response.json();
                if(request!==previewRequest||!dialog.open||stamp!==signature())return;
                if(!response.ok)throw new Error(data.error||'No se pudo consultar la selección.');
                status.textContent=`Coinciden ${data.matches.toLocaleString('es-CR')} registros. Se seleccionan ${data.records.toLocaleString('es-CR')}${data.detail_rows!==null?`, con ${data.detail_rows.toLocaleString('es-CR')} renglones de repuestos completos`:''}.${data.amount?` Monto: ${data.amount}.`:''}${data.summary_only?' El PDF muestra el resumen; Excel y CSV permiten consultar el detalle.':''}`;
                if(!data.records)status.textContent+=' No hay resultados para descargar; ajuste los filtros.';
                verified=stamp;submit.disabled=!data.records;
            } catch(error) {if(error.name!=='AbortError'){status.textContent=error.message;status.classList.add('is-warning');}}
        }
        function changed(){clearTimeout(timer);previewRequest?.abort();submit.disabled=true;verified='';timer=setTimeout(preview,450);}
        form.addEventListener('input',changed);form.addEventListener('change',changed);
        // El buscador actualiza el identificador oculto al elegir una sugerencia.
        form.addEventListener('click',event=>{if(event.target.closest('.autocomplete-option,.autocomplete-clear'))changed();});
        form.addEventListener('submit',event=>{if(!verified||verified!==signature()){event.preventDefault();clearTimeout(timer);preview();}});
        preview();
    }
    document.addEventListener('click',async event=>{
        const link=event.target.closest('.export-links a,.download-options a');
        if(link&&!event.ctrlKey&&!event.metaKey&&!event.shiftKey&&event.button===0) {
            const url=new URL(link.href);if(!url.searchParams.has('export'))return;
            event.preventDefault();opener=link;loader?.abort();previewRequest?.abort();clearTimeout(timer);loader=new AbortController();const request=loader;
            url.searchParams.set('download_form',url.searchParams.get('export'));url.searchParams.delete('export');
            dialog.innerHTML='<header class="operation-heading"><h2 id="download-title">Descargar información</h2><button type="button" class="detail-close" data-download-close aria-label="Cerrar descargas">×</button></header><div class="operation-body">Preparando filtros…</div>';
            dialog.showModal();document.body.classList.add('detail-open');
            try {
                const response=await fetch(url,{signal:request.signal});const html=await response.text();
                if(request!==loader||!dialog.open)return;
                dialog.innerHTML=html;
                // Reutiliza la inicialización de buscadores, sin cambiar la navegación.
                document.body.dispatchEvent(new CustomEvent('htmx:afterSettle',{detail:{target:dialog}}));
                initForm();dialog.querySelector('[data-download-close]')?.focus({preventScroll:true});
            } catch(error) {if(error.name!=='AbortError')dialog.querySelector('.operation-body').textContent='No se pudieron cargar los filtros. Cierre e intente nuevamente.';}
        }
        if(event.target.closest('[data-download-close]'))dialog.close();
    });
    dialog.addEventListener('click',event=>{
        if(event.target!==dialog)return;
        const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)dialog.close();
    });
    dialog.addEventListener('close',()=>{loader?.abort();previewRequest?.abort();clearTimeout(timer);document.body.classList.remove('detail-open');if(opener?.isConnected)opener.focus({preventScroll:true});});
})();
