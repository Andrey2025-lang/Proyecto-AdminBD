(() => {
    const states = new WeakMap();
    const debounceMs = 600;

    function normalized(value) {
        const id = value.replace(/[\s-]+/g, '');
        return /^\d{9,12}$/.test(id) ? id : null;
    }
    function stateFor(form) {
        let state = states.get(form);
        if (!state) {
            state = { timer: null, controller: null, sequence: 0, inFlight: '', completed: '', currentId: '', lastAutomaticName: '' };
            states.set(form, state);
        }
        return state;
    }
    function panelFor(form) { return form.querySelector('[data-client-hacienda-panel]'); }
    function setStatus(form, message, type = '') {
        const status = panelFor(form)?.querySelector('[data-hacienda-status]');
        if (!status) return;
        status.textContent = message;
        status.className = `help hacienda-status${type ? ` is-${type}` : ''}`;
    }
    function hideDetails(form) {
        const panel = panelFor(form);
        if (!panel) return;
        panel.hidden = false;
        const details = panel.querySelector('[data-hacienda-details]');
        if (details) details.hidden = true;
    }
    function value(panel, selector, content) {
        const target = panel.querySelector(selector);
        if (target) target.textContent = content ?? '';
    }
    function scalar(content) {
        return content === null || content === undefined ? '' : typeof content === 'object' ? JSON.stringify(content) : String(content);
    }
    function activities(target, items) {
        target.replaceChildren();
        if (!Array.isArray(items) || items.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'hacienda-activity-empty';
            empty.textContent = 'Hacienda no devolvió actividades registradas.';
            target.append(empty);
            return;
        }
        items.forEach(item => {
            const card = document.createElement('article');
            card.className = 'hacienda-activity';
            const list = document.createElement('dl');
            const entries = item && typeof item === 'object' && !Array.isArray(item) ? Object.entries(item) : [['actividad', item]];
            entries.forEach(([key, itemValue]) => {
                const term = document.createElement('dt');
                const detail = document.createElement('dd');
                term.textContent = key;
                detail.textContent = scalar(itemValue);
                list.append(term, detail);
            });
            card.append(list); target.append(card);
        });
    }
    function render(form, data) {
        const panel = panelFor(form);
        if (!panel) return;
        panel.hidden = false;
        value(panel, '[data-hacienda-tipo]', data.tipo_identificacion);
        value(panel, '[data-hacienda-regimen]', `${scalar(data.regimen?.codigo)} · ${scalar(data.regimen?.descripcion)}`);
        value(panel, '[data-hacienda-estado]', data.situacion?.estado);
        value(panel, '[data-hacienda-moroso]', data.situacion?.moroso);
        value(panel, '[data-hacienda-omiso]', data.situacion?.omiso);
        value(panel, '[data-hacienda-administracion]', data.situacion?.administracion_tributaria);
        activities(panel.querySelector('[data-hacienda-activities]'), data.actividades);
        panel.querySelector('[data-hacienda-details]').hidden = false;
    }
    async function consult(form, id) {
        const state = stateFor(form);
        if (state.inFlight === id || state.completed === id) return;
        state.controller?.abort();
        const controller = new AbortController();
        const sequence = ++state.sequence;
        state.controller = controller; state.inFlight = id;
        hideDetails(form); setStatus(form, 'Consultando Hacienda...', 'loading');
        const url = new URL('index.php', window.location.href);
        url.searchParams.set('hacienda_consulta', '1');
        url.searchParams.set('identificacion', id);
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
            const result = await response.json();
            if (sequence !== state.sequence || !form.isConnected) return;
            state.inFlight = ''; state.completed = id;
            if (!response.ok || !result.ok) {
                const messages = { no_encontrado: 'Identificación no encontrada.', identificacion_invalida: 'Identificación inválida para la consulta.', servicio_no_disponible: 'Hacienda no está disponible. Puede continuar manualmente.' };
                setStatus(form, messages[result.error?.code] || result.error?.message || 'No se pudo completar la consulta.', 'error');
                return;
            }
            const name = form.querySelector('input[name="nombre"]');
            if (name && (!name.value.trim() || name.value === state.lastAutomaticName)) {
                name.value = result.data.nombre || '';
                state.lastAutomaticName = name.value;
            }
            render(form, result.data);
            setStatus(form, result.cached ? 'Información encontrada (recuperada de caché).' : 'Información encontrada.', 'success');
        } catch (error) {
            if (error.name === 'AbortError' || sequence !== state.sequence) return;
            state.inFlight = ''; state.completed = id;
            setStatus(form, 'No se pudo completar la consulta. Puede continuar manualmente.', 'error');
        }
    }
    function schedule(form, immediate = false) {
        const field = form.querySelector('input[name="cedula"]');
        if (!field) return;
        const state = stateFor(form); clearTimeout(state.timer);
        state.controller?.abort(); state.inFlight = '';
        const id = normalized(field.value);
        if (!id) {
            state.sequence++;
            state.currentId = '';
            state.completed = '';
            hideDetails(form);
            setStatus(form, field.value.trim() ? 'Identificación inválida para la consulta.' : '', field.value.trim() ? 'error' : '');
            return;
        }
        if (state.currentId !== id) {
            state.currentId = id;
            state.completed = '';
        }
        if (state.completed === id) return;
        state.timer = setTimeout(() => consult(form, id), immediate ? 0 : debounceMs);
    }
    document.addEventListener('input', event => {
        const field = event.target.closest('input[name="cedula"][data-hacienda-identificacion]');
        if (field) schedule(field.closest('form'));
    });
    document.addEventListener('blur', event => {
        const field = event.target.closest('input[name="cedula"][data-hacienda-identificacion]');
        if (field) schedule(field.closest('form'), true);
    }, true);
})();
