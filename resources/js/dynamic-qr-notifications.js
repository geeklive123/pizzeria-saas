const container = document.querySelector('[data-qr-notifications]');

if (container) {
    let busy = false;
    const shown = new Set();
    const rendered = new Set();
    const waitForPaint = () => new Promise((resolve) => {
        let finished = false;
        const finish = (visible) => {
            if (finished) return;
            finished = true;
            document.removeEventListener('visibilitychange', onVisibility);
            resolve(visible);
        };
        const onVisibility = () => { if (document.hidden) finish(false); };
        document.addEventListener('visibilitychange', onVisibility);
        requestAnimationFrame(() => requestAnimationFrame(() => finish(!document.hidden)));
    });
    const storageKey = (id) => `qr-notice:${container.dataset.scope}:${id}`;
    const wasShown = (id) => {
        if (shown.has(id)) return true;
        try { return localStorage.getItem(storageKey(id)) === 'shown'; } catch { return false; }
    };
    const remember = (id) => {
        shown.add(id);
        try { localStorage.setItem(storageKey(id), 'shown'); } catch { /* The current tab still deduplicates. */ }
    };
    const forget = (id) => {
        try { localStorage.removeItem(storageKey(id)); } catch { /* No persistent marker to remove. */ }
    };
    const show = (notice) => {
        const card = document.createElement('div');
        card.className = 'rounded-xl border border-emerald-300 bg-white p-4 text-sm shadow-xl';
        const title = document.createElement('p');
        title.className = 'font-bold text-emerald-800';
        title.textContent = `${notice.status} · Pedido ${notice.order_number}`;
        const amount = document.createElement('p');
        amount.className = 'mt-1 text-stone-700';
        amount.textContent = `Importe confirmado: ${notice.currency} ${notice.amount}`;
        const link = document.createElement('a');
        link.className = 'link mt-2 inline-block';
        link.href = notice.order_url;
        link.textContent = 'Abrir pedido';
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-secondary ml-3';
        close.textContent = 'Cerrar aviso';
        close.addEventListener('click', () => card.remove());
        card.append(title, amount, link, close);
        container.append(card);
        return card;
    };
    const collect = async () => {
        try {
            const response = await fetch(container.dataset.url, {
                method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
            });
            if (response.ok) {
                const data = await response.json();
                for (const notice of data.notifications) {
                    if (document.hidden) return;
                    if (!wasShown(notice.id)) {
                        let card;
                        if (!rendered.has(notice.id)) {
                            card = show(notice);
                            rendered.add(notice.id);
                        }
                        if (!await waitForPaint()) {
                            card?.remove();
                            rendered.delete(notice.id);
                            return;
                        }
                        remember(notice.id);
                    }
                    const url = container.dataset.ackUrl.replace('00000000000000000000000000', encodeURIComponent(notice.id));
                    const ack = await fetch(url, {
                        method: 'POST', credentials: 'same-origin', cache: 'no-store',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
                    });
                    if (ack.ok) forget(notice.id);
                }
            }
        } catch {
            // Pending notifications and failed acknowledgements are retried on the next poll.
        }
    };
    const poll = async () => {
        if (busy || document.hidden) return;
        busy = true;
        try {
            if (navigator.locks) {
                await navigator.locks.request(`qr-notices:${container.dataset.scope}`, { ifAvailable: true }, async (lock) => {
                    if (lock) await collect();
                });
            } else {
                await collect();
            }
        } finally { busy = false; }
    };
    poll();
    setInterval(poll, 10000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
}
