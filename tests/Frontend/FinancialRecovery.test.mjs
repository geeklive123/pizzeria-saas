import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/financial-recovery.js', import.meta.url), 'utf8');
const pending = { ulid: 'attempt', status: 'pending', active: true, amount: '10.00', received_amount: '10.00', method: 'cash', idempotency_key: 'same-key', owned_by_current_user: true, dispatch_ulid: null, reconcile_url: '/reconcile' };
const state = (attempt = null, version = 1, balance = '30.00') => ({ attempt, financial_version: version, balance });

async function setup(initial, saved = null) {
    const elements = new Map(['message', 'check', 'reconcile', 'retry', 'refresh'].map((name) => [name, { hidden: false, textContent: '', addEventListener(event, handler) { this[event] = handler; } }]));
    const submit = { disabled: true };
    const form = { action: '/payment', elements: { idempotency_key: { value: 'new-key' }, amount: { value: '10.00' }, received_amount: { value: '10.00' } }, querySelector: () => submit, addEventListener(event, handler) { this[event] = handler; } };
    const box = { dataset: { order: 'order', dispatch: '', version: '1', statusUrl: '/status', financialBlocked: '1' }, querySelector(selector) { return selector === '[data-cash-payment]' ? form : elements.get(selector.match(/data-financial-(.+)]/)[1]); } };
    const values = new Map(saved ? [['financial-payment:order', saved]] : []), responses = [initial], requests = [];
    let reloads = 0;
    runInNewContext(source, {
        document: { querySelectorAll: () => [box], querySelector: () => ({ content: 'csrf' }) },
        window: { addEventListener() {}, location: { reload: () => { reloads++; } } },
        localStorage: { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: (key) => values.delete(key) },
        URLSearchParams, FormData: class { constructor(input) { this.key = input.elements.idempotency_key.value; } },
        fetch: async (url, options) => { requests.push({ url, options }); const response = responses.shift(); if (response instanceof Error) throw response; return response; },
    });
    await new Promise((resolve) => setImmediate(resolve));
    return { elements, submit, form, box, values, responses, requests, reloads: () => reloads };
}
const ok = (data) => ({ ok: true, status: 200, json: async () => data });

test('reload detects a pending attempt and retries only with its original identity', async () => {
    const ui = await setup(ok(state(pending)), 'same-key');
    assert.equal(ui.submit.disabled, true);
    ui.responses.push(ok({ attempt: { ...pending, status: 'retryable' } }));
    await ui.elements.get('reconcile').click();
    ui.elements.get('retry').click();
    assert.equal(ui.form.elements.idempotency_key.value, 'same-key');
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
