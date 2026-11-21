import test from 'node:test';
import assert from 'node:assert/strict';
import {prepareUploadDataset, grantReadyDatasetQuery} from '../../resources/js/playground/upload-dataset.js';

function transport(responses) {
    const calls = [];
    return {
        calls,
        url: path => `/ui-api/v1/${path}`,
        fetcher: async (path, init) => {
            calls.push({path, ...init});
            const [status, body] = responses.shift();
            return new Response(JSON.stringify(body), {status});
        },
    };
}

test('new upload dataset redeems only its one-time bootstrap capability', async () => {
    const wire = transport([[201, {success: true, grant_token: 'one-use-dataset-capability'}], [200, {success: true}]]);
    await prepareUploadDataset('uploaded-files', wire);
    assert.equal(wire.calls[0].path, '/ui-api/v1/datasets');
    assert.deepEqual(JSON.parse(wire.calls[0].body), {dataset_id: 'uploaded-files'});
    assert.equal(wire.calls[1].path, '/ui-api/v1/datasets/uploaded-files/ingest-grants/self');
    assert.deepEqual(JSON.parse(wire.calls[1].body), {grant_token: 'one-use-dataset-capability'});
    assert.ok(wire.calls.every(call => !call.headers.Authorization));
});

test('existing upload dataset reuses its exact ingest grant without inventing a capability', async () => {
    const wire = transport([[200, {success: true}], [200, {success: true}]]);
    await prepareUploadDataset('existing', wire);
    assert.deepEqual(JSON.parse(wire.calls[1].body), {});
});

test('existing dataset without an ingest grant stays denied', async () => {
    const wire = transport([[200, {success: true}], [400, {success: false, message: 'Ingest grant token required.'}]]);
    await assert.rejects(prepareUploadDataset('ungranted', wire), /Ingest grant token required/);
    assert.equal(wire.calls.length, 2);
});

test('failed creation stops preparation before requesting a grant', async () => {
    const wire = transport([[409, {success: false, message: 'Dataset identity is reserved.'}]]);
    await assert.rejects(prepareUploadDataset('reserved', wire), /identity is reserved/);
    assert.equal(wire.calls.length, 1);
});

test('proxy outages produce an HTTP error without trying to redeem a grant', async () => {
    let calls = 0;
    await assert.rejects(prepareUploadDataset('uploads', {
        url: path => path,
        fetcher: async () => { calls++; return new Response('<html>Unavailable</html>', {status: 502}); },
    }), /Dataset preparation failed \(502\)/);
    assert.equal(calls, 1);
});

test('query access waits for completed ingestion and uses the canonical grant API', async () => {
    const wire = transport([[200, {success: true}]]);
    for (const status of ['running', 'failed', 'cancelled']) {
        assert.equal(await grantReadyDatasetQuery({status, dataset_id: 'uploads'}, wire), false);
    }
    assert.equal(wire.calls.length, 0);
    assert.equal(await grantReadyDatasetQuery({status: 'completed', dataset_id: 'uploads'}, wire), true);
    assert.equal(wire.calls[0].path, '/ui-api/v1/datasets/uploads/query-grants/self');
    assert.deepEqual(JSON.parse(wire.calls[0].body), {});
});

test('failed query grants remain errors so polling can retry', async () => {
    const wire = transport([[409, {success: false, message: 'Dataset is not ready.'}]]);
    await assert.rejects(grantReadyDatasetQuery({status: 'completed', dataset_id: 'uploads'}, wire), /not ready/);
});
