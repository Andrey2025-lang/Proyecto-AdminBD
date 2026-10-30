(() => {
    document.documentElement.classList.add('js');
    const format = new Intl.NumberFormat('es', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const widgets = new WeakMap();
    const mobile = window.matchMedia('(max-width: 680px)');
    const sidebar = document.querySelector('#sidebar');
    const toggle = document.querySelector('#sidebar-toggle');
    const mobileToggle = document.querySelector('#sidebar-mobile-toggle');
    const backdrop = document.querySelector('.sidebar-backdrop');
    let pinned=false;
    try {pinned=localStorage.getItem('repuesto.sidebar.pinned')==='true';} catch (_) {}
    let hoverTimer;
    function expandSidebar() { if (!mobile.matches) { clearTimeout(hoverTimer); document.body.classList.add('sidebar-peek'); syncSidebar(); } }
    function leaveSidebar(event) {
        if (sidebar?.contains(event.relatedTarget) || toggle?.contains(event.relatedTarget)) return;
        clearTimeout(hoverTimer);
        hoverTimer=setTimeout(()=>{document.body.classList.remove('sidebar-peek');syncSidebar();},160);
    }
    [sidebar,toggle].forEach(element=>{
        element?.addEventListener('pointerenter',expandSidebar);
        element?.addEventListener('pointerleave',leaveSidebar);
        element?.addEventListener('focusin',expandSidebar);
        element?.addEventListener('focusout',leaveSidebar);
    });
    function syncSidebar() {
        const open = document.body.classList.contains('sidebar-open');
        const expanded=pinned||document.body.classList.contains('sidebar-peek');
        document.body.classList.toggle('sidebar-collapsed', !mobile.matches && !expanded);
        if (sidebar) {
            sidebar.inert = mobile.matches && !open;
            sidebar.setAttribute('aria-hidden', String(mobile.matches && !open));
        }
        if (backdrop) backdrop.hidden = !mobile.matches || !open;
        const workspace = document.querySelector('.workspace');
        if (workspace) workspace.inert = mobile.matches && open;
        if (toggle) {
            toggle.setAttribute('aria-expanded', String(mobile.matches ? open : expanded));
            toggle.setAttribute('aria-label', mobile.matches ? (open ? 'Cerrar menú' : 'Abrir menú') : (pinned ? 'Soltar menú fijo' : 'Fijar menú abierto'));
            toggle.setAttribute('aria-pressed',String(!mobile.matches&&pinned));
            toggle.title=pinned?'Soltar menú fijo':'Fijar menú abierto';
            toggle.querySelector('span').textContent=pinned?'Menú fijo':'Fijar menú';
        }
        mobileToggle?.setAttribute('aria-expanded',String(mobile.matches&&open));
        mobileToggle?.setAttribute('aria-label',open?'Cerrar menú':'Abrir menú');
    }
    function closeSidebar() { document.body.classList.remove('sidebar-open','sidebar-peek'); syncSidebar(); }
    toggle?.addEventListener('click', () => {
        if (mobile.matches) document.body.classList.toggle('sidebar-open');
        else {
            pinned=!pinned;
            try {localStorage.setItem('repuesto.sidebar.pinned',String(pinned));} catch (_) {}
            if(!pinned)document.body.classList.remove('sidebar-peek');
        }
        syncSidebar();
        if (mobile.matches && document.body.classList.contains('sidebar-open')) sidebar.querySelector('a')?.focus();
    });
    mobileToggle?.addEventListener('click',()=>{
        document.body.classList.toggle('sidebar-open');syncSidebar();
        if(document.body.classList.contains('sidebar-open'))sidebar.querySelector('a')?.focus();
    });
    backdrop?.addEventListener('click', () => { closeSidebar(); mobileToggle?.focus(); });
    document.querySelector('.sidebar-close')?.addEventListener('click', () => { closeSidebar(); mobileToggle?.focus(); });
    sidebar?.addEventListener('click', event => { if (event.target.closest('a') && mobile.matches) closeSidebar(); });
    mobile.addEventListener('change', closeSidebar);
    syncSidebar();

    function refreshEstimate(changed) {
        const part = document.querySelector('#id_repuesto');
        if (!part) return;
        const quantity = document.querySelector('#cantidad');
        const priceInput = document.querySelector('#precio_unitario');
        const sale = part.dataset.operation === 'sale';
        if (!sale && changed === part && priceInput) priceInput.value = part.value ? part.dataset.price : '0.00';
        const price = sale ? Number(part.dataset.price || 0) : Number(priceInput?.value || 0);
        if (!document.querySelector('.cart-editor')) document.querySelector('#total-preview').textContent = format.format(price * Number(quantity.value || 0));
        document.querySelector('#stock-hint').textContent = part.value
            ? `Existencias: ${part.dataset.stock} unidades.${sale ? ` Precio unitario: ${format.format(price)}` : ''}`
            : 'Seleccione una referencia para consultar sus existencias.';
        if (sale) {
            quantity.max = part.value ? part.dataset.stock : '2147483647';
            quantity.setCustomValidity(part.value && Number(quantity.value) > Number(part.dataset.stock)
                ? 'La cantidad supera las existencias disponibles.' : '');
        }
    }
    function initAutocomplete(widget) {
        if (widgets.has(widget)) return;
        const input = widget.querySelector('.autocomplete-input');
        const hidden = widget.querySelector('input[type=hidden]');
        const menu = widget.querySelector('.autocomplete-menu');
        const list = widget.querySelector('.autocomplete-options');
        const status = widget.querySelector('.autocomplete-status');
        const more = widget.querySelector('.autocomplete-more');
        const clear = widget.querySelector('.autocomplete-clear');
        const state = { items: [], page: 0, term: '', active: -1, open: false, controller: null, sequence: 0, timer: null, loading: false };
        widgets.set(widget, state);
        input.setCustomValidity(hidden.value || !input.required ? '' : 'Busque y seleccione una opción de la lista.');
        function close() {
            state.open = false; menu.hidden = true;
            input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant');
        }
        state.close = close;
        function positionMenu() {
            const control = widget.querySelector('.autocomplete-control');
            const box = control.getBoundingClientRect();
            const below = innerHeight - box.bottom;
            const upwards = below < 230 && box.top > below;
            menu.style.top = upwards ? 'auto' : `${control.offsetTop + control.offsetHeight + 5}px`;
            menu.style.bottom = upwards ? `${widget.clientHeight - control.offsetTop + 5}px` : 'auto';
            list.style.maxHeight = `${Math.min(260, Math.max(110, (upwards ? box.top : below) - 85))}px`;
        }
        function open() { state.open = true; menu.hidden = false; input.setAttribute('aria-expanded', 'true'); positionMenu(); }
        function clearSelection() {
            hidden.value = ''; hidden.dataset.price = ''; hidden.dataset.stock = ''; hidden.dataset.label = ''; hidden.dataset.code = '';
            input.removeAttribute('aria-activedescendant'); state.active = -1;
            clear.hidden = true; input.setCustomValidity(input.required||input.value.trim()?'Busque y seleccione una opción de la lista.':'');
            if (hidden.id === 'id_repuesto') refreshEstimate(hidden);
        }
        state.reset=()=>{ state.controller?.abort(); clearTimeout(state.timer); state.sequence++; input.value=''; clearSelection(); close(); };
        widget.addEventListener('reset-selection',()=>state.reset());
        function activate(index) {
            const options = list.querySelectorAll('[role=option]');
            state.active = index;
            options.forEach((option, i) => { option.classList.toggle('is-active', i === index); option.setAttribute('aria-selected', String(i === index)); });
            if (options[index]) {
                input.setAttribute('aria-activedescendant', options[index].id);
                options[index].scrollIntoView({ block: 'nearest' });
            } else input.removeAttribute('aria-activedescendant');
        }
        function choose(item) {
            state.controller?.abort(); clearTimeout(state.timer); state.sequence++;
            hidden.value = String(item.id); input.value = item.label;
            hidden.dataset.label=item.label; hidden.dataset.code=item.codigo_barras||'';
            if (hidden.id === 'id_repuesto') {
                hidden.dataset.price = item[widget.dataset.operation === 'sale' ? 'precio_venta' : 'precio_compra'];
                hidden.dataset.stock = String(item.stock_actual);
                refreshEstimate(hidden);
            }
            input.setCustomValidity(''); clear.hidden = false; input.focus({ preventScroll: true }); close();
        }
        function render() {
            list.replaceChildren(); state.active = -1;
            state.items.forEach((item, index) => {
                const option = document.createElement('button');
                option.type = 'button'; option.tabIndex = -1; option.className = 'autocomplete-option';
                option.id = `${list.id}-${index}`; option.setAttribute('role', 'option'); option.setAttribute('aria-selected', 'false');
                const label = document.createElement('strong'); label.textContent = item.label; option.append(label);
                const detail = document.createElement('small');
                detail.textContent = widget.dataset.lookup.startsWith('repuestos')
                    ? `${item.codigo_barras} · ${item.stock_actual} unidades · ${format.format(Number(item[widget.dataset.operation === 'sale' ? 'precio_venta' : 'precio_compra']))}`
                    : (item.cedula ? `Identificación: ${item.cedula}` : 'Proveedor');
                option.append(detail);
                option.addEventListener('pointerdown', event => event.preventDefault());
                option.addEventListener('click', () => choose(item));
                option.addEventListener('pointermove', () => activate(index));
                list.append(option);
            });
        }
        async function load(append = false) {
            const term = hidden.value ? '' : [...input.value.trim()].slice(0, 100).join('');
            state.controller?.abort();
            const sequence = ++state.sequence;
            if (term !== '' && [...term].length < 2) {
                list.replaceChildren(); more.hidden = true; state.items = []; state.page = 0;
                state.loading = false; status.textContent = 'Escriba al menos 2 caracteres para buscar.'; return;
            }
            if (!append) { state.items = []; state.page = 0; list.replaceChildren(); list.scrollTop = 0; }
            state.term = term; state.loading = true; more.disabled = true;
            status.textContent = 'Buscando…';
            const controller = new AbortController(); state.controller = controller;
            const url = new URL('index.php', location.href);
            url.search = new URLSearchParams({ lookup: widget.dataset.lookup, operation: widget.dataset.operation, term, lp: String(state.page + 1) });
            try {
                const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Búsqueda no disponible.');
                if (sequence !== state.sequence || !widget.isConnected) return;
                state.items.push(...data.items); state.page = data.page;
                render(); more.hidden = !data.has_more;
                status.textContent = state.items.length ? `${state.items.length} resultados${data.has_more ? ' · Hay más coincidencias' : ''}` : 'No se encontraron coincidencias.';
            } catch (error) {
                if (error.name !== 'AbortError' && sequence === state.sequence) { status.textContent = 'No se pudo buscar. Intente nuevamente.'; more.hidden = true; }
            } finally {
                if (sequence === state.sequence) { state.loading = false; more.disabled = false; }
            }
        }
        input.addEventListener('focus', () => { open(); load(); });
        input.addEventListener('input', () => {
            clearSelection(); open(); clearTimeout(state.timer); state.controller?.abort(); state.sequence++;
            list.replaceChildren(); state.items = []; more.hidden = true;
            status.textContent = input.value.trim().length === 1 ? 'Escriba al menos 2 caracteres para buscar.' : 'Buscando…';
            state.timer = setTimeout(() => load(), 250);
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Escape') { close(); return; }
            if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                if (!state.open) { open(); load(); }
                if (state.items.length) activate(state.active < 0 ? (event.key === 'ArrowDown' ? 0 : state.items.length - 1) : (state.active + (event.key === 'ArrowDown' ? 1 : -1) + state.items.length) % state.items.length);
            } else if (event.key === 'Enter' && state.open) {
                event.preventDefault();
                if (hidden.value && state.active < 0) { close(); return; }
                if (state.items.length && !state.loading) choose(state.items[Math.max(0, state.active)]);
            }
        });
        clear.addEventListener('pointerdown', event => event.preventDefault());
        clear.addEventListener('click', () => { input.value = ''; clearSelection(); input.focus(); open(); load(); });
        more.addEventListener('pointerdown', event => event.preventDefault());
        more.addEventListener('click', () => { if (!state.loading) load(true); });
        widget.addEventListener('focusout', event => { if (!widget.contains(event.relatedTarget)) close(); });
        widget.dataset.ready = 'true';
        if (document.activeElement === input) { open(); load(); }
    }
    function updateNav() {
        const page = new URL(location.href).searchParams.get('page') || 'inicio';
        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.toggle('active', link.dataset.page === page);
            if (link.dataset.page === page) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
        document.querySelectorAll('.autocomplete').forEach(initAutocomplete);
        refreshEstimate();
    }
    document.addEventListener('pointerdown', event => {
        document.querySelectorAll('.autocomplete').forEach(widget => { if (!widget.contains(event.target)) widgets.get(widget)?.close(); });
    });
    document.addEventListener('keydown', event => {
        if (!mobile.matches || !document.body.classList.contains('sidebar-open')) return;
        if (event.key === 'Escape') { closeSidebar(); mobileToggle?.focus(); }
        if (event.key === 'Tab') {
            const links = [...sidebar.querySelectorAll('a,button')].filter(el=>el.getClientRects().length); const first = links[0], last = links.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    document.addEventListener('input', event => { if (['cantidad', 'precio_unitario'].includes(event.target.id)) refreshEstimate(event.target); });
    let pagerPosition=null;
    document.body.addEventListener('htmx:beforeRequest',event=>{
        const control=event.detail.elt?.closest('.pager-direction,.pager-jump');
        const pager=control?.closest('.pager');
        if(!pager){pagerPosition=null;return;}
        const table=pager.closest('.panel-card')?.querySelector('.table-container');
        pagerPosition={label:pager.getAttribute('aria-label'),height:table?.getBoundingClientRect().height||0,top:pager.getBoundingClientRect().top};
    });
    document.body.addEventListener('htmx:afterSwap',event=>{
        if(event.detail.target?.id!=='content'||!pagerPosition)return;
        const saved=pagerPosition;pagerPosition=null;
        const pager=[...document.querySelectorAll('.pager')].find(el=>el.getAttribute('aria-label')===saved.label);
        const table=pager?.closest('.panel-card')?.querySelector('.table-container');
        if(table)table.style.minHeight=`${saved.height}px`;
        if(pager)window.scrollBy({top:pager.getBoundingClientRect().top-saved.top,behavior:'instant'});
    });
    document.body.addEventListener('htmx:beforeSwap', event => {
        if ([422, 503].includes(event.detail.xhr.status)) { event.detail.shouldSwap = true; event.detail.isError = false; }
    });
    document.body.addEventListener('htmx:afterSettle', updateNav);
    document.body.addEventListener('htmx:afterSettle', event => {
        if (event.detail.target?.id==='content' && event.detail.requestConfig?.elt?.closest('.nav-link')) {
            window.scrollTo({top:0,left:0,behavior:'instant'});
            document.querySelector('#content')?.focus({preventScroll:true});
            closeSidebar();
        }
    });
    document.body.addEventListener('htmx:pushedIntoHistory', updateNav);
    document.body.addEventListener('htmx:historyRestore', updateNav);
    document.body.addEventListener('htmx:beforeCleanupElement', event => {
        const state = widgets.get(event.detail.elt);
        if (state) { state.controller?.abort(); clearTimeout(state.timer); }
    });
    document.body.addEventListener('htmx:sendError', () => {
        const message = document.createElement('div'); message.className = 'notification is-danger is-light'; message.setAttribute('role', 'alert');
        message.textContent = 'No se pudo contactar con el servidor. Compruebe la conexión antes de volver a enviar la operación.';
        document.querySelector('#content').prepend(message);
    });
    updateNav();
})();
