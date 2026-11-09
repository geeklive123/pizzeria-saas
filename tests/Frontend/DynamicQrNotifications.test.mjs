import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/dynamic-qr-notifications.js', import.meta.url), 'utf8');
const id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const notice = { id, status: 'Pago confirmado', order_number: '#000001', currency: 'BOB', amount: '40.00', order_url: '/orders/a' };
const flush = async () => { for (let i = 0; i < 5; i++) await new Promise((resolve) => setImmediate(resolve)); };

function environment() {
    const values = new Map();
    const state = { delivered: false, offline: false, failAck: false, lostAckResponse: false, lock: false, requests: [] };
    const storage = { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: (key) => values.delete(key) };
    const locks = { async request(_key, _options, callback) {
        if (state.lock) return callback(null);
        state.lock = true;
        try { return await callback({}); } finally { state.lock = false; }
    } };
    function tab() {
        const node = () => ({ children: [], dataset: {}, addEventListener() {}, append(...children) { this.children.push(...children); }, remove() {} });
        const container = node();
        container.dataset = { url: '/notices', ackUrl: '/notices/00000000000000000000000000/ack', scope: 'same-user-branch' };
        const events = new Map();
        const document = {
            hidden: false,
            querySelector: (selector) => selector === '[data-qr-notifications]' ? container : { content: 'token' },
            createElement: node,
            addEventListener(event, handler) { if (!events.has(event)) events.set(event, new Set()); events.get(event).add(handler); },
            removeEventListener(event, handler) { events.get(event)?.delete(handler); },
        };
        let poll;
        runInNewContext(source, {
            document, navigator: { locks }, localStorage: storage,
            requestAnimationFrame: (callback) => setImmediate(callback),
            setInterval: (callback) => { poll = callback; },
            fetch: async (url, options) => {
                state.requests.push({ url, options });
                if (state.offline || (options.method === 'POST' && state.failAck)) throw new Error('offline');
                if (options.method === 'GET') return { ok: true, json: async () => ({ notifications: state.delivered ? [] : [notice] }) };
                state.delivered = true;
                if (state.lostAckResponse) throw new Error('response lost');
                return { ok: true };
            },
        });
        return { container, poll: () => poll() };
    }
    return { state, tab };
}

test('shows a payment and acknowledges it only after the notice is presented', async () => {
    const env = environment();
    const tab = env.tab();
    await flush();
    assert.equal(tab.container.children.length, 1);
    const card = tab.container.children[0];
    assert.equal(card.children[0].textContent, 'Pago confirmado · Pedido #000001');
    assert.equal(card.children[1].textContent, 'Importe confirmado: BOB 40.00');
    assert.equal(card.children[2].href, '/orders/a');
    assert.equal(env.state.requests[0].options.method, 'GET');
    assert.equal(env.state.requests[1].url, `/notices/${id}/ack`);
    assert.equal(env.state.delivered, true);
});

test('a failed read or acknowledgement is retried without repeating the card', async () => {
    const env = environment();
    env.state.offline = true;
    const tab = env.tab();
    await flush();
    assert.equal(tab.container.children.length, 0);
    env.state.offline = false;
    env.state.failAck = true;
    await tab.poll();
    assert.equal(tab.container.children.length, 1);
    assert.equal(env.state.delivered, false);
    env.state.failAck = false;
    await tab.poll();
    assert.equal(tab.container.children.length, 1);
    assert.equal(env.state.delivered, true);
});

test('two tabs share one displayed notice even if the first acknowledgement fails', async () => {
    const env = environment();
    env.state.failAck = true;
    const first = env.tab();
    const second = env.tab();
    await flush();
    assert.equal(first.container.children.length + second.container.children.length, 1);
    assert.equal(env.state.delivered, false);
    env.state.failAck = false;
    await second.poll();
    await first.poll();
    assert.equal(first.container.children.length + second.container.children.length, 1);
    assert.equal(env.state.delivered, true);
});

test('a lost acknowledgement response does not create another card', async () => {
    const env = environment();
    env.state.lostAckResponse = true;
    const tab = env.tab();
    await flush();
    assert.equal(tab.container.children.length, 1);
    assert.equal(env.state.delivered, true);
    env.state.lostAckResponse = false;
    await tab.poll();
    assert.equal(tab.container.children.length, 1);
});
