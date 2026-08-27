const sidebar = document.querySelector('#sidebar');
const backdrop = document.querySelector('#sidebar-backdrop');
const toggleSidebar = () => {
    sidebar?.classList.toggle('-translate-x-full');
    backdrop?.classList.toggle('hidden');
};

document.querySelector('#sidebar-toggle')?.addEventListener('click', toggleSidebar);
backdrop?.addEventListener('click', toggleSidebar);

const filterPosProducts = () => {
    const term = document.querySelector('[data-pos-search]')?.value.toLocaleLowerCase() ?? '';
    const category = document.querySelector('[data-pos-category].is-active')?.dataset.posCategory ?? 'all';
    document.querySelectorAll('[data-pos-product]').forEach((product) => {
        const matchesTerm = product.dataset.name.includes(term);
        const matchesCategory = category === 'all' || product.dataset.category === category;
        product.classList.toggle('hidden', !matchesTerm || !matchesCategory);
    });
};
const closePizzaComposers = () => {
    document.querySelectorAll('[data-pizza-composer]').forEach((composer) => { composer.hidden = true; });
};

document.querySelector('[data-pos-search]')?.addEventListener('input', () => {
    closePizzaComposers();
    filterPosProducts();
});
document.querySelectorAll('[data-pos-category]').forEach((button, index) => {
    if (index === 0) button.classList.add('is-active');
    button.addEventListener('click', () => {
        closePizzaComposers();
        document.querySelectorAll('[data-pos-category]').forEach((candidate) => candidate.classList.remove('is-active'));
        button.classList.add('is-active');
        filterPosProducts();
    });
});

