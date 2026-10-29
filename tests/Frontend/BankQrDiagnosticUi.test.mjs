import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/bank-qr-diagnostic.js', import.meta.url), 'utf8');

test('copies the exact original text, including long content and line endings', async () => {
    const original = 'data:image/png;base64,'.concat('A'.repeat(100000), '\r\nXYZ');
    const textarea = { dataset: { originalJson: JSON.stringify(original) }, value: original.replace('\r\n', '\n') };
    const status = { textContent: '' };
    const section = { querySelector: (selector) => selector.includes('original') ? textarea : status };
    const button = { closest: () => section, addEventListener: (_, callback) => { button.click = callback; } };
    let copied;
    runInNewContext(source, {
        document: { querySelectorAll: () => [button] },
        navigator: { clipboard: { writeText: async (value) => { copied = value; } } },
        window: { addEventListener: () => {} },
        JSON,
    });
    await button.click();
    assert.equal(copied, original);
    assert.equal(status.textContent, 'Base64 completo copiado.');
});
