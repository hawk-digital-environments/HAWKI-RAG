import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runtimePresentation} from '../../resources/js/playground/runtime-presentation.js';
import {stageLogPresentation} from '../../resources/js/playground/stage-log-presentation.js';
test('runtime uses safe server metadata including an actual latest dataset', () => {
    assert.deepEqual(runtimePresentation({engine:'python_graph_ops',query_model:'configured-chat',embedding_model:'configured-embed',latest_dataset:'latest'}), {engine:'RAGAnything',queryModel:'configured-chat',embeddingModel:'configured-embed',latestDataset:'latest'});
    assert.equal(runtimePresentation().latestDataset, 'No datasets yet');
});
test('stage previews preserve text and distinguish download from empty state', () => {
    assert.deepEqual(stageLogPresentation({text:'real stage event'}), {text:'real stage event',downloadable:true});
    assert.equal(stageLogPresentation().downloadable,false);
    assert.match(stageLogPresentation().text,/No stage events yet/);
    const source=readFileSync('resources/js/playground/pipeline.js','utf8');
    assert.match(source,/preview.textContent = view.text/);
    assert.match(source,/stageLogPath\(stage, true\)/);
});
test('standalone dashboard theme overrides page defaults and controls use tokens', () => {
    const entry=readFileSync('frontend/main.js','utf8');
    assert.ok(entry.indexOf('dashboard-dark-theme.css')>entry.indexOf('pipeline-health-dashboard.css'));
    assert.doesNotMatch(readFileSync('resources/css/datasets-dashboard.css','utf8'),/background: #ffffff/);
});
