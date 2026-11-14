import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/storefront.js', import.meta.url), 'utf8');
const checkout = source.slice(source.indexOf('const initCheckout ='), source.indexOf("document.addEventListener('click'"));
const key = 'masa-y-mana-online-request-v1';
const ok = (data) => ({ ok: true, status: 200, json: async () => data });

function setup(saved = new Map(), initialResponses = []) {
    const form = { action: '/store', dataset: { demo: 'false', recoverUrl: '/recover' }, addEventListener(event, fn) { this[event] = fn; }, reportValidity: () => true };
    const submit = { disabled: false, textContent: '', addEventListener() {} };
    const error = { hidden: true, textContent: '' };
    const confirmation = { hidden: true, textContent: '' };
    const elements = { '[data-checkout-form]': form, '[data-submit-order]': submit, '[data-form-errors]': error, '[data-demo-confirmation]': confirmation, 'meta[name="csrf-token"]': { content: 'csrf' } };
    const responses = [...initialResponses], calls = [];
    let cleared = 0;
    const context = {
        document: { querySelector: (selector) => elements[selector] },
        cart: [{ variantUlid: 'variant', quantity: 2, price: '0.01' }],
        localStorage: { getItem: (name) => saved.get(name) ?? null, setItem: (name, value) => saved.set(name, value), removeItem: (name) => saved.delete(name) },
        saveCart() { cleared++; }, toast() {}, crypto: { randomUUID: () => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' },
        FormData: class { get(name) { return { customer_name: 'Cliente', customer_phone: '70000000', notes: '' }[name]; } },
        fetch: async (url, options) => { calls.push({ url, body: JSON.parse(options.body) }); const response = responses.shift(); if (response instanceof Error) throw response; return response; },
    };
    runInNewContext(`${checkout}\ninitCheckout();`, context);
    return { form, submit, error, confirmation, saved, responses, calls, cleared: () => cleared };
}

test('only clears cart after verified success and sends no local price', async () => {
    const ui = setup();
    let resolve;
    ui.responses.push(new Promise((done) => { resolve = done; }));
    const first = ui.form.submit({ preventDefault() {} });
    await ui.form.submit({ preventDefault() {} });
    assert.equal(ui.calls.length, 1);
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
    const reloaded = setup(saved, [ok({ code: 'WEB-000001', subtotal: '179.00' })]);
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(reloaded.calls[0].url, '/recover');
    assert.equal(reloaded.cleared(), 1);
    assert.equal(saved.has(key), false);
});

test('validation failure leaves cart and attempt intact', async () => {
    const ui = setup();
    ui.responses.push({ ok: false, status: 422, json: async () => ({ errors: { items: ['No disponible'] } }) });
    await ui.form.submit({ preventDefault() {} });
    assert.equal(ui.cleared(), 0);
    assert.equal(ui.saved.has(key), true);
    assert.equal(ui.error.textContent, 'No disponible');
});
