import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/dynamic-qr.js', import.meta.url), 'utf8');
const bankIntent = (status = 'pending', overrides = {}) => ({
    ulid: 'intent-one', status, amount: '40.00', currency: 'BOB', image_url: '/image', bank_qr_id: 'bank-1',
    commercial_reference: 'reference-1', dispatch_ulid: null, idempotency_key: 'original-key', expires_at: '2099-10-30T00:00:00Z',
    message: status === 'paid' ? 'Pago confirmado' : 'Esperando pago', issue: null,
    check_url: '/check', cancel_url: '/cancel', redirect_url: '/orders', ...overrides,
});

async function harness(enabled = '1', initial = { intent: null }, savedKey = null) {
    const elements = new Map();
    for (const name of ['open', 'open-mixed', 'reopen', 'indicator', 'indicator-balance', 'refresh', 'mixed-form', 'mixed-submit', 'cash', 'received', 'confirm-cash', 'estimate', 'change', 'cash-paid', 'paid', 'balance', 'qr-pending', 'close', 'modal', 'generate', 'status', 'amount', 'image', 'reference', 'identifier', 'expiry', 'check', 'cancel', 'error']) {
        elements.set(name, { hidden: false, disabled: false, checked: false, value: '', textContent: '',
            addEventListener(event, handler) { this[event] = handler; }, focus() {}, removeAttribute() { this.src = ''; } });
    }
    const modal = elements.get('modal');
    modal.showModal = () => { modal.open = true; };
    modal.close = () => { modal.open = false; modal.closeHandler(); };
    modal.addEventListener = (event, handler) => { if (event === 'close') modal.closeHandler = handler; };
    const box = { dataset: { enabled, createUrl: '/create', currentUrl: '/current', dispatch: '', key: 'original-key', financialVersion: '7' },
        querySelector(selector) { return elements.get(selector.match(/data-qr-(.+)]/)[1]); }, contains: () => false };
    const requests = [], responses = [initial], timers = new Map(), redirects = [];
    const saved = new Map(savedKey ? [['qr-generation:', savedKey]] : []);
    const events = {};
    let sequence = 0;
    runInNewContext(source, {
        document: { hidden: false, querySelectorAll: () => [box], querySelector: () => ({ content: 'csrf-test' }), addEventListener: (event, handler) => { events[event] = handler; } },
        window: { location: { reload: () => redirects.push('reload') } },
        crypto: { randomUUID: () => 'fresh-key' }, Date, JSON, Error, AbortSignal,
        localStorage: { getItem: (key) => saved.get(key) ?? null, setItem: (key, value) => saved.set(key, value), removeItem: (key) => saved.delete(key) },
        setTimeout(callback, delay) { timers.set(++sequence, { callback, delay }); return sequence; },
        clearTimeout(id) { timers.delete(id); },
        async fetch(url, options) {
            requests.push({ url, options, body: options.body ? JSON.parse(options.body) : null });
            const response = await responses.shift();
            assert.notEqual(response, undefined, 'Unexpected UI request');
            if (response instanceof Error) throw response;
            if (response.httpStatus) return { ok: false, status: response.httpStatus, json: async () => response.data };
            return { ok: true, json: async () => response };
        },
    });
    await new Promise((resolve) => setImmediate(resolve));
    requests.length = 0;
    return { elements, box, requests, responses, timers, redirects, events };
}

test('opening generates only the server balance and displays the bank image', async () => {
    const ui = await harness();
    ui.responses.push({ intent: null }, { intent: bankIntent() });
    await ui.elements.get('open').click();
    assert.equal(ui.requests[0].url, '/current');
    assert.equal(ui.requests[1].url, '/create');
    assert.equal(ui.requests[1].body.cash_amount, undefined);
    assert.equal(ui.requests[1].body.amount, undefined);
    assert.equal(ui.requests[1].body.financial_version, '7');
    assert.equal(ui.elements.get('amount').textContent, 'BOB 40.00');
    assert.equal(ui.elements.get('image').src, '/image');
    assert.equal(ui.elements.get('image').hidden, false);
    assert.equal(ui.elements.get('reference').textContent, 'Referencia: reference-1');
    assert.equal(ui.elements.get('identifier').textContent, 'QR ID: bank-1');
    assert.equal(ui.elements.get('status').textContent, 'Esperando pago');
    assert.equal([...ui.timers.values()].filter((t) => t.delay === 6500).length, 1);
});

