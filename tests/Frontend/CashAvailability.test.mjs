import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = (await readFile(new URL('../../resources/js/cash-availability.js', import.meta.url), 'utf8')).replace('export function', 'function');
const tick = () => new Promise((resolve) => setImmediate(resolve));

function setup() {
    const handlers = {}, responses = [], requests = [];
    const form = { dataset: { preparationEnabled: 'true', cashAvailabilityUrl: '/availability', demo: 'false' }, mode: 'delivery',
        querySelector: () => ({ value: form.mode }), addEventListener: (name, callback) => { handlers[name] = callback; } };
    const submit = { dataset: {}, disabled: false, textContent: '' }, warning = { hidden: true, textContent: '' };
    const context = { form, submit, warning, document: { hidden: false, addEventListener: (name, callback) => { handlers[name] = callback; } },
        window: { addEventListener: (name, callback) => { handlers[name] = callback; }, setInterval: (callback) => { handlers.interval = callback; } },
        fetch: async (url) => { requests.push(url); const next = responses.shift(); if (next instanceof Error) throw next; return await next; } };
    const api = runInNewContext(`${source}\ninitCashAvailability(form, submit, warning, () => true);`, context);
    return { api, form, submit, warning, handlers, responses, requests };
}
const ok = (open) => ({ ok: true, json: async () => ({ open }) });

test('closed and opened cash updates delivery button and warning, while pickup remains available', async () => {
    const ui = setup(); await tick();
    ui.responses.push(ok(false)); await ui.api.check();
    assert.equal(ui.submit.disabled, true); assert.equal(ui.submit.textContent, 'Pedidos no disponibles');
    assert.equal(ui.submit.dataset.cashUnavailable, 'true'); assert.equal(ui.warning.hidden, false); assert.match(ui.warning.textContent, /fuera de horario/);
    ui.form.mode = 'pickup'; ui.handlers.change({ target: { name: 'fulfillment' } });
    assert.equal(ui.submit.disabled, false); assert.equal(ui.submit.textContent, 'Realizar pedido'); assert.equal(ui.warning.hidden, true);
    ui.responses.push(ok(true)); ui.form.mode = 'delivery'; ui.handlers.change({ target: { name: 'fulfillment' } }); await tick();
    assert.equal(ui.submit.disabled, false); assert.equal(ui.submit.textContent, 'Preparar pedido'); assert.equal(ui.warning.hidden, true);
    ui.responses.push(ok(false)); ui.handlers.interval(); await tick();
    assert.equal(ui.submit.disabled, true); assert.equal(ui.warning.hidden, false);
});

test('failed and stale checks cannot incorrectly enable delivery', async () => {
    const ui = setup(); await tick();
    ui.responses.push(new Error('offline')); await ui.api.check();
    assert.equal(ui.submit.disabled, true); assert.match(ui.warning.textContent, /verificar/);
    let finishOld; ui.responses.push(new Promise((resolve) => { finishOld = resolve; }));
    const old = ui.api.check(); ui.responses.push(ok(false)); await ui.api.check(); finishOld(ok(true)); await old;
    assert.equal(ui.submit.disabled, true); assert.match(ui.warning.textContent, /fuera de horario/);
    ui.submit.dataset.cashBusy = 'true'; ui.submit.textContent = 'Validando pedido…';
    ui.responses.push(ok(true)); await ui.api.check(); assert.equal(ui.submit.textContent, 'Validando pedido…');
    ui.submit.dataset.cashBusy = 'false'; ui.api.render(); assert.equal(ui.submit.disabled, false);
});
