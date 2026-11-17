import { initDelivery } from './delivery';
import { initCashAvailability } from './cash-availability';

const CART_KEY = 'masa-y-mana-storefront-cart-v1';
const PREPARATION_KEY = 'masa-y-mana-delivery-preparation-v1';
const MAX_QUANTITY = 20;

const escapeHtml = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

const moneyToCents = (value) => {
    const match = String(value).trim().match(/^(\d+)(?:\.(\d{1,2}))?$/);
    if (!match) return 0n;
    const fraction = (match[2] ?? '').padEnd(2, '0');
    return (BigInt(match[1]) * 100n) + BigInt(fraction);
};

const displayCents = (cents) => {
    const whole = cents / 100n;
    const fraction = String(cents % 100n).padStart(2, '0');
    return fraction === '00' ? String(whole) : `${whole},${fraction.replace(/0$/, '')}`;
};

const readCart = () => {
    try {
        const parsed = JSON.parse(localStorage.getItem(CART_KEY) ?? '[]');
        if (!Array.isArray(parsed)) return [];
        return parsed.filter((item) => item
            && typeof item.variantUlid === 'string'
            && typeof item.productName === 'string'
            && typeof item.variantName === 'string'
            && /^\d+(?:\.\d{1,2})?$/.test(String(item.price))
            && Number.isInteger(item.quantity)
            && item.quantity >= 1
            && item.quantity <= MAX_QUANTITY);
    } catch {
        return [];
    }
};

let cart = readCart();

const saveCart = () => {
    localStorage.setItem(CART_KEY, JSON.stringify(cart));
    try {
        const pending = JSON.parse(localStorage.getItem(PREPARATION_KEY) ?? 'null');
        if (pending?.key) {
            pending.dirty = true;
            localStorage.setItem(PREPARATION_KEY, JSON.stringify(pending));
            document.querySelector('[data-checkout-form]')?.dispatchEvent(new Event('storefront:preparation-dirty'));
        }
    } catch { /* Keep the cart usable if local storage contains invalid data. */ }
    renderCart();
};

const cartQuantity = () => cart.reduce((total, item) => total + item.quantity, 0);
const cartTotal = () => cart.reduce((total, item) => total + (moneyToCents(item.price) * BigInt(item.quantity)), 0n);

const toast = (message) => {
    const element = document.querySelector('[data-toast]');
    if (!element) return;
    element.textContent = message;
    element.classList.add('show');
    window.clearTimeout(toast.timer);
    toast.timer = window.setTimeout(() => element.classList.remove('show'), 2400);
};

const changeQuantity = (variantUlid, delta) => {
    const item = cart.find((line) => line.variantUlid === variantUlid);
    if (!item) return;
    const next = item.quantity + delta;
    if (next < 1) {
        cart = cart.filter((line) => line.variantUlid !== variantUlid);
    } else {
        item.quantity = Math.min(next, MAX_QUANTITY);
    }
    saveCart();
};

const removeItem = (variantUlid) => {
    cart = cart.filter((line) => line.variantUlid !== variantUlid);
    saveCart();
};

const cartLineMarkup = (item) => `
    <article class="cart-line">
        <div>
            <h3>${escapeHtml(item.productName)}</h3>
            <p>${escapeHtml(item.variantName)} · ${escapeHtml(item.price)} Bs.</p>
            <div class="quantity-control" aria-label="Cantidad de ${escapeHtml(item.productName)}">
                <button type="button" data-cart-decrease="${escapeHtml(item.variantUlid)}" aria-label="Disminuir cantidad">−</button>
                <span>${item.quantity}</span>
                <button type="button" data-cart-increase="${escapeHtml(item.variantUlid)}" aria-label="Aumentar cantidad">+</button>
            </div>
        </div>
        <div class="cart-line-price">
            <strong>${displayCents(moneyToCents(item.price) * BigInt(item.quantity))} Bs.</strong>
            <button class="remove-line" type="button" data-cart-remove="${escapeHtml(item.variantUlid)}">Quitar</button>
        </div>
    </article>`;

const checkoutLineMarkup = (item) => `
    <article class="summary-line">
        <div><strong>${item.quantity}× ${escapeHtml(item.productName)}</strong><small>${escapeHtml(item.variantName)} · ${escapeHtml(item.price)} Bs. c/u</small></div>
        <span>${displayCents(moneyToCents(item.price) * BigInt(item.quantity))} Bs.</span>
    </article>`;

