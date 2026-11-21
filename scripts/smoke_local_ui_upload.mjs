// Real frontend preparation and FormData through the local nginx boundary.
// Run with node scripts/smoke_local_ui_upload.mjs. No service credential is read.
import assert from 'node:assert/strict';
import {writeFile} from 'node:fs/promises';
import {apiFetch} from '../resources/js/api.js';
import {prepareUploadDataset, grantReadyDatasetQuery} from '../resources/js/playground/upload-dataset.js';

const origin = 'http://localhost:8080';
const base = `${origin}/ui-api/v1/`;
const dataset = `ui-upload-${crypto.randomUUID().replaceAll('-', '').slice(0, 12)}`;
const reportPath = process.env.RAWKI_UI_SMOKE_REPORT || '/tmp/rawki-ui-routes-upload-smoke.json';
const withGraph = process.env.RAWKI_UI_SMOKE_GRAPH !== 'false';
const content = `# Project Cedar acceptance fixture

Mira Vale leads Project Cedar at Aurora Institute. Aurora Institute operates the
Cedar Sensor in Berlin. Project Cedar uses Atlas Protocol to collect measurements.
The Cedar Sensor sends measurements to Atlas Gateway. Noah Reed maintains Atlas
Gateway and works with Mira Vale at Aurora Institute. Atlas Gateway stores
temperature measurements from Cedar Sensor. Project Cedar studies temperature
measurements to improve greenhouse irrigation. Aurora Institute owns the
greenhouse. Noah Reed designed Atlas Protocol for Project Cedar. Mira Vale
reviews the measurements and reports results to Aurora Institute every week.
`;
const report = {dataset_id: dataset, status: 'RUNNING', checks: {}};
const transport = {
    url: path => base + path,
    fetcher: (url, init = {}) => apiFetch(url, {
        ...init, headers: {...init.headers, Origin: origin}, signal: AbortSignal.timeout(240000),
    }),
};
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const save = () => writeFile(reportPath, JSON.stringify(report, null, 2) + '\n');
let prepared = false;
let documentId;

async function request(method, path, init = {}) {
    const response = await transport.fetcher(base + path, {...init, method});
    assert.ok([200, 201, 202].includes(response.status), `${method} ${path}: HTTP ${response.status}`);
    assert.equal(response.headers.has('www-authenticate'), false);
    assert.equal(response.headers.has('set-cookie'), false);
    return {response, body: await response.json()};
}

async function retire(path) {
    const deadline = Date.now() + 240000;
    while (Date.now() < deadline) {
        const {response} = await request('DELETE', path, {headers: {'Idempotency-Key': `${dataset}-${path}`}});
        if (response.status === 200) return;
        await pause(2000);
    }
    throw new Error(`Retirement pending: ${path}`);
}

try {
    // Compose service_started precedes ASGI startup. Wait using reads before
    // creating anything so deployment warmup cannot strand test ownership.
    let available = false;
    for (let attempt = 0; attempt < 30; attempt++) {
        try {
            const response = await transport.fetcher(`${base}pipeline/tasks?limit=1`);
            if (response.ok && (await response.json()).success) { available = true; break; }
        } catch { /* Retry a startup connection failure within the bounded wait. */ }
        await pause(1000);
    }
    assert.ok(available, 'UI boundary did not become ready');
    // Mark the unique dataset for cleanup even if capability redemption fails.
    prepared = true;
    await prepareUploadDataset(dataset, transport);
    report.checks.frontend_dataset_preparation = true;
    const form = new FormData();
    form.set('dataset_id', dataset);
    form.set('graph', String(withGraph));
    form.set('converter_mode', 'native');
    form.set('file', new File([content], 'cedar.md', {type: 'text/markdown'}));
    const admission = await request('POST', 'documents', {
        headers: {Accept: 'application/json', 'Idempotency-Key': `${dataset}-upload`}, body: form,
    });
    documentId = admission.body.document.document_id;
    report.admission = {status: admission.response.status, body: admission.body};
    await save();
    const taskId = admission.body.pipeline.task_id;
    const deadline = Date.now() + 900000;
    let task;
    while (Date.now() < deadline) {
        task = (await request('GET', `pipeline/tasks/${taskId}`)).body.task;
        assert.ok(!['failed', 'cancelled'].includes(task.status), `Task ended ${task.status}`);
        if (task.status === 'completed') break;
        await pause(3000);
    }
    assert.equal(task.status, 'completed');
    assert.ok(task.jobs.every(job => job.index_status === 'ready'));
    report.checks.converter_indexer_ready = true;
    report.stage_logs = {};
    for (const stage of ['scraper', 'converter', 'ingest']) {
        const log = (await request('GET', `pipeline/tasks/${taskId}/stages/${stage}/logs`)).body.log;
        report.stage_logs[stage] = log;
        if (log.text) {
            const download = await transport.fetcher(`${base}pipeline/tasks/${taskId}/stages/${stage}/logs/download`);
            assert.equal(download.status, 200);
            assert.equal(await download.text(), log.text);
        }
    }
    assert.ok(report.stage_logs.converter.line_count + report.stage_logs.ingest.line_count > 0);
    report.checks.stage_previews_and_downloads = true;
    await grantReadyDatasetQuery(task, transport);
    const listing = (await request('GET', `documents?dataset_id=${dataset}`)).body.documents;
    assert.ok(listing.some(row => row.document_id === documentId && row.status === 'indexed'));
    report.checks.document_listing = true;
    const download = await transport.fetcher(`${base}documents/uploads/download?document_id=${documentId}`);
    assert.equal(download.status, 200);
    assert.equal(await download.text(), content);
    report.checks.byte_identical_download = true;
    const graph = (await request('GET', `ops/graph/overview?dataset_id=${dataset}`)).body;
    if (withGraph) assert.ok(graph.nodes.length && graph.edges.length);
    report.checks.graph = {nodes: graph.nodes.length, edges: graph.edges.length};
    report.status = 'PASS';
} catch (error) {
    report.status = 'FAIL';
    report.error = error.message;
    process.exitCode = 1;
} finally {
    try {
        if (documentId) {
            await retire(`documents/${documentId}`);
            const graph = (await request('GET', `ops/graph/overview?dataset_id=${dataset}`)).body;
            assert.equal(graph.nodes.length + graph.edges.length, 0);
            report.checks.graph_empty_after_delete = true;
        }
        if (prepared) await retire(`datasets/${dataset}/storage`);
        report.checks.dataset_retired = true;
    } catch (error) {
        report.status = 'FAIL';
        report.cleanup_error = error.message;
        process.exitCode = 1;
    }
    await save();
    console.log(JSON.stringify({status: report.status, dataset_id: dataset, checks: report.checks, report: reportPath}));
}
