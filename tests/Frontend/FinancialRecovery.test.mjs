import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/financial-recovery.js', import.meta.url), 'utf8');
const pending = { ulid: 'attempt', status: 'pending', active: true, amount: '10.00', received_amount: '10.00', method: 'cash', idempotency_key: 'same-key', owned_by_current_user: true, dispatch_ulid: null, reconcile_url: '/reconcile' };
const state = (attempt = null, version = 1, balance = '30.00') => ({ attempt, financial_version: version, balance });

async function setup(initial, saved = null) {
    const elements = new Map(['message', 'check', 'reconcile', 'retry', 'refresh'].map((name) => [name, { hidden: false, textContent: '', addEventListener(event, handler) { this[event] = handler; } }]));
    const submit = { disabled: true, attributes: {}, setAttribute(name, value) { this.attributes[name] = value; }, removeAttribute(name) { delete this.attributes[name]; } };
    const form = { action: '/payment', elements: { idempotency_key: { value: 'new-key' }, amount: { value: '10.00' }, received_amount: { value: '10.00', dispatchEvent(event) { this.lastEvent = event.type; } } }, querySelector: () => submit, addEventListener(event, handler) { this[event] = handler; } };
    const box = { dataset: { order: 'order', dispatch: '', version: '1', statusUrl: '/status', financialBlocked: '1' }, querySelector(selector) { return selector === '[data-cash-payment]' ? form : elements.get(selector.match(/data-financial-(.+)]/)[1]); } };
    const values = new Map(saved ? [['financial-payment:order', saved]] : []), responses = [initial], requests = [];
    let reloads = 0;
    runInNewContext(source, {
        document: { querySelectorAll: () => [box], querySelector: () => ({ content: 'csrf' }) },
        window: { addEventListener() {}, location: { reload: () => { reloads++; } } },
        localStorage: { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: (key) => values.delete(key) },
        URLSearchParams, AbortSignal, Event, FormData: class { constructor(input) { this.key = input.elements.idempotency_key.value; this.amount = input.elements.amount.value; this.received = input.elements.received_amount.value; } },
        fetch: async (url, options) => { requests.push({ url, options }); const response = responses.shift(); if (response instanceof Error) throw response; return response; },
    });
    await new Promise((resolve) => setImmediate(resolve));
    return { elements, submit, form, box, values, responses, requests, reloads: () => reloads };
}
const ok = (data) => ({ ok: true, status: 200, json: async () => data });

test('submitting cash shows loading and ignores a second click', async () => {
    const ui = await setup(ok(state()));
    let resolvePayment;
    ui.responses.push(new Promise((resolve) => { resolvePayment = resolve; }), ok(state({ ...pending, idempotency_key: 'new-key', active: false, status: 'confirmed' }, 2, '20.00')));
    const first = ui.form.submit({ preventDefault() {} });
    assert.equal(ui.submit.disabled, true);
    assert.equal(ui.submit.textContent, 'Registrando efectivo…');
    assert.equal(ui.submit.attributes['aria-busy'], 'true');
    await ui.form.submit({ preventDefault() {} });
    assert.equal(ui.requests.filter((request) => request.url === '/payment').length, 1);
    resolvePayment({ status: 200 });
    await first;
    assert.equal(ui.submit.textContent, 'Registrar efectivo');
    assert.equal(ui.submit.attributes['aria-busy'], undefined);
    assert.match(ui.elements.get('message').textContent, /confirmado/);
});

test('reload detects a pending attempt and retries only with its original identity', async () => {
    const ui = await setup(ok(state(pending)), 'same-key');
    assert.equal(ui.submit.disabled, true);
    ui.responses.push(ok({ attempt: { ...pending, status: 'retryable' } }));
    await ui.elements.get('reconcile').click();
    ui.elements.get('retry').click();
    assert.equal(ui.form.elements.idempotency_key.value, 'same-key');
    assert.equal(ui.form.elements.received_amount.lastEvent, 'input');
    assert.equal(ui.submit.disabled, false);
    assert.equal(ui.box.dataset.financialBlocked, '1');
});

test('a lost response stays blocked when the server cannot find the attempt', async () => {
    const ui = await setup(ok(state()));
    ui.responses.push(new Error('lost'), ok(state()));
    await ui.form.submit({ preventDefault() {} });
    assert.equal(ui.values.get('financial-payment:order'), 'new-key');
    assert.equal(ui.submit.disabled, true);
    assert.match(ui.elements.get('message').textContent, /No se pudo confirmar/);
});

test('confirmed lost response requires explicit review before a new payment', async () => {
    const ui = await setup(ok(state()));
    ui.responses.push(new Error('lost'), ok(state({ ...pending, idempotency_key: 'new-key', active: false, status: 'confirmed' }, 2, '20.00')));
    await ui.form.submit({ preventDefault() {} });
    assert.equal(ui.submit.disabled, true);
    assert.match(ui.elements.get('message').textContent, /confirmado.*20\.00/);
    ui.elements.get('refresh').click();
    assert.equal(ui.values.size, 0);
    assert.equal(ui.reloads(), 1);
});

test('stale 409 displays the current balance without repeating the payment', async () => {
    const ui = await setup(ok(state()));
    ui.responses.push({ status: 409 }, ok(state(null, 2, '20.00')));
    await ui.form.submit({ preventDefault() {} });
    assert.equal(ui.requests.filter((request) => request.url === '/payment').length, 1);
    assert.equal(ui.form.elements.idempotency_key.value, 'new-key');
    assert.match(ui.elements.get('message').textContent, /20\.00/);
    assert.equal(ui.elements.get('refresh').hidden, false);
});

test('cash 65 received 70 submits once and keeps the confirmed payment blocked', async () => {
    const ui = await setup(ok(state(null, 1, '65.00')));
    assert.equal(ui.submit.disabled, false);
    ui.form.elements.amount.value = '65.00';
    ui.form.elements.received_amount.value = '70.00';
    ui.responses.push({ status: 200 }, ok(state({ ...pending, amount: '65.00', received_amount: '70.00', idempotency_key: 'new-key', active: false, status: 'confirmed' }, 2, '0.00')));
    await ui.form.submit({ preventDefault() {} });
    const payment = ui.requests.find((request) => request.url === '/payment');
    assert.equal(payment.options.body.amount, '65.00');
    assert.equal(payment.options.body.received, '70.00');
    assert.equal(ui.requests.filter((request) => request.url === '/payment').length, 1);
    assert.equal(ui.submit.disabled, true);
    assert.match(ui.elements.get('message').textContent, /confirmado.*0\.00/);
    assert.equal(ui.submit.attributes['aria-busy'], undefined);
});

test('a timed out status request stays safe and can be checked again', async () => {
    const ui = await setup(new Error('timed out'));
    assert.equal(ui.submit.disabled, true);
    assert.match(ui.elements.get('message').textContent, /No se pudo verificar/);
    ui.responses.push(ok(state(null, 1, '65.00')));
    await ui.elements.get('check').click();
    assert.equal(ui.submit.disabled, false);
    assert.ok(ui.requests.every((request) => request.options.signal));
});