const renderCart = () => {
    const quantity = cartQuantity();
    const total = displayCents(cartTotal());
    document.querySelectorAll('.js-cart-count').forEach((element) => { element.textContent = String(quantity); });
    document.querySelectorAll('.js-cart-total').forEach((element) => { element.textContent = total; });

    const lines = document.querySelector('.js-cart-lines');
    const empty = document.querySelector('.js-cart-empty');
    if (lines) lines.innerHTML = cart.map(cartLineMarkup).join('');
    if (empty) empty.hidden = cart.length > 0;

    const checkoutLines = document.querySelector('.js-checkout-lines');
    const checkoutEmpty = document.querySelector('.js-checkout-empty');
    if (checkoutLines) checkoutLines.innerHTML = cart.map(checkoutLineMarkup).join('');
    if (checkoutEmpty) checkoutEmpty.hidden = cart.length > 0;

    document.querySelectorAll('.js-checkout-link').forEach((link) => {
        link.toggleAttribute('aria-disabled', cart.length === 0);
        link.style.pointerEvents = cart.length === 0 ? 'none' : '';
        link.style.opacity = cart.length === 0 ? '.45' : '';
    });

    document.querySelectorAll('[data-submit-order]').forEach((button) => { button.disabled = cart.length === 0 || button.dataset.cashUnavailable === 'true' || button.dataset.cashBusy === 'true' || button.dataset.demo === 'true'; });
    const floating = document.querySelector('[data-floating-cart]');
    if (floating) floating.hidden = cart.length === 0;
};

const openCart = () => {
    const drawer = document.querySelector('[data-cart-drawer]');
    const backdrop = document.querySelector('.cart-backdrop');
    if (!drawer || !backdrop) return;
    backdrop.hidden = false;
    drawer.classList.add('open');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('cart-open');
    drawer.querySelector('.icon-button')?.focus();
};

const closeCart = () => {
    const drawer = document.querySelector('[data-cart-drawer]');
    const backdrop = document.querySelector('.cart-backdrop');
    if (!drawer || !backdrop) return;
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('cart-open');
    window.setTimeout(() => { backdrop.hidden = true; }, 280);
};

const addFromCard = (button) => {
    const card = button.closest('[data-product-card]');
    if (!card) return;
    const select = card.querySelector('[data-variant-select]');
    const selected = select
        ? select.options[select.selectedIndex]
        : card.querySelector('input[type="radio"]:checked');
    if (!selected) return;

    const variantUlid = selected.value;
    const existing = cart.find((line) => line.variantUlid === variantUlid);
    if (existing) {
        existing.quantity = Math.min(existing.quantity + 1, MAX_QUANTITY);
    } else {
        cart.push({
            variantUlid,
            productName: card.dataset.productName?.trim() || 'Producto',
            variantName: selected.dataset.name || 'Presentación',
            price: selected.dataset.price || '0.00',
            quantity: 1,
        });
    }
    saveCart();
    toast('Producto agregado a tu comanda.');
};

const normalizeSearch = (value) => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

const initMenuFilters = () => {
    const search = document.querySelector('[data-menu-search]');
    const tabs = [...document.querySelectorAll('[data-category]')];
    const cards = [...document.querySelectorAll('[data-product-category]')];
    const sections = [...document.querySelectorAll('[data-category-section]')];
    const empty = document.querySelector('[data-search-empty]');
    if (!cards.length) return;

    let category = 'all';
    const filter = () => {
        const term = normalizeSearch(search?.value ?? '');
        let visibleCount = 0;
        cards.forEach((card) => {
            const matchesCategory = category === 'all' || card.dataset.productCategory === category;
            const matchesSearch = !term || normalizeSearch(card.dataset.productSearch).includes(term);
            card.hidden = !(matchesCategory && matchesSearch);
            if (!card.hidden) visibleCount += 1;
        });
        sections.forEach((section) => {
            section.hidden = !section.querySelector('[data-product-category]:not([hidden])');
        });
        if (empty) empty.hidden = visibleCount !== 0;
    };

    search?.addEventListener('input', filter);
    tabs.forEach((tab) => tab.addEventListener('click', () => {
        category = tab.dataset.category || 'all';
        tabs.forEach((candidate) => candidate.classList.toggle('active', candidate === tab));
        filter();
    }));
};

