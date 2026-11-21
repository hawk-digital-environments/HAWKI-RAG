import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/playground/graph-visualization.js', import.meta.url), 'utf8')
    .replace(/^import .*;\n/gm, '');

function workspace(fetcher = async () => ({ok: true, json: async () => ({ok: true})})) {
    const controls = new Map(['graph-semantic-dataset', 'graph-search-input', 'graph-semantic-input', 'graph-overview-btn', 'graph-snapshot-save-btn', 'graph-snapshot-load']
        .map(id => [id, {value: '', disabled: false, addEventListener() {}}]));
    const intervals = new Set();
    const context = vm.createContext({
        document: {getElementById: id => controls.get(id) || null, querySelector: () => null},
        window: {location: {origin: 'http://test'}, localStorage: {setItem() {}}},
        cytoscape: {use() {}}, elk: {}, coseBilkent: {}, URL,
        fetch: fetcher, setInterval: callback => {intervals.add(callback); return callback;},
        clearInterval: callback => intervals.delete(callback), setTimeout, clearTimeout,
    });
    vm.runInContext(source, context);
    return {controls, intervals, run: code => vm.runInContext(code, context)};
}

test('no selected dataset disables graph controls and prevents overview polling requests', async () => {
    let requests = 0;
    const app = workspace(async () => {requests++;});
    app.run('datasetChanged()');
    await app.run('loadOverview()');
    for (const [id, control] of app.controls) {
        if (id !== 'graph-semantic-dataset') assert.equal(control.disabled, true, id);
    }
    assert.equal(app.intervals.size, 0);
    assert.equal(requests, 0);
});

test('graph reads and mutations always carry the selected dataset', async () => {
    const calls = [];
    const app = workspace(async (url, options) => {
        calls.push({url, options});
        return {ok: true, json: async () => ({ok: true})};
    });
    app.controls.get('graph-semantic-dataset').value = 'dataset-a';
    await app.run("requestJson('http://test/ui-api/v1/ops/graph/overview?limit=80')");
    await app.run("requestJson('http://test/ui-api/v1/ops/graph/expand', {method:'POST', body:JSON.stringify({node_id:'stable'})})");
    assert.equal(new URL(calls[0].url).searchParams.get('dataset_id'), 'dataset-a');
    assert.equal(JSON.parse(calls[1].options.body).dataset_id, 'dataset-a');
});

test('a response arriving after a dataset switch cannot enter the graph scene', async () => {
    let complete;
    const app = workspace(() => new Promise(resolve => {complete = resolve;}));
    app.controls.get('graph-semantic-dataset').value = 'dataset-a';
    const response = app.run("requestJson('http://test/ui-api/v1/ops/graph/overview')");
    app.controls.get('graph-semantic-dataset').value = 'dataset-b';
    app.run('datasetGeneration += 1');
    complete({ok: true, json: async () => ({ok: true, nodes: [{id: 'foreign'}]})});
    await assert.rejects(response, /discarded stale graph response/);
});

test('clearing selection removes the scene and cancels polling', () => {
    const app = workspace();
    app.run('cy = {elements: () => ({remove: () => {}})}');
    const interval = () => {};
    app.intervals.add(interval);
    app.run('overviewPollTimer = setInterval(() => {}, 12000)');
    app.run('datasetChanged()');
    assert.equal(app.intervals.size, 1); // The unrelated timer is retained.
    assert.ok(app.intervals.has(interval));
    assert.equal(app.run('selectedNodeId'), null);
});

test('clear UI names selected dataset and sends the explicit confirmation', async () => {
    const text = readFileSync(new URL('../../resources/js/svelte/apps/HawkiRagPlayground.svelte', import.meta.url), 'utf8');
    assert.match(text, /Clear the graph for dataset/);
    assert.match(text, /confirm: 'CLEAR_DATASET_GRAPH'/);
    assert.doesNotMatch(text, /async function deleteCollection/);
    assert.match(text, /Manage dataset storage/);
});

test('settings UI disables save for deployment-managed configuration and gate is advisory', () => {
    const settings = readFileSync(new URL('../../resources/js/svelte/apps/SettingsPage.svelte', import.meta.url), 'utf8');
    assert.match(settings, /disabled=\{busy \|\| initialConfig.effective === false\}/);
    assert.match(settings, /deployment-managed/);
    const gate = readFileSync(new URL('../../resources/js/health-gate.js', import.meta.url), 'utf8');
    assert.match(gate, /Advisory system readiness/);
});

test('failed readiness checks and diagnostic outages never block the application', () => {
    const gate = readFileSync(new URL('../../resources/js/health-gate.js', import.meta.url), 'utf8');
    const renderers = gate.slice(gate.indexOf('function renderPayload('), gate.indexOf('async function refreshGate('));
    const blocked = [];
    const context = vm.createContext({createOverlay() {}, setBlocked: value => blocked.push(value),
        title: {}, summary: {}, checkedAt: {}, renderChecks() {}, renderActions() {}});
    vm.runInContext(renderers, context);
    vm.runInContext("renderPayload({enforce:true, status:'blocked'}); renderFailure(new Error('unavailable'));", context);
    assert.deepEqual(blocked, [false, false]);
});
