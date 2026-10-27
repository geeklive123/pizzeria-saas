const CART_KEY = 'masa-y-mana-storefront-cart-v1';
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

    document.querySelectorAll('[data-submit-order]').forEach((button) => { button.disabled = cart.length === 0; });
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
    const demoConfirmation = document.querySelector('[data-demo-confirmation]');
    const deliveryFields = document.querySelector('[data-delivery-fields]');
    const address = form.elements.namedItem('address');

    const syncFulfillment = () => {
        const fulfillment = form.querySelector('input[name="fulfillment"]:checked')?.value;
        const isDelivery = fulfillment === 'delivery';
        if (deliveryFields) deliveryFields.hidden = !isDelivery;
        if (address) address.required = isDelivery;
    };

    form.querySelectorAll('input[name="fulfillment"]').forEach((input) => input.addEventListener('change', syncFulfillment));
    syncFulfillment();
    submit?.addEventListener('click', () => form.requestSubmit());

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        if (!cart.length) {
            toast('Agrega al menos un producto.');
            return;
        }

        submit.disabled = true;
        submit.textContent = 'Validando pedido…';
        if (errors) errors.hidden = true;
        if (demoConfirmation) demoConfirmation.hidden = true;
        const fields = new FormData(form);
        const payload = {
            customer_name: fields.get('customer_name'),
            customer_phone: fields.get('customer_phone'),
            customer_email: fields.get('customer_email') || null,
            fulfillment: fields.get('fulfillment'),
            address: fields.get('address') || null,
            reference: fields.get('reference') || null,
            notes: fields.get('notes') || null,
            items: cart.map((item) => ({ variant_ulid: item.variantUlid, quantity: item.quantity })),
        };

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok) {
                const messages = Object.values(result.errors ?? {}).flat();
                throw new Error(messages[0] ?? result.message ?? 'No pudimos validar el pedido.');
            }
            if (result.demo === true) {
                if (demoConfirmation) {
                    demoConfirmation.textContent = `Simulación validada: ${result.item_count} producto(s), subtotal ${displayCents(moneyToCents(result.subtotal))} Bs. No se envió ningún pedido.`;
                    demoConfirmation.hidden = false;
                }
                submit.disabled = false;
                submit.innerHTML = '<span>◉</span> Simular nuevamente';
                return;
            }
            if (typeof result.url !== 'string' || !result.url.startsWith('https://wa.me/')) {
                throw new Error('El canal de pedido no está disponible.');
            }
            window.location.assign(result.url);
        } catch (error) {
            if (errors) {
                errors.textContent = error instanceof Error ? error.message : 'No pudimos validar el pedido.';
                errors.hidden = false;
            }
            submit.disabled = false;
            submit.innerHTML = form.dataset.demo === 'true'
                ? '<span>◉</span> Simular finalización'
                : '<span>◉</span> Enviar pedido por WhatsApp';
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
