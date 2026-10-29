document.querySelectorAll('[data-dynamic-qr]').forEach((box) => {
    const el = (name) => box.querySelector(`[data-qr-${name}]`);
    const modal = el('modal');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const enabled = box.dataset.enabled === '1';
    const terminal = ['paid', 'cancelled', 'failed', 'generation_unknown', 'reconciliation_required'];
    let intent = null;
    let busy = false;
    let createUncertain = false;
    let timer;
    let polls = 0;
    let nextCheck = 0;
    let summary = null;
    const cents = (value) => {
        if (!/^\d{1,14}(?:\.\d{1,2})?$/.test(value)) return null;
        const [whole, decimal = ''] = value.split('.');
        return BigInt(whole) * 100n + BigInt(decimal.padEnd(2, '0'));
    };
    const money = (value) => `Bs ${value / 100n},${String(value % 100n).padStart(2, '0')}`;
    const updateEstimate = () => {
        const cash = cents(el('cash').value.trim());
        const received = cents(el('received').value.trim());
        const balance = cents(summary?.balance ?? '');
        el('estimate').textContent = cash !== null && balance !== null && cash <= balance ? `QR restante estimado: ${money(balance - cash)}` : 'QR restante estimado: —';
        el('change').textContent = cash !== null && received !== null && received >= cash ? `Cambio estimado: ${money(received - cash)}` : 'Cambio estimado: —';
    };

    const render = (data) => {
        intent = data.intent;
        summary = data.summary ?? summary;
        if (summary) {
            el('cash-paid').textContent = `Efectivo registrado: BOB ${summary.cash_paid}`;
            el('paid').textContent = `Total pagado confirmado: BOB ${summary.paid}`;
            el('balance').textContent = `Saldo restante: BOB ${summary.balance}`;
        }
        el('qr-pending').textContent = intent && ['creating', 'generation_unknown', 'reconciliation_required'].includes(intent.status)
            ? `QR en revisión: BOB ${intent.amount}`
            : `QR pendiente: BOB ${intent?.status === 'pending' ? intent.amount : '0.00'}`;
        if (!intent) return;
        el('mixed-form').hidden = true;
        const otherDispatch = (intent.dispatch_ulid || '') !== (box.dataset.dispatch || '');
        if (otherDispatch) {
            el('cash-paid').textContent = '';
            el('paid').textContent = '';
            el('balance').textContent = '';
            el('qr-pending').textContent = '';
        }
        const expired = intent.expires_at && Date.parse(intent.expires_at) <= Date.now();
        el('amount').textContent = otherDispatch ? '' : `${intent.currency} ${intent.amount}`;
        el('reference').textContent = otherDispatch ? '' : `Referencia: ${intent.commercial_reference}`;
        el('expiry').textContent = otherDispatch || !intent.expires_at ? '' : `Vigencia: ${new Date(intent.expires_at).toLocaleString('es-BO', { timeZone: 'America/La_Paz' })}`;
        el('identifier').textContent = otherDispatch || !intent.bank_qr_id ? '' : `QR ID: ${intent.bank_qr_id}`;
        el('image').hidden = otherDispatch || expired || intent.status !== 'pending' || !intent.image_url;
        if (!el('image').hidden) el('image').src = intent.image_url;
        else el('image').removeAttribute('src');
        el('status').textContent = otherDispatch ? 'Existe un QR activo para otra tanda. Resuélvelo antes de cobrar esta tanda.'
            : expired && intent.status === 'pending' ? 'Vigencia vencida. Consulta al banco; no generes otro QR hasta confirmar su estado.' : intent.message;
        el('error').textContent = !otherDispatch && intent.issue ? `Incidencia: ${intent.issue}. Contacta al responsable de caja.` : '';
        if (!otherDispatch && intent.status === 'generation_unknown') {
            el('error').textContent = 'La generación del QR anterior no pudo verificarse. No vuelvas a cobrar este importe hasta revisar su estado.';
        }
        if (!otherDispatch && intent.status === 'pending' && !expired && !intent.image_url) {
            el('error').textContent = 'La imagen QR no está disponible. Consulta al responsable antes de volver a cobrar.';
        }
        el('check').hidden = otherDispatch || ['paid', 'cancelled', 'failed'].includes(intent.status);
        el('cancel').hidden = otherDispatch || !['pending', 'failed', 'generation_unknown'].includes(intent.status);
        el('generate').hidden = otherDispatch || intent.status !== 'cancelled' || !enabled;
        if (intent.status === 'paid' && !otherDispatch) {
            clearTimeout(timer);
            setTimeout(() => window.location.assign(intent.redirect_url), 1200);
        }
    };

    const request = async (url, body = null, loading = '') => {
        if (busy) return false;
        busy = true;
        [el('open'), el('open-mixed'), el('mixed-submit'), el('check'), el('cancel'), el('generate')].forEach((button) => { button.disabled = true; });
        el('error').textContent = '';
        if (loading) el('status').textContent = loading;
        try {
            const response = await fetch(url, {
                method: body === null ? 'GET' : 'POST', credentials: 'same-origin',
                signal: AbortSignal.timeout(45000),
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                ...(body !== null ? { body: JSON.stringify(body) } : {}),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'No se pudo completar la consulta.');
            render(data);
            return true;
        } catch (error) {
            el('status').textContent = 'No se pudo conectar. Verifica el intento antes de volver a cobrar.';
            el('error').textContent = error.message || 'Conexión interrumpida.';
            return false;
        } finally {
            busy = false;
            [el('open'), el('open-mixed'), el('mixed-submit'), el('check'), el('cancel'), el('generate')].forEach((button) => { button.disabled = false; });
        }
    };

    const poll = () => {
        clearTimeout(timer);
        if (!modal.open || !intent || terminal.includes(intent.status) || (intent.dispatch_ulid || '') !== (box.dataset.dispatch || '')) return;
        if (polls >= 100) {
            el('status').textContent = 'Consulta automática pausada. Usa «Consultar estado».';
            return;
        }
        timer = setTimeout(async () => {
            if (!document.hidden && modal.open) {
                polls++;
                nextCheck = Date.now() + 6500;
                await request(intent.check_url, {}, 'Consultando banco…');
            }
            poll();
        }, 6500);
    };

    const generate = async (cash = null) => {
        createUncertain = true;
        const ok = await request(box.dataset.createUrl, {
            idempotency_key: box.dataset.key,
            kitchen_dispatch: box.dataset.dispatch || null,
            ...(cash ?? {}),
        }, 'Generando QR…');
        if (ok) createUncertain = false;
        polls = 0;
        poll();
    };

    const open = async (mixed) => {
        if (busy) return;
        modal.showModal();
        el('close').focus();
        el('amount').textContent = '';
        el('reference').textContent = '';
        el('identifier').textContent = '';
        el('expiry').textContent = '';
        el('image').hidden = true;
        el('check').hidden = true;
        el('cancel').hidden = true;
        el('generate').hidden = true;
        el('mixed-form').hidden = true;
        const ok = await request(box.dataset.currentUrl, null, 'Buscando cobro pendiente…');
        if (!ok || !modal.open) return;
        if (intent) {
            polls = 0;
            poll();
        } else if (!enabled) {
            el('status').textContent = 'Banco Económico no está habilitado para nuevos cobros.';
        } else if (createUncertain) {
            el('status').textContent = 'No se confirmó la generación. Consulta con el responsable antes de volver a cobrar.';
        } else if (summary && cents(summary.balance) === 0n) {
            el('status').textContent = 'No queda saldo pendiente. Actualiza la venta para ver el cobro.';
        } else if (mixed) {
            el('status').textContent = 'Confirma el efectivo recibido antes de generar el QR por el saldo.';
            el('cash').value = '';
            el('received').value = '';
            el('confirm-cash').checked = false;
            el('mixed-form').hidden = false;
            updateEstimate();
        } else {
            await generate();
        }
    };
    el('open').addEventListener('click', () => open(false));
    el('open-mixed').addEventListener('click', () => open(true));
    el('cash').addEventListener('input', updateEstimate);
    el('received').addEventListener('input', updateEstimate);
    el('mixed-submit').addEventListener('click', async () => {
        if (busy || !el('confirm-cash').checked) {
            el('error').textContent = 'Confirma que recibiste el efectivo.';
            return;
        }
        const amount = el('cash').value.trim();
        const received = el('received').value.trim();
        const cash = cents(amount), receivedValue = cents(received), balance = cents(summary?.balance ?? '');
        if (cash === null || receivedValue === null || balance === null || cash <= 0n || cash >= balance || receivedValue < cash) {
            el('error').textContent = 'Ingresa efectivo positivo menor al saldo y un monto recibido suficiente.';
            return;
        }
        el('mixed-form').hidden = true;
        await generate({ cash_amount: amount, received_amount: received, confirm_cash: true });
    });
    el('close').addEventListener('click', () => modal.close());
    el('image').addEventListener('error', () => {
        el('image').hidden = true;
        el('error').textContent = 'No se pudo cargar la imagen QR. Verifica la sesión o consulta al responsable.';
    });
    modal.addEventListener('close', () => { clearTimeout(timer); el('open').focus(); });
    el('generate').addEventListener('click', async () => {
        if (busy || !intent || intent.status !== 'cancelled') return;
        box.dataset.key = crypto.randomUUID();
        await generate();
    });
    el('check').addEventListener('click', async () => {
        if (!intent || busy || Date.now() < nextCheck) return;
        nextCheck = Date.now() + 6500;
        await request(intent.check_url, {}, 'Consultando banco…');
        poll();
    });
    el('cancel').addEventListener('click', async () => {
        if (!intent || busy) return;
        clearTimeout(timer);
        await request(intent.cancel_url, {}, 'Consultando banco…');
        poll();
    });
});
