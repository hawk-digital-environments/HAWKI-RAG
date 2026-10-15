import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';
import { once } from 'node:events';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { createRequire } from 'node:module';
import test from 'node:test';

// Build: npm run build -- --outDir storage/framework/testing/managed-ui-build
// Run with RUN_MANAGED_UI_TESTS=1, RAWKI_UI_PLAYWRIGHT_PACKAGE pointing to an
// existing Playwright JS package, and optionally RAWKI_UI_CHROME to a Chrome executable.
test('public management deletion preserves evidence until the signed scoped cleanup receipt is verified', {
    skip: process.env.RUN_MANAGED_UI_TESTS !== '1' && 'Opt-in: requires built Vite assets and RAWKI_UI_PLAYWRIGHT_PACKAGE.',
    timeout: 60000,
}, async () => {
    const { chromium } = createRequire(import.meta.url)(process.env.RAWKI_UI_PLAYWRIGHT_PACKAGE);
    const listener = createServer();
    listener.listen(0, '127.0.0.1');
    await once(listener, 'listening');
    const port = listener.address().port;
    await new Promise((done) => listener.close(done));
    const origin = `http://127.0.0.1:${port}`;
    const root = mkdtempSync(resolve(tmpdir(), 'rawki-ui-deletion-'));
    const router = resolve('tests/Support/managed_deletion_ui.php');
    const env = { ...process.env, RAWKI_UI_DELETION_ROOT: root };
    const php = (mode) => {
        const result = spawnSync('php', [router, mode], { env, encoding: 'utf8' });
        assert.equal(result.status, 0, result.stderr);
        return result.stdout;
    };
    let browser;
    let server;
    try {
        writeFileSync(`${root}/phase`, 'lost-ack');
        php('seed');
        server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', router], { env, stdio: 'ignore' });
        for (let attempt = 0; attempt < 50; attempt++) {
            try {
                const response = await fetch(`${origin}/api/documents`);
                if (response.ok) break;
            } catch {}
            await new Promise((done) => setTimeout(done, 100));
        }
        browser = await chromium.launch({ headless: true, ...(process.env.RAWKI_UI_CHROME ? { executablePath: process.env.RAWKI_UI_CHROME } : {}) });
        const page = await browser.newPage();
        const failures = [];
        page.on('pageerror', (error) => failures.push(error.message));
        page.on('response', (response) => {
            if (response.status() >= 400) failures.push(`${response.status()} ${new URL(response.url()).pathname}`);
        });
        // The deployment health probe is outside this regression; document HTTP routes stay real.
        await page.route('**/api/health/system-gate?*', (route) => route.fulfill({ json: { success: true, enforce: false } }));
        page.on('dialog', (dialog) => dialog.accept());
        await page.goto(`${origin}/datasets?dataset_id=owned`);
        const status = page.locator('#datasets-status');
        const row = () => page.locator('#datasets-documents tbody tr').filter({ hasText: 'owned.pdf' });
        try {
            await row().waitFor({ state: 'visible', timeout: 10000 });
        } catch (error) {
            assert.fail(`${error.message}\n${JSON.stringify(failures)}\n${await page.locator('body').innerText()}`);
        }
        await row().getByRole('button', { name: /^Delete$/ }).click();
        await page.waitForFunction(() => document.querySelector('#datasets-status')?.dataset.tone === 'error');
        const retained = JSON.parse(php('state'));
        assert.equal(retained.operations.length, 1);
        assert.equal(retained.outputs.find((output) => output.document_id === 'adoc_ui_owned').active, true);
        assert.equal(retained.documents.find((document) => document.document_id === 'adoc_ui_owned').deleted_at, null);

        writeFileSync(`${root}/phase`, 'pending');
        await Promise.all([
            page.waitForResponse((response) => response.url().endsWith('/api/documents/adoc_ui_owned') && response.request().method() === 'DELETE' && response.status() === 202),
            row().getByRole('button', { name: /Delete|Retry deletion/ }).click(),
        ]);
        await page.waitForFunction(() => document.querySelector('#datasets-status')?.dataset.tone === 'warn'
            && document.querySelector('#datasets-status')?.textContent.startsWith('Deletion of'));
        assert.match(await status.textContent(), /verification|verified|cleanup/i);
        assert.equal(await row().count(), 1);
        const calls = readFileSync(`${root}/dispatches.jsonl`, 'utf8').trim().split('\n').map(JSON.parse);
        assert.equal(calls.length, 2);
        assert.deepEqual(calls[0].workflow_input, calls[1].workflow_input);
        const input = calls[0].workflow_input;
        assert.equal(input.dataset_id, 'owned');
        assert.equal(input.targets.length, 1);
        const target = input.targets[0];
        assert.equal(target.source_id, 'source-owned');
        assert.equal(target.collection, 'historical_collection');
        assert.equal(target.neo4j_namespace, 'historical_namespace');
        await page.goto(`${origin}/pipeline-controller`);
        const ownedTaskRow = () => page.locator('.pipeline-run-row').filter({ hasText: 'task-owned' });
        await ownedTaskRow().getByRole('button', { name: 'Delete', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('#pipeline-task-note')?.textContent.includes('pending verification'));
        assert.equal(await ownedTaskRow().count(), 1);
        assert.notEqual(JSON.parse(php('state')).tasks.find((task) => task.task_id === 'task-owned').metadata?.history_hidden, true);
        await page.goto(`${origin}/datasets?dataset_id=owned`);
        await row().waitFor({ state: 'visible' });
        const receipt = { schema_version: 1, event_id: 'ui-receipt', operation_id: input.operation_id,
            workflow_id: calls[0].workflow_id, run_id: 'ui-delete-run', status: 'completed', results: [{
                output_id: target.output_id,
                qdrant: { verified: true, remaining_points: 1, doc_id: target.doc_id, source_id: target.source_id, collection: target.collection, dataset_id: input.dataset_id },
                neo4j: { verified: true, required: true, remaining_contributions: 0, doc_id: target.doc_id, namespace: target.neo4j_namespace, dataset_id: input.dataset_id },
            }] };
        const callback = async () => {
            const body = JSON.stringify(receipt);
            const time = String(Math.floor(Date.now() / 1000));
            return fetch(`${origin}/api/internal/pipeline/managed-deletion-events`, { method: 'POST', body, headers: {
                'Content-Type': 'application/json', Accept: 'application/json', 'X-Hawki-Timestamp': time,
                'X-Hawki-Signature': `v1=${createHmac('sha256', 'ui-test-secret').update(`${time}.${body}`).digest('hex')}`,
            } });
        };
        assert.equal((await callback()).status, 409);
        receipt.results[0].qdrant.remaining_points = 0;
        receipt.results[0].neo4j.remaining_contributions = 1;
        assert.equal((await callback()).status, 409);
        receipt.status = 'failed';
        assert.equal((await callback()).status, 200);
        await row().getByRole('button', { name: /Delete|Retry deletion/ }).click();
        await page.waitForFunction(() => document.querySelector('#datasets-status')?.dataset.tone === 'error');
        const failed = JSON.parse(php('state'));
        assert.equal(failed.outputs.find((output) => output.document_id === 'adoc_ui_owned').active, true);
        assert.equal(failed.sources.length, 2);
        assert.equal(failed.operations[0].operation_id, input.operation_id);

        receipt.status = 'completed';
        receipt.results[0].neo4j.remaining_contributions = 0;
        assert.equal((await callback()).status, 200);
        await row().getByRole('button', { name: /Delete|Retry deletion/ }).click();
        await page.waitForFunction(() => document.querySelector('#datasets-status')?.dataset.tone === 'success');
        assert.equal(await row().count(), 0);
        const completed = JSON.parse(php('state'));
        assert.equal(completed.outputs.find((output) => output.document_id === 'adoc_ui_owned').active, false);
        assert.equal(completed.outputs.find((output) => output.document_id === 'adoc_ui_foreign').active, true);
        assert.equal(completed.documents.find((document) => document.document_id === 'adoc_ui_foreign').deleted_at, null);

        const dispatchCount = readFileSync(`${root}/dispatches.jsonl`, 'utf8').trim().split('\n').length;
        await page.goto(`${origin}/pipeline-controller`);
        const taskRow = page.locator('.pipeline-run-row').filter({ hasText: 'task-foreign' });
        await taskRow.getByRole('button', { name: 'Delete', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('#pipeline-task-note')?.textContent.includes('pending verification'));
        assert.equal(await taskRow.count(), 1);
        assert.notEqual(JSON.parse(php('state')).tasks.find((task) => task.task_id === 'task-foreign').metadata?.history_hidden, true);
        assert.equal(readFileSync(`${root}/dispatches.jsonl`, 'utf8').trim().split('\n').length, dispatchCount + 1);
        await ownedTaskRow().getByRole('button', { name: 'Delete', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('#pipeline-task-note')?.textContent.includes('Deleted indexed documents and removed task task-owned'));
        assert.equal(await ownedTaskRow().count(), 0);
        const hidden = JSON.parse(php('state'));
        assert.equal(hidden.tasks.find((task) => task.task_id === 'task-owned').metadata?.history_hidden, true);
        assert.equal(hidden.outputs.find((output) => output.document_id === 'adoc_ui_foreign').active, true);
        assert.equal(hidden.sources.length, 2);
        const foreignCall = readFileSync(`${root}/dispatches.jsonl`, 'utf8').trim().split('\n').map(JSON.parse)
            .find((call) => call.workflow_input.dataset_id === 'foreign');
        const foreignInput = foreignCall.workflow_input;
        Object.assign(receipt, { operation_id: foreignInput.operation_id, workflow_id: foreignCall.workflow_id,
            results: foreignInput.targets.map((item) => ({ output_id: item.output_id,
                qdrant: { verified: true, remaining_points: 0, doc_id: item.doc_id, source_id: item.source_id,
                    collection: item.collection, dataset_id: foreignInput.dataset_id },
                neo4j: { verified: true, required: true, remaining_contributions: 0, doc_id: item.doc_id,
                    namespace: item.neo4j_namespace, dataset_id: foreignInput.dataset_id },
            })) });
        assert.equal((await callback()).status, 200);
        // The original Delete action must hide history after proof, without a second click.
        await taskRow.waitFor({ state: 'hidden', timeout: 10000 });
        assert.equal(JSON.parse(php('state')).tasks.find((task) => task.task_id === 'task-foreign').metadata.history_hidden, true);
        await page.reload();
        assert.equal(await ownedTaskRow().count(), 0);
        assert.equal(await taskRow.count(), 0);
    } finally {
        await browser?.close();
        if (server && server.exitCode === null && server.signalCode === null) {
            server.kill();
            await once(server, 'exit');
        }
        rmSync(root, { recursive: true, force: true });
    }
});