const initCheckout = () => {
    const form = document.querySelector('[data-checkout-form]');
    if (!form) return;
    const submit = document.querySelector('[data-submit-order]');
    const errors = document.querySelector('[data-form-errors]');
    const confirmation = document.querySelector('[data-demo-confirmation]');
    const delivery = typeof initDelivery === 'function' ? initDelivery(form) : null;
    const cashAvailability = typeof initCashAvailability === 'function' ? initCashAvailability(form, submit, document.querySelector('[data-cash-warning]'), () => cart.length > 0, errors) : null;
    let recoveryCount = 0;
    const beginRecovery = () => { recoveryCount++; submit.dataset.cashBusy = 'true'; submit.disabled = true; };
    const endRecovery = () => {
        recoveryCount--;
        if (recoveryCount === 0) {
            submit.dataset.cashBusy = 'false';
            submit.disabled = false;
            cashAvailability?.render();
        }
    };
    const pendingKey = 'masa-y-mana-online-request-v1';
    const fingerprint = () => JSON.stringify(cart.map((item) => [item.variantUlid, item.quantity]).sort((a, b) => a[0].localeCompare(b[0])));
    const pending = () => {
        try { return JSON.parse(localStorage.getItem(pendingKey) ?? 'null'); } catch { return null; }
    };
    const showError = (message) => { if (errors) { errors.textContent = message; errors.hidden = false; } };
    const finish = (result, attempt) => {
        if (!/^WEB-\d{6,}$/.test(result.code ?? '') || !/^\d+\.\d{2}$/.test(result.subtotal ?? '')) throw new Error('No se pudo verificar la respuesta.');
        if (pending()?.key === attempt.key) {
            if (fingerprint() === attempt.fingerprint) { cart = []; saveCart(); }
            localStorage.removeItem(pendingKey);
        }
        if (confirmation) {
            confirmation.textContent = `Solicitud ${result.code} registrada. Subtotal: ${result.subtotal} Bs. ${result.fulfillment === 'delivery' && result.delivery_coverage === 'free' ? 'Delivery gratuito · Bs 0,00.' : 'El restaurante revisará tu pedido.'}`;
            confirmation.hidden = false;
        }
        submit.disabled = false;
        submit.textContent = 'Realizar pedido';
    };
    const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' };
    const preparationKey = PREPARATION_KEY;
    const readPreparation = () => { try { return JSON.parse(localStorage.getItem(preparationKey) ?? 'null'); } catch { return null; } };
    const preparationRequest = async (url, body) => {
        const response = await fetch(url, { method: 'POST', headers, body: JSON.stringify(body) });
        const result = await response.json();
        if (!response.ok) throw new Error(Object.values(result.errors ?? {}).flat()[0] ?? result.message ?? 'No fue posible preparar el pedido.');
        return result;
    };
    const qrCheck = document.querySelector('[data-online-qr-check]');
    qrCheck?.addEventListener('click', async () => {
        const attempt = readPreparation();
        if (!attempt?.key || !qrCheck.dataset.statusUrl) return;
        const status = document.querySelector('[data-online-qr-status]');
        qrCheck.disabled = true;
        try {
            const result = await preparationRequest(qrCheck.dataset.statusUrl, { idempotency_key: attempt.key });
            if (status) status.textContent = result.status === 'paid' ? 'Pago confirmado' : result.status === 'pending' ? 'Esperando confirmación bancaria.' : 'El pago requiere revisión. Contacta al restaurante.';
        } catch {
            if (status) status.textContent = 'Error temporal recuperable. Vuelve a comprobar el pago.';
        } finally {
            qrCheck.disabled = false;
        }
    });
    const showPreparation = (result) => {
        if (!confirmation) return;
        confirmation.textContent = result.ready ? `Preparación registrada. Total: ${result.subtotal} Bs. No se ha realizado ningún pago ni se ha confirmado el pedido.` : result.message;
        confirmation.hidden = false;
    };
    const recoverPreparation = async (attempt) => {
        const result = await preparationRequest(form.dataset.preparationResolveUrl, { idempotency_key: attempt.key });
        if (!result.created) { if (readPreparation()?.key === attempt.key) localStorage.removeItem(preparationKey); return null; }
        if (!attempt.dirty && readPreparation()?.key === attempt.key && !readPreparation()?.dirty) showPreparation(result);
        return result;
    };
    let invalidation = Promise.resolve();
    const invalidatePreparation = () => {
        const attempt = readPreparation();
        if (!attempt?.key) return;
        attempt.dirty = true;
        localStorage.setItem(preparationKey, JSON.stringify(attempt));
        if (confirmation) confirmation.hidden = true;
        invalidation = invalidation.then(async () => {
            if (readPreparation()?.key !== attempt.key) return;
            const existing = await recoverPreparation(attempt);
            if (existing) await preparationRequest(form.dataset.preparationCancelUrl, { idempotency_key: attempt.key });
            if (readPreparation()?.key === attempt.key) localStorage.removeItem(preparationKey);
        }).catch(() => showError('No se pudo cancelar la preparación anterior. Vuelve a comprobar tu conexión.'));
    };
    if (form.dataset.preparationEnabled === 'true') {
        form.addEventListener('input', invalidatePreparation);
        form.addEventListener('change', invalidatePreparation);
        form.addEventListener('storefront:preparation-dirty', invalidatePreparation);
        form.addEventListener('storefront:location-changed', invalidatePreparation);
    }
    if (form.dataset.preparationEnabled === 'true' && form.dataset.demo !== 'true' && readPreparation()) {
        beginRecovery();
        const previousPreparation = readPreparation();
        (previousPreparation.dirty ? (invalidatePreparation(), invalidation) : recoverPreparation(previousPreparation))
            .catch(() => showError('No se pudo comprobar la preparación anterior. Recarga para volver a intentarlo.'))
            .finally(endRecovery);
    }
    const prepareDelivery = async (fulfillment) => {
        await invalidation;
        const fields = new FormData(form);
        const contents = { customer_name: fields.get('customer_name'), customer_phone: fields.get('customer_phone'), ...fulfillment,
            notes: fields.get('notes') || null, items: cart.map((item) => ({ variant_ulid: item.variantUlid, quantity: item.quantity })).sort((a, b) => a.variant_ulid.localeCompare(b.variant_ulid)) };
        const bytes = new TextEncoder().encode(JSON.stringify(contents));
        const digest = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)), (b) => b.toString(16).padStart(2, '0')).join('');
        let attempt = readPreparation();
        if (attempt) {
            const existing = await recoverPreparation(attempt);
            if (existing && existing.ready && !attempt.dirty && attempt.fingerprint === digest) return attempt;
            if (existing) await preparationRequest(form.dataset.preparationCancelUrl, { idempotency_key: attempt.key });
            if (readPreparation()?.key === attempt.key) localStorage.removeItem(preparationKey);
        }
        attempt = { key: crypto.randomUUID(), fingerprint: digest };
        localStorage.setItem(preparationKey, JSON.stringify(attempt));
        try {
            const result = await preparationRequest(form.dataset.preparationUrl, { ...contents, idempotency_key: attempt.key });
            const current = readPreparation();
            if (current?.key === attempt.key && !current.dirty) showPreparation(result);
        } catch (error) {
            const existing = await recoverPreparation(attempt);
            if (!existing) throw error;
            if (!existing.ready) throw error;
        }
        return attempt;
    };
    const resolveAttempt = async (attempt) => {
        const response = await fetch(form.dataset.resolveUrl, { method: 'POST', headers, body: JSON.stringify({ idempotency_key: attempt.key }) });
        if (!response.ok) throw new Error('No fue posible resolver el pedido anterior.');
        const result = await response.json();
        if (result.created === true) {
            finish(result, attempt);
            return true;
        }
        if (result.created !== false) throw new Error('No se pudo verificar el pedido anterior.');
        if (pending()?.key === attempt.key) localStorage.removeItem(pendingKey);
        return false;
    };
    const previous = pending();
    if (previous && form.dataset.demo !== 'true') {
        beginRecovery();
        resolveAttempt(previous).then((found) => {
            if (!found) showError('El intento anterior no se registró. Puedes corregir tu pedido y enviarlo.');
        }).catch(() => showError('No se pudo resolver el pedido anterior. Recarga para volver a comprobarlo.'))
            .finally(endRecovery);
    }
    submit?.addEventListener('click', () => form.requestSubmit());

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;
        if (!form.reportValidity()) return;
        if (!cart.length) {
            toast('Agrega al menos un producto.');
            return;
        }
        const fulfillment = delivery ? delivery.payload() : { fulfillment: 'pickup' };
        if (!fulfillment) return;

        submit.disabled = true;
        submit.dataset.cashBusy = 'true';
        submit.textContent = 'Validando pedido…';
        if (errors) errors.hidden = true;
        if (confirmation) confirmation.hidden = true;
        try {
            if (form.dataset.preparationEnabled === 'true' && fulfillment.fulfillment === 'delivery') {
                const attempt = await prepareDelivery(fulfillment);
                if (form.dataset.onlineQrEnabled === 'true' && readPreparation()?.key === attempt.key && !readPreparation()?.dirty) {
                    await preparationRequest(form.dataset.onlineOrderUrl, { idempotency_key: attempt.key });
                    if (form.dataset.onlineQrUrl) {
                        const panel = document.querySelector('[data-online-qr]');
                        const status = document.querySelector('[data-online-qr-status]');
                        const qrImage = document.querySelector('[data-online-qr-image]');
                        if (panel) panel.hidden = false;
                        if (confirmation) confirmation.hidden = true;
                        if (qrImage) qrImage.hidden = true;
                        if (status) status.textContent = 'Generando tu QR';
                        const qr = await preparationRequest(form.dataset.onlineQrUrl, { idempotency_key: attempt.key });
                        if (qr.status === 'pending' && qr.qr_image && qrImage) {
                            qrImage.src = qr.qr_image;
                            qrImage.hidden = false;
                            if (qrCheck) qrCheck.hidden = false;
                            if (status) status.textContent = `QR listo para pagar · Bs ${qr.amount}. Esperando confirmación bancaria.`;
                        } else if (status) {
                            status.textContent = 'Error temporal recuperable. Vuelve a intentarlo o consulta al restaurante.';
                        }
                    }
                }
                submit.disabled = false;
                submit.textContent = 'Preparar pedido';
                return;
            }
            const existing = pending();
            if (existing && await resolveAttempt(existing)) return;
            const attempt = { key: crypto.randomUUID(), fingerprint: fingerprint() };
            localStorage.setItem(pendingKey, JSON.stringify(attempt));
            const fields = new FormData(form);
            const payload = {
                customer_name: fields.get('customer_name'),
                customer_phone: fields.get('customer_phone'),
                ...fulfillment,
                notes: fields.get('notes') || null,
                idempotency_key: attempt.key,
                items: cart.map((item) => ({ variant_ulid: item.variantUlid, quantity: item.quantity })),
            };
            const response = await fetch(form.action, {
                method: 'POST',
                headers,
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok) {
                const messages = Object.values(result.errors ?? {}).flat();
                if (response.status === 422 && await resolveAttempt(attempt)) return;
                throw new Error(messages[0] ?? result.message ?? 'No pudimos validar el pedido.');
            }
            finish(result, attempt);
        } catch (error) {
            if (confirmation) confirmation.hidden = true;
            const qrPanel = document.querySelector('[data-online-qr]');
            const qrStatus = document.querySelector('[data-online-qr-status]');
            if (qrPanel && !qrPanel.hidden && qrStatus) qrStatus.textContent = 'Error temporal recuperable. Vuelve a intentarlo o consulta al restaurante.';
            if (errors) {
                errors.textContent = error instanceof Error ? error.message : 'No pudimos validar el pedido.';
                errors.hidden = false;
            }
            submit.disabled = false;
            submit.textContent = form.dataset.preparationEnabled === 'true' && fulfillment.fulfillment === 'delivery' ? 'Preparar pedido' : 'Realizar pedido';
        } finally {
            submit.dataset.cashBusy = 'false';
            cashAvailability?.render();
            if (fulfillment.fulfillment === 'delivery') cashAvailability?.check();
        }
    });
};

