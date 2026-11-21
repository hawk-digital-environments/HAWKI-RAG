import test from 'node:test';
import assert from 'node:assert/strict';
import {apiFetch} from '../../resources/js/api.js';
import {apiUrl} from '../../resources/js/playground/urls.js';

test('UI calls use the service boundary and canonical Python paths', () => {
    globalThis.document = {querySelector: () => ({content: '/'})};
    globalThis.window = {location: {origin: 'https://rag.test'}};
    assert.equal(apiUrl('query'), 'https://rag.test/ui-api/v1/query');
    assert.equal(apiUrl('rag/neo4j/graph/overview?limit=80'), 'https://rag.test/ui-api/v1/ops/graph/overview?limit=80');
    assert.equal(apiUrl('pipeline/controller/files'), 'https://rag.test/ui-api/v1/documents');
    assert.equal(apiUrl('documents/uploads/download'), 'https://rag.test/ui-api/v1/documents/uploads/download');
});

test('browser transport includes the CSRF marker and adapts Python errors', async () => {
    const original = globalThis.fetch;
    let seen;
    globalThis.fetch = async (url, init) => {
        seen = init;
        return new Response(JSON.stringify({detail: {error: 'dataset_not_found', message: 'Dataset unavailable.'}}), {status: 404, headers: {'content-type': 'application/json'}});
    };
    try {
        const response = await apiFetch('https://rag.test/ui-api/v1/query', {method: 'POST'});
        assert.equal(seen.headers.get('X-Hawki-Browser'), '1');
        assert.equal(seen.headers.has('Authorization'), false);
        assert.equal(seen.credentials, 'same-origin');
        const error = await response.json();
        assert.equal(response.status, 404);
        assert.equal(error.error, 'dataset_not_found');
        assert.equal(error.message, 'Dataset unavailable.');
    } finally { globalThis.fetch = original; }
});