test('a stale financial version shows the new balance and requires explicit review', async () => {
    const ui = await harness();
    ui.responses.push({ intent: null }, { httpStatus: 409, data: { message: 'El pedido cambió.', summary: { balance: '30.00' } } });
    await ui.elements.get('open').click();
    assert.match(ui.elements.get('status').textContent, /pedido cambió/);
    assert.match(ui.elements.get('balance').textContent, /30\.00/);
    assert.equal(ui.elements.get('refresh').hidden, false);
    assert.equal(ui.requests.filter((r) => r.url === '/create').length, 1);
    assert.equal(ui.box.dataset.key, 'original-key');
});

test('closing preserves the attempt and reopening resumes it without another generation', async () => {
    const ui = await harness();
    ui.responses.push({ intent: null }, { intent: bankIntent() });
    await ui.elements.get('open').click();
    ui.elements.get('close').click();
    assert.equal([...ui.timers.values()].filter((t) => t.delay === 10000).length, 1);
    ui.responses.push({ intent: bankIntent() });
    await ui.elements.get('open').click();
    assert.equal(ui.requests.filter((r) => r.url === '/create').length, 1);
    assert.equal(ui.requests.filter((r) => r.url === '/cancel').length, 0);
    assert.equal(ui.elements.get('image').hidden, false);
});

test('manual confirmation redirects and sends no payment amount', async () => {
    const ui = await harness();
    ui.responses.push({ intent: bankIntent() });
    await ui.elements.get('open').click();
    ui.responses.push({ intent: bankIntent('paid') });
    await ui.elements.get('check').click();
    assert.deepEqual(ui.requests[1].body, {});
    assert.equal(ui.elements.get('status').textContent, 'Pago confirmado');
    [...ui.timers.values()].find((t) => t.delay === 2500).callback();
    assert.deepEqual(ui.redirects, ['reload']);
});

test('uncertain generation or a connection loss does not generate another QR', async () => {
    const ui = await harness();
    ui.responses.push({ intent: null }, new Error('offline'));
    await ui.elements.get('open').click();
    ui.elements.get('close').click();
    ui.responses.push({ intent: null });
    await ui.elements.get('open').click();
    assert.equal(ui.requests.filter((r) => r.url === '/create').length, 1);
    assert.match(ui.elements.get('status').textContent, /No se confirmó/);
});

test('reloading after an uncertain generation does not create a second QR', async () => {
    const ui = await harness('1', { intent: null }, 'original-key');
    ui.responses.push({ intent: null });
    await ui.elements.get('open').click();
    assert.equal(ui.requests.filter((r) => r.url === '/create').length, 0);
    assert.match(ui.elements.get('status').textContent, /No se confirmó/);
});

test('a QR for another batch is never displayed or polled', async () => {
    const ui = await harness();
    ui.box.dataset.dispatch = 'batch-two';
    ui.responses.push({ intent: bankIntent('pending', { dispatch_ulid: 'batch-one' }) });
    await ui.elements.get('open').click();
    assert.equal(ui.elements.get('image').hidden, true);
    assert.match(ui.elements.get('status').textContent, /otra tanda/);
    assert.equal([...ui.timers.values()].filter((t) => t.delay === 6500).length, 1);
});