document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (target.closest('[data-cart-open]')) openCart();
    if (target.closest('[data-cart-close]')) closeCart();
    const add = target.closest('[data-add-product]');
    if (add) addFromCard(add);
    const increase = target.closest('[data-cart-increase]');
    if (increase) changeQuantity(increase.dataset.cartIncrease, 1);
    const decrease = target.closest('[data-cart-decrease]');
    if (decrease) changeQuantity(decrease.dataset.cartDecrease, -1);
    const remove = target.closest('[data-cart-remove]');
    if (remove) removeItem(remove.dataset.cartRemove);
});

document.addEventListener('change', (event) => {
    const target = event.target;
    if (target instanceof HTMLSelectElement && target.matches('[data-variant-select]')) {
        const selected = target.options[target.selectedIndex];
        const availability = target.closest('[data-product-card]')?.querySelector('[data-selected-availability]');
        if (availability) {
            availability.textContent = selected.dataset.statusLabel || 'Disponibilidad por confirmar';
            availability.className = `availability-note status-${selected.dataset.status || 'untracked'}`;
        }
        return;
    }
    if (target instanceof HTMLInputElement && target.type === 'radio' && target.closest('.variant-options')) {
        const card = target.closest('[data-product-card]');
        const price = card?.querySelector('[data-selected-price]');
        if (price) price.textContent = target.dataset.price || '0.00';
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeCart();
});

document.querySelector('[data-nav-toggle]')?.addEventListener('click', (event) => {
    const button = event.currentTarget;
    const nav = document.querySelector('[data-site-nav]');
    if (!(button instanceof HTMLElement) || !nav) return;
    const open = nav.classList.toggle('open');
    button.setAttribute('aria-expanded', String(open));
});

renderCart();
initMenuFilters();
initCheckout();
