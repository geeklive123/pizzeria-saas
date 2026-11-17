// Static Blade fixture and browser-only responses; every request is intercepted.
// This does not replace the SQLite HTTP integration test.
const { chromium } = require('../../tmp/sprint33-playwright-local/node_modules/playwright-core');
const fs = require('node:fs/promises');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '../..');
const output = path.join(root, 'tmp/sprint33-visual');
const fixture = path.join(output, 'checkout.html');
const base = 'http://localhost/tienda/checkout';
const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="260" height="260"><rect width="260" height="260" fill="white"/><rect x="15" y="15" width="230" height="230" fill="none" stroke="black" stroke-width="10"/><text x="130" y="115" text-anchor="middle" font-size="23">QR FICTICIO</text><text x="130" y="145" text-anchor="middle" font-size="18">NO PAGABLE</text></svg>';
const image = 'data:image/svg+xml;base64,' + Buffer.from(svg).toString('base64');

async function main() {
    const html = await fs.readFile(fixture, 'utf8');
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const results = [];
    try {
        for (const [name, viewport] of [['desktop', { width: 1440, height: 900 }], ['mobile', { width: 390, height: 844 }]]) {
            const state = { open: true, coverage: 'free', bank: 'pending', delay: false };
            const context = await browser.newContext({ viewport, serviceWorkers: 'block' });
            await context.addInitScript(() => localStorage.setItem('masa-y-mana-storefront-cart-v1', JSON.stringify([
                { variantUlid: 'visual-variant', productName: 'Pizza de prueba', variantName: 'Familiar', price: '12.00', quantity: 2 },
            ])));
            await context.route('**/*', async route => {
                const request = route.request();
                const url = new URL(request.url());
                const json = data => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(data) });
                if (request.isNavigationRequest() && url.pathname === '/tienda/checkout') return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
                if (url.pathname.startsWith('/build/assets/')) {
                    const file = path.join(root, 'public', url.pathname.slice(1));
                    try { return route.fulfill({ status: 200, body: await fs.readFile(file), contentType: file.endsWith('.css') ? 'text/css' : 'text/javascript' }); } catch { return route.abort(); }
                }
                if (url.pathname.endsWith('/disponibilidad')) return json({ open: state.open });
                if (url.pathname.endsWith('/cobertura')) return json({ coverage: state.coverage, distance_m: state.coverage === 'free' ? 200 : 1200 });
                if (url.pathname.endsWith('/buscar')) return json({ results: [] });
                if (url.pathname.endsWith('/preparacion')) return json({ created: true, ready: true, subtotal: '24.00' });
                if (url.pathname.endsWith('/preparacion/orden')) return json({ ready: true });
                if (url.pathname.endsWith('/preparacion/qr')) {
                    if (state.delay) await new Promise(resolve => setTimeout(resolve, 700));
                    return json(state.bank === 'timeout' ? { status: 'generation_unknown' } : { status: 'pending', qr_image: image, amount: '24.00' });
                }
                if (url.pathname.endsWith('/preparacion/qr/estado')) return json({ status: state.bank });
                // Offline tile, intentionally not a real geographic map or network request.
                if (url.hostname === 'tile.openstreetmap.org') return route.fulfill({ status: 200, contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"><rect width="256" height="256" fill="#eee9df"/><path d="M0 128H256 M128 0V256" stroke="#d6cfc2"/></svg>' });
                return route.abort();
            });
            const page = await context.newPage();
            const capture = async label => {
                await page.screenshot({ path: path.join(output, `${name}-${label}.png`), fullPage: true });
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
                results.push({ viewport: name, state: label, horizontalOverflow: overflow });
            };
            await page.goto(base);
            await page.waitForSelector('[data-submit-order]');
            await capture('carrito');
            await page.locator('input[value="delivery"]').check();
            await page.locator('[name="customer_name"]').fill('Cliente de prueba');
            await page.locator('[name="customer_phone"]').fill('70000000');
            await page.locator('[name="address"]').fill('Calle de prueba 123');
            await page.locator('[name="reference"]').fill('Portón de prueba');
            await page.locator('[data-delivery-map]').click({ position: { x: 60, y: 60 } });
            await page.locator('[data-delivery-confirm]:not([disabled])').waitFor();
            await capture('mapa-dentro');
            state.coverage = 'outside';
            await page.locator('[data-delivery-map]').click({ position: { x: 90, y: 90 } });
            await page.locator('[data-delivery-cost-warning]:not([hidden])').waitFor();
            await capture('fuera-cobertura');
            state.coverage = 'free';
            await page.locator('[data-delivery-map]').click({ position: { x: 60, y: 60 } });
            await page.locator('[data-delivery-confirm]:not([disabled])').waitFor();
            await page.locator('[data-delivery-confirm]').click();
            state.open = false;
            await page.evaluate(() => window.dispatchEvent(new Event('focus')));
            await page.locator('[data-cash-warning]:not([hidden])').waitFor();
            await capture('caja-cerrada');
            state.open = true;
            await page.evaluate(() => window.dispatchEvent(new Event('focus')));
            await page.locator('[data-submit-order]:not([disabled])').waitFor();
            state.delay = true;
            await page.locator('[data-submit-order]').click();
            await page.getByText('Generando tu QR').waitFor();
            await capture('generando');
            await page.locator('[data-online-qr-image]:not([hidden])').waitFor();
            assert.match(await page.locator('[data-preparation-note]').innerText(), /confirmación bancaria/);
            assert.match(await page.locator('[data-checkout-legal-note]').innerText(), /reserva inventario/);
            await capture('qr-espera');
            state.bank = 'cancelled';
            await page.locator('[data-online-qr-check]').click();
            await page.getByText('El pago requiere revisión. Contacta al restaurante.').waitFor();
            await capture('revision');
            // A late confirmation after cancellation is shown as an incident,
            // matching the real reconciliation result; it is not shown as paid.
            state.bank = 'reconciliation_required';
            await page.locator('[data-online-qr-check]').click();
            await page.getByText('El pago requiere revisión. Contacta al restaurante.').waitFor();
            await capture('confirmacion-tardia-revision');
            // Independent simulated UI state: normal, timely payment.
            state.bank = 'paid';
            await page.locator('[data-online-qr-check]').click();
            await page.getByText('Pago confirmado').waitFor();
            await capture('confirmado');
            await page.evaluate(() => localStorage.removeItem('masa-y-mana-delivery-preparation-v1'));
            state.bank = 'timeout';
            await page.locator('[data-submit-order]').click();
            await page.getByText('Error temporal recuperable. Vuelve a intentarlo o consulta al restaurante.').waitFor();
            await capture('timeout');
            assert.equal(results.filter(item => item.viewport === name && item.horizontalOverflow).length, 0, `${name}: desbordamiento horizontal`);
            await context.close();
        }
    } finally { await browser.close(); }
    await fs.writeFile(path.join(output, 'resultados.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results));
}
main().catch(error => { console.error(error); process.exitCode = 1; });