document.querySelectorAll('[data-pizza-composer]').forEach((composer) => {
    const size = composer.querySelector('[data-pizza-size]');
    const sizeOptions = [...composer.querySelectorAll('[data-pizza-size-option]')];
    const rows = [...composer.querySelectorAll('[data-pizza-section]')];
    const form = composer.querySelector('[data-pizza-form]');
    const error = composer.querySelector('[data-pizza-error]');
    const price = composer.querySelector('[data-pizza-price]');
    const basePrice = composer.querySelector('[data-pizza-base-price]');
    const extrasPrice = composer.querySelector('[data-pizza-extras-price]');
    const toppings = [...composer.querySelectorAll('[data-pizza-topping]')];
    const compatibility = composer.querySelector('[data-pizza-compatibility]');
    const combineToggle = composer.querySelector('[data-pizza-combine-toggle]');
    const combination = composer.querySelector('[data-pizza-combination]');
    const optionTemplates = new Map(
        [...composer.querySelectorAll('[data-pizza-options]')].map((template) => [template.dataset.pizzaOptions, template]),
    );
    let sectionCount = 1;

    const resetCombination = () => {
        sectionCount = 1;
        if (combination) combination.hidden = true;
        rows.slice(1).forEach((row) => {
            const variant = row.querySelector('[data-pizza-variant]');
            variant.value = '';
        });
    };

    const loadOptions = (select, sizeKey) => {
        if (select.dataset.loadedSizeKey === sizeKey) return;
        const template = optionTemplates.get(sizeKey);
        select.replaceChildren(...[...template.content.querySelectorAll('option')].map((option) => option.cloneNode(true)));
        select.dataset.loadedSizeKey = sizeKey;
    };

    const sectionLabel = (index) => {
        if (sectionCount === 1) return 'Sabor';
        if (sectionCount === 2) return `Mitad ${index + 1}`;
        if (sectionCount === 3) return `Parte ${index + 1} de 3`;
        return `Cuarto ${index + 1}`;
    };

    const priceInCents = (value) => {
        const [whole = '0', decimals = ''] = String(value).split('.');
        return BigInt(whole) * 100n + BigInt(decimals.padEnd(2, '0').slice(0, 2));
    };

    const formatCents = (value) => `Bs ${(value / 100n).toString()},${(value % 100n).toString().padStart(2, '0')}`;

    const toppingPrice = (topping) => {
        const sizePrices = JSON.parse(topping.dataset.sizePrices || '{}');
        return priceInCents(sizePrices[size.value] ?? topping.dataset.priceDefault ?? '0');
    };

    const refresh = () => {
        sizeOptions.forEach((option) => { option.checked = option.value === size.value; });
        const canCombine = ['mediana', 'familiar'].includes(size.value);
        if (combineToggle) combineToggle.hidden = !canCombine;
        if (!canCombine && sectionCount > 1) resetCombination();
        const availableForSize = optionTemplates.get(size.value)?.content.querySelectorAll('option[data-size-key]').length ?? 0;
        composer.querySelectorAll('[data-pizza-section-count]').forEach((button) => {
            button.disabled = Number(button.dataset.pizzaSectionCount) > availableForSize;
        });
        if (compatibility) {
            compatibility.hidden = availableForSize !== 1;
            compatibility.textContent = availableForSize === 1
                ? 'Solo hay 1 sabor disponible para este tamaño. Configura el mismo tamaño en otros sabores para poder combinarlos.'
                : '';
        }
        if (sectionCount > availableForSize) sectionCount = Math.max(1, availableForSize);

        const selected = new Set();

        rows.forEach((row, index) => {
            const active = index < sectionCount;
            row.hidden = !active;
            const variant = row.querySelector('[data-pizza-variant]');
            loadOptions(variant, size.value);
            row.querySelectorAll('input, select').forEach((field) => { field.disabled = !active; });
            if (active) {
                const current = variant.value;
                [...variant.options].forEach((option) => {
                    option.disabled = option.value !== '' && selected.has(option.value) && option.value !== current;
                });
                if (variant.selectedOptions[0]?.disabled || selected.has(variant.value)) variant.value = '';
                if (variant.value) selected.add(variant.value);
                row.querySelector('[data-pizza-section-label]').textContent = sectionLabel(index);
                const selectedOption = variant.selectedOptions[0];
                row.querySelector('[data-pizza-availability]').textContent = selectedOption?.dataset.availabilityMode === 'recipe_pending'
                    ? 'Receta pendiente: no se reservarán ingredientes de este sabor.'
                    : selectedOption
                        ? `Disponibilidad estimada del sabor: ${selectedOption.dataset.availability}`
                        : 'No hay otro sabor disponible para este tamaño.';
            }
        });

        rows.slice(0, sectionCount).forEach((row) => {
            const variant = row.querySelector('[data-pizza-variant]');
            const current = variant.value;
            [...variant.options].forEach((option) => {
                option.disabled = option.value !== '' && selected.has(option.value) && option.value !== current;
            });
        });

        const prices = rows.slice(0, sectionCount)
            .map((row) => row.querySelector('[data-pizza-variant]').selectedOptions[0]?.dataset.price)
            .filter(Boolean)
            .map(priceInCents);
        const pizzaCents = prices.length > 0
            ? prices.reduce((highest, candidate) => candidate > highest ? candidate : highest, 0n)
            : null;
        let toppingCents = 0n;
        toppings.forEach((topping) => {
            const cents = toppingPrice(topping);
            topping.closest('label')?.querySelector('[data-topping-price-label]')
                ?.replaceChildren(document.createTextNode(`+ ${formatCents(cents)}`));
            if (topping.checked) toppingCents += cents;
        });
        basePrice.textContent = pizzaCents === null ? '—' : formatCents(pizzaCents);
        extrasPrice.textContent = formatCents(toppingCents);
        price.textContent = pizzaCents === null ? '—' : formatCents(pizzaCents + toppingCents);
        error.hidden = true;
        error.textContent = '';

        composer.querySelectorAll('[data-pizza-section-count]').forEach((button) => {
            button.classList.toggle('is-active', button.dataset.pizzaSectionCount === String(sectionCount));
        });
    };

    composer.querySelectorAll('[data-pizza-section-count]').forEach((button) => {
        button.addEventListener('click', () => {
            sectionCount = parseInt(button.dataset.pizzaSectionCount, 10);
            refresh();
        });
    });
    combineToggle?.addEventListener('click', () => {
        if (!['mediana', 'familiar'].includes(size.value)) return;
        combination.hidden = false;
        sectionCount = 2;
        refresh();
    });
    composer.querySelector('[data-close-pizza-combination]')?.addEventListener('click', () => {
        resetCombination();
        refresh();
    });
    const changePizzaSize = (sizeKey) => {
        if (!optionTemplates.has(sizeKey)) return;
        const selectedProductId = rows[0].querySelector('[data-pizza-variant]').selectedOptions[0]?.dataset.productId;
        size.value = sizeKey;
        rows.forEach((row) => {
            const variant = row.querySelector('[data-pizza-variant]');
            delete variant.dataset.loadedSizeKey;
            variant.replaceChildren();
        });
        resetCombination();
        refresh();
        const compatibleFlavor = [...rows[0].querySelector('[data-pizza-variant]').options]
            .find((option) => option.dataset.productId === selectedProductId);
        if (compatibleFlavor) rows[0].querySelector('[data-pizza-variant]').value = compatibleFlavor.value;
        refresh();
    };
    sizeOptions.forEach((option) => option.addEventListener('change', () => {
        if (option.checked) changePizzaSize(option.value);
    }));
    toppings.forEach((topping) => topping.addEventListener('change', refresh));
    rows.forEach((row) => row.querySelector('[data-pizza-variant]')?.addEventListener('change', refresh));
    form?.addEventListener('submit', (event) => {
        const flavors = rows.slice(0, sectionCount).map((row) => row.querySelector('[data-pizza-variant]').value);
        if (flavors.some((flavor) => !flavor) || new Set(flavors).size !== flavors.length) {
            event.preventDefault();
            error.textContent = 'Ese sabor ya está seleccionado. Elige otro sabor o utiliza la opción "1 sabor".';
            error.hidden = false;
            error.focus();
        }
    });
    composer.querySelector('[data-close-pizza-composer]')?.addEventListener('click', () => {
        composer.hidden = true;
    });
    composer.addEventListener('pizza:open', (event) => {
        composer.hidden = false;
        toppings.forEach((topping) => { topping.checked = false; });
        changePizzaSize(event.detail.sizeKey);
        rows[0].querySelector('[data-pizza-variant]').value = event.detail.variant;
        refresh();
        composer.scrollIntoView({ behavior: 'smooth', block: 'start' });
        composer.focus({ preventScroll: true });
    });
    refresh();
});

