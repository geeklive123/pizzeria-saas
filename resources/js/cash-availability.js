const closedMessage = 'Estamos fuera de horario. Por el momento no podemos recibir pedidos online. Vuelve a intentarlo cuando el restaurante esté atendiendo.';

export function initCashAvailability(form, submit, warning, cartHasItems, errors) {
    if (form.dataset.preparationEnabled !== 'true' || !form.dataset.cashAvailabilityUrl || !submit || !warning) return null;
    let open = null;
    let requestNumber = 0;
    const isDelivery = () => form.querySelector('input[name="fulfillment"]:checked')?.value === 'delivery';
    const render = () => {
        const delivery = isDelivery();
        const unavailable = delivery && open !== true;
        submit.dataset.cashUnavailable = String(unavailable);
        warning.hidden = !unavailable;
        if (unavailable) warning.textContent = open === false ? closedMessage : 'No se pudo verificar la disponibilidad. Vuelve a intentarlo en unos momentos.';
        if ((!delivery || open === true) && errors?.textContent === closedMessage) errors.hidden = true;
        if (submit.dataset.cashBusy === 'true') return;
        submit.disabled = unavailable || form.dataset.demo === 'true' || !cartHasItems();
        submit.textContent = unavailable ? 'Pedidos no disponibles' : delivery ? 'Preparar pedido' : 'Realizar pedido';
    };
    const check = async () => {
        const current = ++requestNumber;
        try {
            const response = await fetch(form.dataset.cashAvailabilityUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error('unavailable');
            const data = await response.json();
            if (typeof data.open !== 'boolean') throw new Error('invalid');
            if (current === requestNumber) open = data.open;
        } catch {
            if (current === requestNumber) open = null;
        }
        if (current === requestNumber) render();
    };
    form.addEventListener('change', (event) => {
        if (event.target?.name !== 'fulfillment') return;
        render();
        if (isDelivery()) check();
    });
    window.addEventListener('focus', check);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
    window.setInterval(() => { if (!document.hidden) check(); }, 15000);
    render();
    check();
    return { check, render };
}
