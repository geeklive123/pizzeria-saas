// All application responses come from the local Laravel server. Only non-local
// network requests are aborted; no application response is intercepted.
const { chromium } = require('../../tmp/sprint33-playwright-local/node_modules/playwright-core');
const fs = require('node:fs/promises');
const path = require('node:path');
const assert = require('node:assert/strict');

async function main() {
    const dir = process.env.ONLINE_QR_DEMO_DIR;
    if (!dir || !/^online-qr-demo-[a-f0-9]{16}$/.test(path.basename(dir))) throw Error('Invalid demo directory');
    const info = JSON.parse(await fs.readFile(path.join(dir, 'info.json'), 'utf8'));
    const base = 'http://127.0.0.1:18765';
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const mobile = process.env.ONLINE_QR_DEMO_MOBILE === '1';
    const context = await browser.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 900 }, serviceWorkers: 'block' });
    await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const page = await context.newPage();
    const applicationRequests = [];
    page.on('request', request => { if (request.method() === 'POST') applicationRequests.push(new URL(request.url()).pathname); });
    page.on('pageerror', error => console.error('PAGE ERROR:', error.message));
    page.on('response', response => { if (response.status() >= 400) console.error('HTTP', response.status(), new URL(response.url()).pathname); });
    const api = async (method, endpoint) => {
        const response = await context.request.fetch(base + endpoint, { method, headers: { 'X-Demo-Token': info.control_token } });
        assert.equal(response.status(), 200, `${endpoint}: ${response.status()}`);
        return response.json();
    };
    try {
        assert.equal((await context.request.get(base + '/__demo/state')).status(), 403);
        assert.equal((await context.request.post(base + '/__demo/paid')).status(), 403);
        await page.goto(base + '/tienda/menu');
        await page.locator('[data-add-product]:not([disabled])').first().click();
        await page.goto(base + '/tienda/checkout');
        await page.locator('input[value="delivery"]').check();
        await page.locator('[name="customer_name"]').fill('Cliente ficticio');
        await page.locator('[name="customer_phone"]').fill('70000000');
        await page.locator('[name="address"]').fill('Calle de prueba 123');
        await page.locator('[name="reference"]').fill('Portón ficticio');
        const map = page.locator('[data-delivery-map]');
        const box = await map.boundingBox();
        await map.click({ position: { x: box.width / 2 + 40, y: box.height / 2 + 30 } });
        await page.locator('[data-delivery-confirm]:not([disabled])').waitFor();
        await page.locator('[data-delivery-confirm]').click();
        await page.locator('[data-submit-order]:not([disabled])').click();
        await page.locator('[data-online-qr-image]:not([hidden])').waitFor({ timeout: 30000 });
        assert.match(await page.locator('[data-online-qr-status]').innerText(), /Bs 12\.00.*Esperando/);
        assert.match(await page.locator('[data-online-qr-image]').getAttribute('src'), /^data:image\/png;base64,/);
        await page.screenshot({ path: path.join(dir, 'checkout-qr-laravel.png'), fullPage: true });
        const pending = await api('GET', '/__demo/state');
        assert.equal(pending.intent.status, 'pending');
        assert.ok(pending.intent.bank_qr_id.startsWith('demo-'));
        assert.equal(pending.intent.has_image, true);
        assert.equal(pending.orders, 1);
        assert.equal(pending.intents, 1);
        assert.equal(pending.payments, 0);
        assert.equal(pending.reservations[0].status, 'reserved');
        await fs.writeFile(path.join(dir, 'stop-online-emissions'), 'disabled for demo');
        applicationRequests.length = 0;
        await page.reload();
        await page.locator('[data-online-qr-check]:not([hidden])').waitFor();
        await page.locator('[data-online-qr-check]').click();
        await page.getByText('Esperando confirmación bancaria.').waitFor();
        const afterReload = await api('GET', '/__demo/state');
        assert.equal(afterReload.intents, 1);
        assert.equal(afterReload.intent.bank_qr_id, pending.intent.bank_qr_id);
        assert.equal(applicationRequests.some(url => url.endsWith('/preparacion/qr') || url.endsWith('/preparacion/orden')), false);
        await page.screenshot({ path: path.join(dir, 'checkout-recarga-canal-detenido.png'), fullPage: true });
        await api('POST', '/__demo/paid');
        await api('POST', '/__demo/reconcile');
        await page.locator('[data-online-qr-check]').click();
        await page.getByText('Pago confirmado').waitFor({ timeout: 30000 });
        await page.screenshot({ path: path.join(dir, 'checkout-pago-confirmado.png'), fullPage: true });
        await page.locator('[data-online-qr-check]').click();
        await page.waitForTimeout(500);
        const paid = await api('GET', '/__demo/state');
        assert.equal(paid.intent.status, 'paid');
        assert.equal(paid.orders, 1);
        assert.equal(paid.intents, 1);
        assert.equal(paid.payments, 1);
        assert.equal(paid.consumptions, 1);
        assert.equal(paid.reservations[0].status, 'consumed');
        assert.equal(paid.prints, 2);
        await page.goto(base + '/login');
        await page.locator('[name="email"]').fill('demo@example.test');
        await page.locator('[name="password"]').fill('Demo-local-12345');
        await page.locator('button[type="submit"]').click();
        await page.goto(base + '/online-orders/' + paid.request.id);
        assert.equal((await page.locator('body').innerText()).includes('Cliente ficticio'), true);
        assert.match(await page.locator('body').innerText(), /No hay una incidencia bancaria pendiente/);
        await page.screenshot({ path: path.join(dir, 'administracion-pedido.png'), fullPage: true });
        console.log(JSON.stringify({ pending, paid, admin_url: page.url(), screenshots: dir }));
    } finally {
        await context.close();
        await browser.close();
    }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
