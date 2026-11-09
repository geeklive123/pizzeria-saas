document.querySelectorAll('[data-financial-recovery]').forEach((box) => {
    const find = (name) => box.querySelector(`[data-financial-${name}]`);
    const form = box.querySelector('[data-cash-payment]');
    const submit = form.querySelector('[type="submit"]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const storageKey = `financial-payment:${box.dataset.order}`;
    let attempt = null;
    let savedKey = null;
    let conflict = false;
    let retryPrepared = false;
    let busy = false;
    let refreshPending = false;

    const block = (blocked) => {
        box.dataset.financialBlocked = blocked ? '1' : '0';
        submit.disabled = blocked;
    };
    const message = (text) => { find('message').textContent = text; };
    const storage = {
        read() { return localStorage.getItem(storageKey); },
        write(value) { localStorage.setItem(storageKey, value); },
        clear() { localStorage.removeItem(storageKey); },
    };
    const resetActions = () => ['reconcile', 'retry', 'refresh'].forEach((name) => { find(name).hidden = true; });
    const status = async () => {
        if (busy) { refreshPending = true; return; }
        busy = true;
        block(true);
        resetActions();
        try {
            savedKey = storage.read();
            const params = new URLSearchParams();
            if (savedKey) params.set('key', savedKey);
            if (box.dataset.dispatch) params.set('dispatch', box.dataset.dispatch);
            const response = await fetch(`${box.dataset.statusUrl}?${params}`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('No se pudo consultar el estado.');
            const data = await response.json();
            attempt = data.attempt;
            const balance = `Saldo actual: BOB ${data.balance}.`;
            if (attempt?.active) {
                message(`Hay un cobro sin resolver por BOB ${attempt.amount}. No vuelvas a cobrar hasta verificarlo. ${balance}`);
                find('reconcile').hidden = false;
            } else if (savedKey && attempt?.idempotency_key === savedKey && attempt.status === 'confirmed') {
                message(`El cobro de BOB ${attempt.amount} está confirmado. ${balance} Revisa el saldo antes de continuar.`);
                find('refresh').hidden = false;
            } else if (savedKey && (conflict || (attempt?.idempotency_key === savedKey && attempt.status === 'failed'))) {
                message(`El pedido cambió o el intento fue rechazado. ${balance} Revisa el saldo antes de decidir otro cobro.`);
                find('refresh').hidden = false;
            } else if (savedKey) {
                message(`No se pudo confirmar el resultado del cobro. No vuelvas a cobrar; consulta al responsable. ${balance}`);
            } else if (data.qr_blocked || localStorage.getItem(`qr-generation:${box.dataset.order}`)) {
                message(`Hay un QR pendiente o sin confirmar. Consulta su estado antes de cobrar en efectivo. ${balance}`);
                box.dataset.financialBlocked = '0';
            } else if (String(data.financial_version) !== box.dataset.version) {
                message(`El pedido cambió. ${balance} Revisa el saldo antes de decidir otro cobro.`);
                find('refresh').hidden = false;
            } else {
                message(`Estado consultado. ${balance}`);
                block(false);
            }
        } catch {
            message('No se pudo verificar el cobro. No vuelvas a cobrar hasta consultar su estado.');
        } finally {
            busy = false;
            if (refreshPending) { refreshPending = false; status(); }
        }
    };
    find('check').addEventListener('click', status);
    find('refresh').addEventListener('click', () => {
        try { storage.clear(); } catch { message('No se pudo guardar el estado. Consulta al responsable.'); return; }
        window.location.reload();
    });
    find('reconcile').addEventListener('click', async () => {
        if (!attempt || busy) return;
        busy = true;
        try {
            const response = await fetch(attempt.reconcile_url, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } });
            if (!response.ok) throw new Error('No se pudo verificar.');
            const data = await response.json();
            attempt = data.attempt;
            if (attempt.status === 'retryable' && attempt.owned_by_current_user && (attempt.dispatch_ulid || '') === (box.dataset.dispatch || '') && attempt.method === 'cash') {
                message('No se encontró un pago confirmado. Puedes preparar el mismo intento; confirma nuevamente antes de enviarlo.');
                find('reconcile').hidden = true;
                find('retry').hidden = false;
            } else if (attempt.status === 'confirmed') {
                message('El pago está confirmado. Revisa el saldo actualizado antes de continuar.');
                find('reconcile').hidden = true;
                find('refresh').hidden = false;
            } else {
                message('El cobro sigue sin resolver. Consulta al responsable antes de cobrar.');
            }
        } catch {
            message('No se pudo verificar el intento. No vuelvas a cobrar hasta consultar su estado.');
        } finally {
            busy = false;
            if (refreshPending) { refreshPending = false; status(); }
        }
    });
    find('retry').addEventListener('click', () => {
        if (!attempt?.idempotency_key) return;
        form.elements.idempotency_key.value = attempt.idempotency_key;
        form.elements.amount.value = attempt.amount;
        form.elements.received_amount.value = attempt.received_amount || attempt.amount;
        retryPrepared = true;
        find('retry').hidden = true;
        message('El mismo cobro está preparado. Comprueba el importe y pulsa Registrar efectivo para reintentarlo.');
        submit.disabled = false;
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || (box.dataset.financialBlocked !== '0' && !retryPrepared)) return;
        const key = form.elements.idempotency_key.value;
        try { storage.write(key); } catch { block(true); message('No se puede guardar la identidad del cobro. Consulta al responsable.'); return; }
        busy = true;
        block(true);
        message('Procesando cobro. No vuelvas a cobrar hasta verificar el resultado.');
        try {
            const response = await fetch(form.action, { method: 'POST', credentials: 'same-origin', body: new FormData(form), headers: { Accept: 'text/html' } });
            conflict = response.status === 409;
        } catch {
            // A lost response does not imply that the payment failed.
        }
        retryPrepared = false;
        busy = false;
        refreshPending = false;
        await status();
    });
    window.addEventListener('storage', (event) => { if ([storageKey, `qr-generation:${box.dataset.order}`].includes(event.key)) { retryPrepared = false; status(); } });
    window.addEventListener('financial-qr-change', () => { retryPrepared = false; status(); });
    status();
});
