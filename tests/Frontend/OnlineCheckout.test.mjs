import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import { webcrypto } from 'node:crypto';

const source = await readFile(new URL('../../resources/js/storefront.js', import.meta.url), 'utf8');
const checkout = source.slice(source.indexOf('const initCheckout ='), source.indexOf("document.addEventListener('click'"));
const key = 'masa-y-mana-online-request-v1';
const ok = (data) => ({ ok: true, status: 200, json: async () => data });

function setup(saved = new Map(), initialResponses = [], preparation = false) {
    const form = { action: '/store', dataset: { demo: 'false', resolveUrl: '/resolve' }, addEventListener(event, fn) { this[event] = fn; }, reportValidity: () => true };
    if (preparation) Object.assign(form.dataset, { preparationEnabled: 'true', preparationUrl: '/prepare', preparationResolveUrl: '/prepare/resolve', preparationCancelUrl: '/prepare/cancel' });
    const submit = { disabled: false, textContent: '', dataset: {}, addEventListener() {} };
    const error = { hidden: true, textContent: '' };
    const confirmation = { hidden: true, textContent: '' };
    const elements = { '[data-checkout-form]': form, '[data-submit-order]': submit, '[data-form-errors]': error, '[data-demo-confirmation]': confirmation, 'meta[name="csrf-token"]': { content: 'csrf' } };
    const responses = [...initialResponses], calls = [];
    let nextKey = 0;
    let cleared = 0;
    const context = {
        PREPARATION_KEY: 'masa-y-mana-delivery-preparation-v1',
        document: { querySelector: (selector) => elements[selector] },
        cart: [{ variantUlid: 'variant', quantity: 2, price: '0.01' }],
        localStorage: { getItem: (name) => saved.get(name) ?? null, setItem: (name, value) => saved.set(name, value), removeItem: (name) => saved.delete(name) },
        saveCart() { cleared++; }, toast() {}, crypto: { subtle: webcrypto.subtle, randomUUID: () => nextKey++ ? 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' : 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' }, TextEncoder,
        ...(preparation ? { initDelivery: () => ({ payload: () => ({ fulfillment: 'delivery', address: 'Calle', reference: 'Porton', latitude: -17.37, longitude: -66.17, location_confirmed: true }) }) } : {}),
        FormData: class { get(name) { return { customer_name: 'Cliente', customer_phone: '70000000', notes: '' }[name]; } },
        fetch: async (url, options) => { calls.push({ url, body: JSON.parse(options.body) }); const response = responses.shift(); if (response instanceof Error) throw response; return response; },
    };
    runInNewContext(`${checkout}\ninitCheckout();`, context);
    return { form, submit, error, confirmation, saved, responses, calls, context, cleared: () => cleared };
}

