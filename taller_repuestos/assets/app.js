(() => {
    const format = new Intl.NumberFormat('es', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function refreshEstimate(changed) {
        const select = document.querySelector('#id_repuesto');
        if (!select) return;
        const option = select.selectedOptions[0];
        const quantity = document.querySelector('#cantidad');
        const priceInput = document.querySelector('#precio_unitario');
        const sale = select.dataset.operation === 'sale';
        if (!sale && changed === select && priceInput) priceInput.value = option.dataset.price || '0.00';
        const price = sale ? Number(option.dataset.price || 0) : Number(priceInput?.value || 0);
        document.querySelector('#total-preview').textContent = format.format(price * Number(quantity.value || 0));
        document.querySelector('#stock-hint').textContent = select.value
            ? `Existencias: ${option.dataset.stock} unidades.${sale ? ` Precio unitario: ${format.format(price)}` : ''}`
            : 'Seleccione una referencia para consultar sus existencias.';
        if (sale) {
            quantity.max = select.value ? option.dataset.stock : '2147483647';
            quantity.setCustomValidity(select.value && Number(quantity.value) > Number(option.dataset.stock)
                ? 'La cantidad supera las existencias disponibles.' : '');
        }
    }
    function updateNav() {
        const page = new URL(location.href).searchParams.get('page') || 'inicio';
        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.toggle('active', link.dataset.page === page);
            if (link.dataset.page === page) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
        refreshEstimate();
    }
    document.addEventListener('input', event => {
        if (['id_repuesto', 'cantidad', 'precio_unitario'].includes(event.target.id)) refreshEstimate(event.target);
    });
    document.addEventListener('change', event => {
        if (event.target.id === 'id_repuesto') refreshEstimate(event.target);
    });
    document.body.addEventListener('htmx:beforeSwap', event => {
        if ([422, 503].includes(event.detail.xhr.status)) {
            event.detail.shouldSwap = true;
            event.detail.isError = false;
        }
    });
    document.body.addEventListener('htmx:afterSettle', updateNav);
    document.body.addEventListener('htmx:pushedIntoHistory', updateNav);
    document.body.addEventListener('htmx:historyRestore', updateNav);
    document.body.addEventListener('htmx:sendError', () => {
        const message = document.createElement('div');
        message.className = 'notification is-danger is-light';
        message.setAttribute('role', 'alert');
        message.textContent = 'No se pudo contactar con el servidor. Compruebe la conexión antes de volver a enviar la operación.';
        document.querySelector('#content').prepend(message);
    });
    updateNav();
})();
