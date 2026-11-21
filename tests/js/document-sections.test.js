import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const component = readFileSync('resources/js/svelte/apps/DatasetsDashboardPage.svelte', 'utf8');
const runtime = readFileSync('resources/js/datasets-dashboard.js', 'utf8');

test('document browser omits the four removed panels and their containers', () => {
    for (const text of ['Extracted Markdown preview', 'Related pipeline jobs', 'Pipeline history', 'Ingestion history', 'Document metadata']) {
        assert.ok(!component.includes(text), text);
    }
    for (const id of ['datasets-document-markdown-preview', 'datasets-document-related-jobs', 'datasets-tasks', 'datasets-ingestion-history', 'datasets-document-metadata', 'datasets-task-count', 'datasets-ingestion-count', 'datasets-document-jobs-count']) {
        assert.ok(!component.includes(id), id);
        assert.ok(!runtime.includes(id), id);
    }
    assert.ok(!component.includes('technical-panel'));
});

test('document list, evidence, search, download and deletion stay connected', () => {
    for (const id of ['datasets-documents', 'datasets-document-search-form']) {
        assert.ok(component.includes(id), id);
        assert.ok(runtime.includes(id), id);
    }
    assert.match(runtime, /function deleteDocument\(/);
    assert.match(runtime, /download.textContent = 'Download'/);
    assert.match(runtime, /documents\/uploads\/download\?document_id=/);
    assert.match(runtime, /async function resolveInitialDocumentDataset\(/);
    assert.doesNotMatch(runtime, /pipeline\/recovery\/|function renderMarkdown\(|function renderRelatedJobs\(|function renderTasks\(|function renderIngestionHistory\(/);
});


test('selected document detail panel and its exclusive readers are removed', () => {
    assert.ok(!component.includes('Selected document'));
    assert.ok(!component.includes('document-context-panel'));
    for (const id of ['datasets-document-info', 'datasets-document-metrics', 'datasets-document-state', 'datasets-document-updated']) {
        assert.ok(!component.includes(id), id);
        assert.ok(!runtime.includes(id), id);
    }
    assert.doesNotMatch(runtime, /function (renderDocumentInfo|renderDocumentMetrics|loadDocument|clearDocumentDetail)\(/);
    for (const label of ['File size', 'Ingested at', 'Content type']) assert.ok(!runtime.includes(label), label);
    // Dataset overview metrics remain; no document-specific metric renderer survives.
    assert.ok(component.includes('datasets-metrics'));
});
