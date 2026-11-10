import assert from 'node:assert/strict';
import { createHash, randomUUID } from 'node:crypto';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, relative, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const digest = (bytes) => createHash('sha256').update(bytes).digest('hex');

test('offline build excludes temporary sources and matches the committed assets', async () => {
    const work = await mkdtemp(join(tmpdir(), 'pizzeria-vite-check-'));
    const suffix = randomUUID();
    const tmpProbe = join(root, 'tmp', `build-probe-${suffix}.html`);
    const viewProbe = join(root, 'storage', 'framework', 'views', `build-probe-${suffix}.php`);
    const offline = join(work, 'offline.cjs');
    const output = join(work, 'build');
    try {
        await mkdir(dirname(tmpProbe), { recursive: true });
        await mkdir(dirname(viewProbe), { recursive: true });
        await writeFile(tmpProbe, '<div class="z-[782782]"></div>', { flag: 'wx' });
        await writeFile(viewProbe, '<div class="z-[783783]"></div>', { flag: 'wx' });
        await writeFile(offline, "const net=require('node:net'); globalThis.fetch=async()=>{throw Error('network disabled')}; net.connect=net.createConnection=()=>{throw Error('network disabled')}; net.Socket.prototype.connect=()=>{throw Error('network disabled')};");
        const run = spawnSync(process.execPath, ['--require', offline, 'node_modules/vite/bin/vite.js', 'build', '--outDir', output], {
            cwd: root, encoding: 'utf8', timeout: 60000,
        });
        assert.equal(run.status, 0, run.stderr || run.stdout || String(run.error));
        const expectedRoot = join(root, 'public', 'build');
        const expected = JSON.parse(await readFile(join(expectedRoot, 'manifest.json'), 'utf8'));
        const built = JSON.parse(await readFile(join(output, 'manifest.json'), 'utf8'));
        assert.deepEqual(built, expected);
        for (const entry of Object.values(expected)) {
            assert.equal(digest(await readFile(join(output, entry.file))), digest(await readFile(join(expectedRoot, entry.file))), entry.file);
        }
        assert.equal(digest(await readFile(join(output, 'fonts-manifest.json'))), digest(await readFile(join(expectedRoot, 'fonts-manifest.json'))));
        const css = await readFile(join(output, expected['resources/css/app.css'].file), 'utf8');
        assert.ok(!css.includes('782782') && !css.includes('783783'), 'Temporary content must not contribute utilities');
    } finally {
        await rm(tmpProbe, { force: true });
        await rm(viewProbe, { force: true });
        const relativeWork = relative(tmpdir(), work);
        if (!relativeWork.startsWith('pizzeria-vite-check-') || relativeWork.includes('..')) throw new Error('Unsafe temporary directory');
        await rm(work, { recursive: true, force: true });
    }
});
