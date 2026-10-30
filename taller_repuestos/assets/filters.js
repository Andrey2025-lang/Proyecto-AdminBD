(() => {
    const timers=new WeakMap();
    function cancel(form) {
        clearTimeout(timers.get(form));
        window.htmx.trigger(form,'htmx:abort');
    }
    function ready(form) {
        const query=form.elements.q.value.trim();
        const valid=query.length===0||[...query].length>=2;
        form.querySelector('[data-search-status]').textContent=valid
            ?'Los filtros se aplican automáticamente.'
            :'Escriba al menos 2 caracteres para buscar. Se mantienen los resultados anteriores.';
        return valid&&form.checkValidity();
    }
    function apply(form) {
        if(!form.isConnected||!ready(form))return;
        form.querySelector('[data-search-status]').textContent='Buscando…';
        window.htmx.trigger(form,'live-filter');
    }
    document.addEventListener('input',event=>{
        const form=event.target.closest('.filter-bar');
        if(!form)return;
        cancel(form);
        if(event.isComposing||!ready(form))return;
        timers.set(form,setTimeout(()=>apply(form),350));
    });
    document.addEventListener('compositionend',event=>{
        if(event.target.closest('.filter-bar'))event.target.dispatchEvent(new Event('input',{bubbles:true}));
    });
    document.addEventListener('change',event=>{
        const form=event.target.closest('.filter-bar');
        if(!form||event.target.name==='q')return;
        cancel(form);apply(form);
    });
    document.addEventListener('submit',event=>{
        const form=event.target.closest('.filter-bar');
        if(!form)return;
        event.preventDefault();event.stopImmediatePropagation();cancel(form);apply(form);
    },true);
    document.body.addEventListener('htmx:beforeRequest',event=>{
        if(!event.detail.elt.closest('.filter-bar')) {
            document.querySelectorAll('.filter-bar').forEach(cancel);
        }
    });
    document.body.addEventListener('htmx:beforeCleanupElement',event=>{
        if(event.detail.elt instanceof Element&&event.detail.elt.matches('.filter-bar'))clearTimeout(timers.get(event.detail.elt));
    });
})();