document.querySelectorAll('[data-open-pizza-composer]').forEach((button) => {
    button.addEventListener('click', () => {
        document.querySelector('[data-pizza-composer]')?.dispatchEvent(new CustomEvent('pizza:open', {
            detail: { sizeKey: button.dataset.pizzaSizeKey, variant: button.dataset.pizzaVariant, productId: button.dataset.pizzaProductId },
        }));
    });
});

document.querySelectorAll('[data-payment-selector]').forEach((selector) => {
    const forms = [...selector.querySelectorAll('[data-payment-form]')];
    const buttons = [...selector.querySelectorAll('[data-payment-method]')];
    buttons.forEach((button) => button.addEventListener('click', () => {
        forms.forEach((form) => { form.hidden = form.dataset.paymentForm !== button.dataset.paymentMethod; });
        buttons.forEach((candidate) => candidate.classList.toggle('is-active', candidate === button));
        buttons.forEach((candidate) => candidate.setAttribute('aria-pressed', candidate === button ? 'true' : 'false'));
    }));
    forms.forEach((form) => form.addEventListener('submit', (event) => {
        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = 'true';
        const submit = form.querySelector('button[type="submit"]');
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Registrando pago…';
        }
    }));
});

const decimalToCents = (value) => {
    const normalized = String(value).trim().replace(',', '.');
    if (!/^\d+(?:\.\d{0,2})?$/.test(normalized)) return null;
    const [whole, fraction = ''] = normalized.split('.');
    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
};

document.querySelectorAll('[data-cash-payment]').forEach((form) => {
    const amount = form.querySelector('[name="amount"]');
    const received = form.querySelector('[name="received_amount"]');
    const output = form.querySelector('[data-cash-change]');
    const refreshChange = () => {
        const amountCents = decimalToCents(amount.value);
        const receivedCents = decimalToCents(received.value);
        const change = amountCents !== null && receivedCents !== null && receivedCents > amountCents
            ? receivedCents - amountCents
            : 0n;
        output.textContent = `Bs ${(change / 100n).toString()},${(change % 100n).toString().padStart(2, '0')}`;
    };
    amount.addEventListener('input', refreshChange);
    received.addEventListener('input', refreshChange);
    refreshChange();
});

document.addEventListener('click', (event) => {
    const quantityButton = event.target.closest('[data-quantity-step]');
    if (quantityButton) {
        const input = quantityButton.closest('form')?.querySelector('[data-quantity-input]');
        if (input) {
            const normalized = /^\d+(?:\.\d+)?$/.test(input.value.trim()) ? input.value.trim() : '1';
            const [whole, fraction = ''] = normalized.split('.');
            const scale = fraction.length;
            const factor = 10n ** BigInt(scale);
            const current = BigInt(whole) * factor + BigInt(fraction || '0');
            const next = current + BigInt(quantityButton.dataset.quantityStep) * factor;
            const safe = next < factor ? factor : next;
            const nextWhole = safe / factor;
            const nextFraction = scale > 0 ? (safe % factor).toString().padStart(scale, '0') : '';
            input.value = scale > 0 ? `${nextWhole}.${nextFraction}` : nextWhole.toString();
        }
    }

    const addButton = event.target.closest('[data-add-row]');
    if (addButton) {
        const name = addButton.dataset.addRow;
        const container = document.querySelector(`[data-rows="${name}"]`);
        const template = document.querySelector(`#${name}-template`);
        if (container && template) {
            const index = container.querySelectorAll('[data-row]').length;
            container.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', index));
        }
    }

    const removeButton = event.target.closest('[data-remove-row]');
    if (removeButton) {
        const container = removeButton.closest('[data-rows]');
        if (!container || container.querySelectorAll('[data-row]').length > 1) {
            removeButton.closest('[data-row]')?.remove();
        }
    }
});