test('delivery preparation keeps its identity and cart after a lost response', async () => {
    const saved = new Map();
    const ui = setup(saved, [new Error('offline'), new Error('offline')], true);
    await ui.form.submit({ preventDefault() {} });
    const attempt = JSON.parse(saved.get('masa-y-mana-delivery-preparation-v1'));
    assert.equal(attempt.key, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    assert.doesNotMatch(JSON.stringify(attempt), /Cliente|Calle|70000000/);
    assert.equal(ui.cleared(), 0);
    const reloaded = setup(saved, [ok({ created: true, ready: true, subtotal: '24.00' })], true);
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(reloaded.calls[0].url, '/prepare/resolve');
    assert.equal(reloaded.cleared(), 0);
    assert.match(reloaded.confirmation.textContent, /No se ha realizado ningún pago/);
});

test('changed cart cancels a recovered preparation before creating another', async () => {
    const ui = setup(new Map(), [ok({ created: true, ready: true, subtotal: '24.00' })], true);
    await ui.form.submit({ preventDefault() {} });
    ui.context.cart[0].quantity = 3;
    ui.responses.push(ok({ created: true, ready: true, subtotal: '24.00' }), ok({ cancelled: true }), ok({ created: true, ready: true, subtotal: '36.00' }));
    await ui.form.submit({ preventDefault() {} });
    assert.deepEqual(ui.calls.map((call) => call.url), ['/prepare', '/prepare/resolve', '/prepare/cancel', '/prepare']);
    assert.notEqual(ui.calls[0].body.idempotency_key, ui.calls[3].body.idempotency_key);
    assert.equal(ui.cleared(), 0);
});

test('moving the map cancels the previous preparation and retains the cart', async () => {
    const ui = setup(new Map(), [ok({ created: true, ready: true, subtotal: '24.00' })], true);
    await ui.form.submit({ preventDefault() {} });
    ui.responses.push(ok({ created: true, ready: true, subtotal: '24.00' }), ok({ cancelled: true }));
    ui.form['storefront:location-changed']();
    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(ui.calls.map((call) => call.url), ['/prepare', '/prepare/resolve', '/prepare/cancel']);
    assert.equal(ui.saved.has('masa-y-mana-delivery-preparation-v1'), false);
    assert.equal(ui.cleared(), 0);
});

test('double click sends only one preparation request', async () => {
    const ui = setup(new Map(), [], true);
    let complete;
    ui.responses.push(new Promise((resolve) => { complete = resolve; }));
    const first = ui.form.submit({ preventDefault() {} });
    await ui.form.submit({ preventDefault() {} });
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(ui.calls.length, 1);
    complete(ok({ created: true, ready: true, subtotal: '24.00' }));
    await first;
    assert.equal(ui.cleared(), 0);
});

test('failed cancellation keeps a dirty identity for later recovery', async () => {
    const ui = setup(new Map(), [ok({ created: true, ready: true, subtotal: '24.00' })], true);
    await ui.form.submit({ preventDefault() {} });
    ui.responses.push(new Error('offline'));
    ui.form.input();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(JSON.parse(ui.saved.get('masa-y-mana-delivery-preparation-v1')).dirty, true);
    assert.match(ui.error.textContent, /No se pudo cancelar/);
});

test('only clears cart after verified success and sends no local price', async () => {
    const ui = setup();
    let resolve;
    ui.responses.push(new Promise((done) => { resolve = done; }));
    const first = ui.form.submit({ preventDefault() {} });
    await ui.form.submit({ preventDefault() {} });
    assert.equal(ui.calls.length, 1);
    assert.equal(ui.submit.disabled, true);
    assert.match(ui.submit.textContent, /Validando pedido/);
    assert.equal(ui.cleared(), 0);
    assert.equal(ui.calls[0].body.items[0].price, undefined);
    resolve(ok({ code: 'WEB-000001', subtotal: '179.00' }));
    await first;
    assert.equal(ui.cleared(), 1);
    assert.equal(ui.saved.has(key), false);
    assert.match(ui.confirmation.textContent, /WEB-000001/);
});

test('network failure retains the cart and retry identity for later recovery', async () => {
    const saved = new Map();
    const ui = setup(saved);
    ui.responses.push(new Error('offline'));
    await ui.form.submit({ preventDefault() {} });
    assert.equal(ui.cleared(), 0);
    assert.equal(JSON.parse(saved.get(key)).key, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    assert.doesNotMatch(saved.get(key), /Cliente|70000000/);
    const reloaded = setup(saved, [ok({ created: true, code: 'WEB-000001', subtotal: '179.00' })]);
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(reloaded.calls[0].url, '/resolve');
    assert.equal(reloaded.cleared(), 1);
    assert.equal(saved.has(key), false);
});

test('validation failure leaves cart intact and fences the key before correction', async () => {
    const ui = setup();
    ui.responses.push({ ok: false, status: 422, json: async () => ({ errors: { items: ['No disponible'] } }) });
    ui.responses.push(ok({ created: false }));
    await ui.form.submit({ preventDefault() {} });
    assert.equal(ui.cleared(), 0);
    assert.equal(ui.saved.has(key), false);
    assert.equal(ui.error.textContent, 'No disponible');
    ui.responses.push(ok({ code: 'WEB-000002', subtotal: '10.00' }));
    await ui.form.submit({ preventDefault() {} });
    assert.notEqual(ui.calls[0].body.idempotency_key, ui.calls[2].body.idempotency_key);
    assert.equal(ui.cleared(), 1);
});

test('failed resolution retains attempt and never sends changed contents', async () => {
    const saved = new Map();
    const ui = setup(saved);
    ui.responses.push(new Error('offline'));
    await ui.form.submit({ preventDefault() {} });
    ui.responses.push(new Error('still offline'));
    await ui.form.submit({ preventDefault() {} });
    assert.equal(ui.calls.length, 2);
    assert.equal(ui.calls[1].url, '/resolve');
    assert.equal(ui.saved.has(key), true);
    assert.equal(ui.cleared(), 0);
});

test('changed cart resolves the uncertain key before creating a new request', async () => {
    const ui = setup();
    ui.responses.push(new Error('offline'));
    await ui.form.submit({ preventDefault() {} });
    ui.context.cart[0].quantity = 3;
    ui.responses.push(ok({ created: false }), ok({ code: 'WEB-000003', subtotal: '30.00' }));
    await ui.form.submit({ preventDefault() {} });
    assert.equal(ui.calls[1].url, '/resolve');
    assert.equal(ui.calls[2].url, '/store');
    assert.notEqual(ui.calls[0].body.idempotency_key, ui.calls[2].body.idempotency_key);
    assert.equal(ui.calls[2].body.items[0].quantity, 3);
    assert.equal(ui.cleared(), 1);
});

test('a recovered older request does not clear a changed cart', async () => {
    const ui = setup();
    ui.responses.push(new Error('offline'));
    await ui.form.submit({ preventDefault() {} });
    ui.context.cart[0].quantity = 3;
    ui.responses.push(ok({ created: true, code: 'WEB-000003', subtotal: '20.00' }));
    await ui.form.submit({ preventDefault() {} });
    assert.equal(ui.calls.length, 2);
    assert.equal(ui.cleared(), 0);
    assert.match(ui.confirmation.textContent, /WEB-000003/);
});