test('an expired QR is hidden until its bank status is resolved', async () => {
    const ui = await harness();
    ui.responses.push({ intent: bankIntent('pending', { expires_at: '2020-01-01T00:00:00Z', expired: true }) });
    await ui.elements.get('open').click();
    assert.equal(ui.elements.get('image').hidden, true);
    assert.equal(ui.elements.get('generate').hidden, true);
    assert.match(ui.elements.get('status').textContent, /Vigencia vencida/);
    assert.equal(ui.elements.get('check').hidden, false);
});

test('disabled generation still allows existing intent recovery', async () => {
    const ui = await harness('0');
    ui.responses.push({ intent: bankIntent() });
    await ui.elements.get('open').click();
    assert.equal(ui.elements.get('check').hidden, false);
    assert.equal(ui.requests.length, 1);
});

test('polling keeps the PNG visible while awaiting repeated bank checks', async () => {
    const ui = await harness();
    ui.responses.push({ intent: bankIntent() });
    await ui.elements.get('open').click();
    let resolve;
    ui.responses.push(new Promise((done) => { resolve = done; }));
    const pending = [...ui.timers.values()].find((t) => t.delay === 6500).callback();
    assert.equal(ui.elements.get('image').hidden, false);
    assert.equal(ui.elements.get('status').textContent, 'Consultando banco…');
    resolve({ intent: bankIntent() });
    await pending;
    assert.equal(ui.elements.get('status').textContent, 'Esperando pago');
    assert.equal(ui.elements.get('image').hidden, false);
    assert.equal(ui.requests.filter((r) => r.url === '/create').length, 0);
});

test('a missing or inaccessible image is explained without generating again', async () => {
    const ui = await harness();
    ui.responses.push({ intent: bankIntent('pending', { image_url: null }) });
    await ui.elements.get('open').click();
    assert.match(ui.elements.get('error').textContent, /imagen QR no está disponible/);
    ui.elements.get('image').error();
    assert.match(ui.elements.get('error').textContent, /No se pudo cargar/);
    assert.equal(ui.requests.filter((r) => r.url === '/create').length, 0);
});

test('mixed payment requires explicit cash confirmation and sends no computed QR amount', async () => {
    const ui = await harness();
    ui.responses.push({ intent: null, summary: { balance: '80.00', paid: '0.00', cash_paid: '0.00' } });
    await ui.elements.get('open-mixed').click();
    assert.equal(ui.requests.length, 1);
    ui.elements.get('cash').value = '30.00';
    ui.elements.get('received').value = '40.00';
    ui.elements.get('cash').input();
    assert.equal(ui.elements.get('estimate').textContent, 'QR restante estimado: Bs 50,00');
    assert.equal(ui.elements.get('change').textContent, 'Cambio estimado: Bs 10,00');
    await ui.elements.get('mixed-submit').click();
    assert.equal(ui.requests.length, 1);
    ui.elements.get('confirm-cash').checked = true;
    ui.responses.push({ intent: bankIntent('pending', { amount: '50.00' }), summary: { balance: '50.00', paid: '30.00', cash_paid: '30.00' } });
    await ui.elements.get('mixed-submit').click();
    assert.equal(ui.requests[1].body.cash_amount, '30.00');
    assert.equal(ui.requests[1].body.received_amount, '40.00');
    assert.equal(ui.requests[1].body.confirm_cash, true);
    assert.equal(ui.requests[1].body.amount, undefined);
    assert.equal(ui.elements.get('cash-paid').textContent, 'Efectivo registrado: BOB 30.00');
    assert.equal(ui.elements.get('qr-pending').textContent, 'QR pendiente: BOB 50.00');
    ui.elements.get('close').click();
    ui.responses.push({ intent: bankIntent('pending', { amount: '50.00' }), summary: { balance: '50.00', paid: '30.00', cash_paid: '30.00' } });
    await ui.elements.get('open-mixed').click();
    assert.equal(ui.requests.filter((request) => request.url === '/create').length, 1);
    assert.equal(ui.elements.get('mixed-form').hidden, true);
    assert.equal(ui.elements.get('cash-paid').textContent, 'Efectivo registrado: BOB 30.00');
});

