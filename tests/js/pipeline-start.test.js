import test from 'node:test';
import assert from 'node:assert/strict';
import { createPipelineStarter } from '../../resources/js/playground/pipeline-start.js';

test('launcher freezes key/body/task across a lost response and resolves one submission', async () => {
    const calls = [];
    const ledger = new Map();
    let dispatches = 0;
    let identities = 0;
    let lost = true;
    const start = createPipelineStarter({
        url: '/api/pipeline/tasks/start',
        csrfToken: () => 'csrf',
        key: () => `key-${++identities}`,
        payload: task => ({ task_id: `task-${identities}`, urls: task.urls }),
        fetcher: async (url, options) => {
            calls.push(options);
            const key = options.headers['Idempotency-Key'];
            if (!ledger.has(key)) {
                dispatches++;
                ledger.set(key, JSON.parse(options.body).task_id);
            }
            if (lost) { lost = false; throw new Error('response lost'); }
            return { ok: true, json: async () => ({ success: true, task_id: ledger.get(key) }) };
        },
    });
    const task = { id: 'catalog-1', urls: ['https://example.org/a'] };
    await assert.rejects(start(task), /response lost/);
    task.urls.push('https://example.org/changed');
    const result = await start(task);
    assert.equal(result.task_id, 'task-1');
    assert.equal(calls[0].body, calls[1].body);
    assert.equal(calls[0].headers['Idempotency-Key'], calls[1].headers['Idempotency-Key']);
    assert.equal(dispatches, 1);
    const next = await start(task);
    assert.equal(next.task_id, 'task-2');
    assert.notEqual(calls[1].headers['Idempotency-Key'], calls[2].headers['Idempotency-Key']);
    assert.equal(dispatches, 2);
});

for (const outcome of ['network', 'timeout', 400, 401, 403, 404, 409, 413, 422, 500, 503]) {
    test(`launcher retains uncertain submission after ${outcome}`, async () => {
        const calls = [];
        let keys = 0;
        let rejected = false;
        const start = createPipelineStarter({
            url: '/api/pipeline/tasks/start', csrfToken: () => 'csrf', key: () => `key-${++keys}`,
            payload: task => ({ task_id: `task-${keys}`, url: task.url }),
            fetcher: async (url, options) => {
                calls.push(options);
                if (!rejected) {
                    rejected = true;
                    if (typeof outcome === 'string') throw new Error(outcome);
                    return { ok: false, status: outcome, json: async () => ({ success: false }) };
                }
                return { ok: true, json: async () => ({ success: true, task_id: 'accepted' }) };
            },
        });
        const task = { id: 'one', url: 'https://example.org/a' };
        await assert.rejects(start(task));
        task.url = 'https://example.org/changed';
        await start(task);
        assert.equal(keys, 1);
        assert.equal(calls[0].body, calls[1].body);
        assert.equal(calls[0].headers['Idempotency-Key'], calls[1].headers['Idempotency-Key']);
        await start(task);
        assert.equal(keys, 2);
    });
}
