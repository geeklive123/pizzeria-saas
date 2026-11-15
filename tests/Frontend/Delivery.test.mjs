import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = (await readFile(new URL('../../resources/js/delivery.js', import.meta.url), 'utf8'))
    .replace(/^import .*;\s*$/gm, '').replace('export function initDelivery', 'function initDelivery');
const tick = () => new Promise((resolve) => setImmediate(resolve));

function setup(coverage = 'free', searchAvailable = true) {
    const events = {};
    const element = (extra = {}) => ({ value: '', textContent: '', disabled: false, children: [], addEventListener(name, fn) { this[name] = fn; }, append(child) { this.children.push(child); }, replaceChildren() { this.children = []; }, ...extra });
    const address = element(), status = element(), confirm = element(), locate = element(), suggestions = element(), costWarning = element({ hidden: true });
    const mapElement = element();
    const panel = element({ dataset: { latitude: '-17.3769946', longitude: '-66.1771762', radius: '800', tileUrl: 'https://tile.example/{z}/{x}/{y}', searchUrl: '/search', coverageUrl: '/coverage' }, querySelector(s) { return ({ '[data-delivery-address]': address, '[data-delivery-status]': status, '[data-delivery-confirm]': confirm, '[data-delivery-geolocate]': locate, '[data-delivery-suggestions]': suggestions, '[data-delivery-map]': mapElement, '[data-delivery-cost-warning]': costWarning })[s]; } });
    const choice = element(), reference = element({ value: 'Portón' });
    const form = { selected: 'delivery', querySelector: () => ({ value: form.selected }), querySelectorAll: () => [choice], elements: { namedItem: () => reference } };
    const map = { setView() { return this; }, on(name, fn) { events[name] = fn; }, invalidateSize() {} };
    let marker;
    const layer = () => ({ addTo() { return this; }, bindPopup() { return this; } });
    const L = { map: () => map, tileLayer: layer, circle: layer, circleMarker: layer, divIcon: () => ({}), marker(point) { marker = { point, setLatLng(p) { this.point = p; }, addTo() { return this; }, on(name, fn) { events[name] = fn; }, getLatLng() { return { lat: this.point[0], lng: this.point[1] }; } }; return marker; } };
    const calls = []; let geo;
    const context = { L, document: { querySelector(s) { return s === '[data-delivery-fields]' ? panel : { content: 'csrf' }; }, createElement: () => element() }, navigator: { geolocation: { getCurrentPosition(ok, fail) { geo = { ok, fail }; } } }, AbortController, setTimeout: (fn) => { fn(); return 1; }, clearTimeout() {}, fetch: async (url, options) => { calls.push({ url, body: JSON.parse(options.body) }); return url === '/search' ? { ok: searchAvailable, json: async () => ({ results: [{ label: '<calle>', latitude: -17.38, longitude: -66.17 }] }) } : { ok: true, json: async () => ({ distance_m: 100, coverage }) }; } };
    const api = runInNewContext(`${source}\ninitDelivery(form);`, { ...context, form });
    return { api, form, address, status, confirm, locate, suggestions, costWarning, events, calls, geo: () => geo, marker: () => marker };
}

test('outside coverage displays a warning and blocks delivery, but permits pickup', async () => {
    const ui = setup('outside');
    ui.events.click({ latlng: { lat: -17.4, lng: -66.17 } }); await tick();
    assert.equal(ui.costWarning.hidden, false);
    assert.equal(ui.confirm.disabled, true);
    assert.match(ui.status.textContent, /Fuera de cobertura/);
    ui.address.value = 'Calle'; ui.confirm.click();
    assert.equal(ui.api.payload(), null);
    ui.form.selected = 'pickup';
    assert.equal(ui.api.payload().fulfillment, 'pickup');
});

test('manual point must be confirmed and editing the address invalidates it', async () => {
    const ui = setup(); ui.address.value = 'Calle 1'; ui.events.click({ latlng: { lat: -17.38, lng: -66.17 } }); await tick();
    assert.equal(ui.api.payload(), null); ui.confirm.click(); assert.equal(ui.api.payload().fulfillment, 'delivery');
    ui.address.value = 'Calle 2'; ui.address.input(); assert.equal(ui.api.payload(), null);
    ui.confirm.click(); ui.marker().point = [-17.39, -66.18]; ui.events.dragend(); await tick(); assert.equal(ui.api.payload(), null);
});

test('Photon suggestion uses text content and selects its coordinates', async () => {
    const ui = setup(); ui.address.value = 'calle'; ui.address.input(); await tick();
    assert.equal(ui.calls[0].url, '/search'); const button = ui.suggestions.children[0].children[0];
    assert.equal(button.textContent, '<calle>'); button.click(); await tick(); ui.confirm.click();
    assert.equal(ui.api.payload().latitude, -17.38); assert.equal(ui.api.payload().longitude, -66.17);
});

test('unavailable Photon leaves manual map selection usable', async () => {
    const ui = setup('free', false); ui.address.value = 'Calle'; ui.address.input(); await tick();
    assert.match(ui.status.textContent, /Búsqueda no disponible/);
    ui.events.click({ latlng: { lat: -17.38, lng: -66.17 } }); await tick(); ui.confirm.click();
    assert.equal(ui.api.payload().latitude, -17.38);
});

test('browser location can be selected and denial keeps manual selection available', async () => {
    const ui = setup(); ui.address.value = 'Calle'; ui.locate.click(); ui.geo().fail();
    assert.match(ui.status.textContent, /mapa/); ui.events.click({ latlng: { lat: -17.38, lng: -66.17 } }); await tick(); ui.confirm.click(); assert.equal(ui.api.payload().latitude, -17.38);
    ui.locate.click(); ui.geo().ok({ coords: { latitude: -17.4, longitude: -66.2 } }); await tick(); assert.equal(ui.api.payload(), null); ui.confirm.click(); assert.equal(ui.api.payload().latitude, -17.4);
});