test('reopening an already paid sale does not request another QR or cash', async () => {
    const ui = await harness();
    ui.responses.push({ intent: null, summary: { balance: '0.00', paid: '80.00', cash_paid: '30.00' } });
    await ui.elements.get('open-mixed').click();
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.elements.get('mixed-form').hidden, true);
    assert.match(ui.elements.get('status').textContent, /No queda saldo/);
});

test('loading the page recovers the pending attempt and reopens the same image', async () => {
    const ui = await harness('1', { intent: bankIntent(), summary: { balance: '40.00', paid: '30.00', cash_paid: '30.00' } });
    assert.equal(ui.elements.get('indicator').textContent, 'QR pendiente');
    assert.equal(ui.elements.get('indicator-balance').textContent, 'Pagado: BOB 30.00 · Saldo: BOB 40.00');
    assert.equal(ui.elements.get('reopen').hidden, false);
    ui.responses.push({ intent: bankIntent() });
    await ui.elements.get('reopen').click();
    assert.equal(ui.elements.get('image').src, '/image');
    assert.equal(ui.requests.filter((request) => request.url === '/create').length, 0);
});

test('hidden modal follows persisted confirmation without cancelling or creating another QR', async () => {
    const ui = await harness('1', { intent: bankIntent() });
    ui.responses.push({ intent: bankIntent() });
    await ui.elements.get('reopen').click();
    ui.elements.get('close').click();
    ui.responses.push({ intent: bankIntent('paid'), summary: { balance: '0.00', paid: '40.00', cash_paid: '0.00' } });
    await [...ui.timers.values()].find((t) => t.delay === 10000).callback();
    assert.equal(ui.requests.at(-1).url, '/current?intent=intent-one');
    assert.equal(ui.requests.at(-1).options.method, 'GET');
    assert.equal(ui.elements.get('indicator').textContent, 'Pago confirmado');
    assert.match(ui.elements.get('indicator-balance').textContent, /Saldo: BOB 0.00/);
    assert.equal(ui.requests.some((request) => ['/create', '/cancel', '/check'].includes(request.url)), false);
    [...ui.timers.values()].find((t) => t.delay === 2500).callback();
    assert.deepEqual(ui.redirects, ['reload']);
});

test('expired and uncertain attempts remain visible and cannot generate another QR', async () => {
    const expired = await harness('1', { intent: bankIntent('pending', { expired: true }) });
    assert.equal(expired.elements.get('indicator').textContent, 'QR vencido');
    expired.responses.push({ intent: bankIntent('pending', { expired: true }) });
    await expired.elements.get('reopen').click();
    assert.equal(expired.elements.get('image').hidden, true);
    assert.equal(expired.elements.get('generate').hidden, true);
    const uncertain = await harness('1', { intent: bankIntent('generation_unknown') });
    assert.equal(uncertain.elements.get('indicator').textContent, 'Requiere conciliación');
    assert.equal(uncertain.elements.get('open').hidden, true);
    uncertain.responses.push({ intent: bankIntent('generation_unknown') });
    await uncertain.elements.get('reopen').click();
    assert.equal(uncertain.requests.some((request) => request.url === '/create'), false);
});

test('confirmation preserves edits in other forms until the cashier refreshes', async () => {
    const ui = await harness('1', { intent: bankIntent() });
    ui.events.input({ target: {} });
    ui.responses.push({ intent: bankIntent('paid'), summary: { balance: '0.00', paid: '40.00', cash_paid: '0.00' } });
    await [...ui.timers.values()].find((t) => t.delay === 10000).callback();
    assert.equal(ui.elements.get('indicator').textContent, 'Pago confirmado');
    assert.equal(ui.elements.get('refresh').hidden, false);
    assert.equal([...ui.timers.values()].some((t) => t.delay === 2500), false);
    ui.elements.get('refresh').click();
    assert.deepEqual(ui.redirects, ['reload']);
});
