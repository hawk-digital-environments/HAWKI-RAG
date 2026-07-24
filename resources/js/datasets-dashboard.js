import './health-gate.js';
import { mount } from 'svelte';
import DatasetsDashboardPage from './svelte/apps/DatasetsDashboardPage.svelte';
import { apiUrl } from './playground/urls.js';
import { renderStatusIndicator } from './status-indicator.js';

const root = document.querySelector('[data-datasets-dashboard]');

if (root) {
    mount(DatasetsDashboardPage, {
        target: root,
        props: {
            onready: bootDatasetsDashboard,
        },
    });
}

function bootDatasetsDashboard() {
    if (!root) return;

    const query = new URLSearchParams(window.location.search);
    const els = {
        status: document.getElementById('datasets-status'),
        count: document.getElementById('datasets-count'),
        list: document.getElementById('datasets-list'),
        updated: document.getElementById('datasets-updated'),
        info: document.getElementById('datasets-info'),
        metrics: document.getElementById('datasets-metrics'),
        taskCount: document.getElementById('datasets-task-count'),
        documentCount: document.getElementById('datasets-document-count'),
        documentSearchForm: document.getElementById('datasets-document-search-form'),
        documentSearch: document.getElementById('datasets-document-search'),
        documentState: document.getElementById('datasets-document-state'),
        documentUpdated: document.getElementById('datasets-document-updated'),
        documentInfo: document.getElementById('datasets-document-info'),
        documentMetrics: document.getElementById('datasets-document-metrics'),
        documentPreviewNote: document.getElementById('datasets-document-preview-note'),
        documentMarkdownPreview: document.getElementById('datasets-document-markdown-preview'),
        documentJobsCount: document.getElementById('datasets-document-jobs-count'),
        documentRelatedJobs: document.getElementById('datasets-document-related-jobs'),
        documentMetadata: document.getElementById('datasets-document-metadata'),
        ingestionCount: document.getElementById('datasets-ingestion-count'),
        tasks: document.getElementById('datasets-tasks'),
        documents: document.getElementById('datasets-documents'),
        ingestionHistory: document.getElementById('datasets-ingestion-history'),
    };

    const state = {
        selectedDatasetId: query.get('dataset_id') || query.get('datasetId') || localStorage.getItem('hawkiDatasetsDashboardDatasetId') || '',
        selectedDocumentId: query.get('document_id') || query.get('documentId') || localStorage.getItem('hawkiDatasetsDashboardDocumentId') || '',
        documentSearch: query.get('q') || '',
        datasets: [],
        documents: [],
        pollTimer: null,
        requestId: 0,
        documentRequestId: 0,
        selectedDataset: null,
    };

    if (els.documentSearch) {
        els.documentSearch.value = state.documentSearch;
    }

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    async function requestJson(path, options = {}) {
        const headers = {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...(options.headers || {}),
        };

        if (options.body && !headers['Content-Type']) {
            headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(apiUrl(path), {
            ...options,
            headers,
        });
        const text = await response.text();
        const data = text ? JSON.parse(text) : {};

        if (!response.ok || data.success === false) {
            throw new Error(data.message || `HTTP ${response.status}`);
        }

        return data;
    }

    function setStatus(message, tone = 'neutral') {
        if (!els.status) return;
        els.status.textContent = message;
        els.status.dataset.tone = tone;
    }

    function setText(el, value) {
        if (el) el.textContent = value;
    }

    function valueOrDash(value) {
        return value === undefined || value === null || value === '' ? '-' : String(value);
    }

    function formatDate(value) {
        if (!value) return '-';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);

        return date.toLocaleString([], {
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    }

    function statusClass(status) {
        const value = String(status || 'idle').toLowerCase();
        if (['completed', 'indexed', 'active'].includes(value)) return 'is-completed';
        if (['running', 'queued', 'pending', 'processing'].includes(value)) return 'is-running';
        if (value === 'failed') return 'is-failed';
        if (['skipped', 'archived', 'disabled'].includes(value)) return 'is-skipped';

        return 'is-idle';
    }

    function statusPill(status) {
        const pill = document.createElement('span');
        pill.className = `status-pill ${statusClass(status)}`;
        renderStatusIndicator(pill, status);

        return pill;
    }

    function isFailedStatus(status) {
        return String(status || '').toLowerCase() === 'failed';
    }

    function makeLink(href, text) {
        if (!href || !text) return valueOrDash(text);

        const link = document.createElement('a');
        link.href = href;
        link.textContent = text;
        link.className = 'table-link';

        return link;
    }

    function syncUrl() {
        const next = new URL(window.location.href);
        next.search = '';
        if (state.selectedDatasetId) next.searchParams.set('dataset_id', state.selectedDatasetId);
        if (state.selectedDocumentId) next.searchParams.set('document_id', state.selectedDocumentId);
        if (state.documentSearch) next.searchParams.set('q', state.documentSearch);
        window.history.replaceState({}, '', next.toString());
    }

    function actionButton(label, handler, failureMessage = 'Action failed.') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'inline-button';
        button.textContent = label;
        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                await handler();
            } catch (error) {
                setStatus(error.message || failureMessage, 'error');
            } finally {
                button.disabled = false;
            }
        });

        return button;
    }

    function retryTaskButton(task) {
        if (!isFailedStatus(task.status) || !task.task_id) {
            return '-';
        }

        return actionButton('Retry', () => retryTask(task.task_id), 'Retry failed.');
    }

    function retryJobButton(job) {
        if (!isFailedStatus(job.status) || !job.job_id) {
            return '-';
        }

        return actionButton('Retry', () => retryJob(job.job_id), 'Retry failed.');
    }

    function openDocumentButton(doc) {
        if (!doc.id) {
            return '-';
        }

        const button = actionButton(
            doc.id === state.selectedDocumentId ? 'Open' : 'Open',
            () => loadDocument(doc.id),
            'Document load failed.',
        );
        button.disabled = doc.id === state.selectedDocumentId;

        return button;
    }

    function evidencePills(doc) {
        const wrapper = document.createElement('span');
        wrapper.className = 'evidence-stack';

        const qdrant = document.createElement('span');
        qdrant.className = 'evidence-item';
        qdrant.append('Qdrant ', statusPill(doc.qdrant_status || doc.status));

        const neo4j = document.createElement('span');
        neo4j.className = 'evidence-item';
        neo4j.append('Neo4j ', statusPill(doc.neo4j_status || 'unknown'));

        wrapper.append(qdrant, neo4j);

        return wrapper;
    }

    function renderDatasets(datasets) {
        if (!els.list) return;
        els.list.innerHTML = '';
        setText(els.count, `${datasets.length} dataset${datasets.length === 1 ? '' : 's'}`);

        if (datasets.length === 0) {
            renderEmpty(els.list, 'No datasets yet.');
            return;
        }

        datasets.forEach((dataset) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'dataset-list-entry';
            if (dataset.dataset_id === state.selectedDatasetId) {
                wrapper.classList.add('is-selected');
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dataset-list-item';
            if (dataset.dataset_id === state.selectedDatasetId) {
                button.classList.add('is-selected');
            }

            const top = document.createElement('span');
            top.className = 'dataset-list-top';
            const title = document.createElement('strong');
            title.textContent = dataset.name || dataset.dataset_id;
            top.append(title, statusPill(dataset.status));

            const id = document.createElement('span');
            id.className = 'dataset-list-id';
            id.textContent = dataset.dataset_id;

            const meta = document.createElement('span');
            meta.className = 'dataset-list-meta';
            meta.textContent = [
                `${dataset.task_count || 0} tasks`,
                `${dataset.document_count || 0} documents`,
                lastIngestionLabel(dataset.last_ingestion),
            ].filter(Boolean).join(' | ');

            button.append(top, id, meta);
            button.addEventListener('click', () => loadDataset(dataset.dataset_id, { keepDocumentSelection: false }));

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'dataset-delete-button';
            deleteButton.textContent = 'Delete';
            deleteButton.title = 'Delete this dataset Qdrant collection and Neo4j graph data';
            deleteButton.addEventListener('click', async () => {
                deleteButton.disabled = true;
                try {
                    await deleteDatasetStorage(dataset);
                } catch (error) {
                    setStatus(error.message || 'Dataset storage delete failed.', 'error');
                } finally {
                    deleteButton.disabled = false;
                }
            });

            wrapper.append(button, deleteButton);
            els.list.appendChild(wrapper);
        });
    }

    function renderDataset(dataset) {
        state.selectedDataset = dataset;
        setText(els.updated, `Updated ${formatDate(new Date().toISOString())}`);
        setStatus(`Showing dataset ${dataset.dataset_id}.`);

        renderInfo(dataset);
        renderMetrics(dataset);
        renderTasks(dataset.tasks || []);
        renderIngestionHistory(dataset.ingestion_history || []);
    }

    function renderInfo(dataset) {
        els.info.innerHTML = '';
        [
            ['Name', dataset.name],
            ['Dataset ID', dataset.dataset_id],
            ['Qdrant collection', dataset.qdrant_collection],
            ['Neo4j namespace', dataset.neo4j_namespace],
            ['Embedding provider', dataset.embedding_provider],
            ['Embedding model', dataset.embedding_model],
            ['Last ingestion', lastIngestionLabel(dataset.last_ingestion) || '-'],
        ].forEach(([label, value]) => {
            const wrapper = document.createElement('div');
            const term = document.createElement('dt');
            const description = document.createElement('dd');
            term.textContent = label;
            description.textContent = valueOrDash(value);
            wrapper.append(term, description);
            els.info.appendChild(wrapper);
        });
    }

    function renderMetrics(dataset) {
        els.metrics.innerHTML = '';
        const qdrant = dataset.graph_stats?.qdrant || {};
        const neo4j = dataset.graph_stats?.neo4j || {};
        const qdrantCaption = qdrant.ok === false
            ? qdrant.error
            : (qdrant.message || qdrant.collection);

        [
            ['Documents', dataset.document_count ?? 0, 'Database documents'],
            ['Qdrant points', qdrant.points ?? '-', qdrantCaption],
            ['Neo4j entities', neo4j.nodes ?? '-', neo4j.ok === false ? neo4j.error : neo4j.namespace],
            ['Neo4j relations', neo4j.relationships ?? '-', neo4j.ok === false ? neo4j.error : neo4j.namespace],
        ].forEach(([label, value, caption]) => {
            const item = document.createElement('div');
            item.className = 'metric-item';
            const strong = document.createElement('strong');
            strong.textContent = valueOrDash(value);
            const span = document.createElement('span');
            span.textContent = label;
            const small = document.createElement('small');
            small.textContent = valueOrDash(caption);
            item.append(strong, span, small);
            els.metrics.appendChild(item);
        });
    }

    function renderTasks(tasks) {
        setText(els.taskCount, `${tasks.length} task${tasks.length === 1 ? '' : 's'}`);
        renderTable(els.tasks, ['Task', 'Status', 'Jobs', 'Finished', 'Action'], tasks, (task) => [
            task.task_id,
            statusPill(task.status),
            task.counters?.jobs_total ?? 0,
            formatDate(task.finished_at),
            retryTaskButton(task),
        ]);
    }

    function renderDocuments(documents) {
        setText(els.documentCount, `${documents.length} document${documents.length === 1 ? '' : 's'} shown`);
        renderTable(els.documents, ['Document', 'Status', 'Evidence', 'Source', 'Updated', 'Action'], documents, (doc) => [
            doc.title || doc.original_filename || doc.id,
            statusPill(doc.status),
            evidencePills(doc),
            doc.source_url,
            formatDate(doc.updated_at || doc.created_at),
            openDocumentButton(doc),
        ], 'No documents found for this dataset.');
    }

    function renderDocument(doc) {
        state.selectedDocumentId = doc.id;
        localStorage.setItem('hawkiDatasetsDashboardDocumentId', doc.id);
        setText(els.documentUpdated, `Updated ${formatDate(doc.updated_at || new Date().toISOString())}`);
        els.documentState.className = `status-pill ${statusClass(doc.status)}`;
        renderStatusIndicator(els.documentState, doc.status);

        renderDocumentInfo(doc);
        renderDocumentMetrics(doc);
        renderMarkdown(doc);
        renderRelatedJobs(doc.related_jobs || []);
        setText(els.documentMetadata, JSON.stringify(doc.metadata || {}, null, 2));
        renderDocuments(state.documents);
        syncUrl();
    }

    function renderDocumentInfo(doc) {
        els.documentInfo.innerHTML = '';

        [
            ['Document', doc.title || doc.original_filename || doc.id],
            ['Dataset ID', makeLink(`/datasets?dataset_id=${encodeURIComponent(doc.dataset_id || '')}`, doc.dataset_id)],
            ['Status', statusPill(doc.status)],
            ['Source URL', doc.source_url],
            ['Content type', doc.content_type],
            ['Ingested at', formatDate(doc.ingested_at)],
        ].forEach(([label, value]) => {
            const wrapper = document.createElement('div');
            const term = document.createElement('dt');
            const description = document.createElement('dd');
            term.textContent = label;
            if (value instanceof HTMLElement) {
                description.appendChild(value);
            } else {
                description.textContent = valueOrDash(value);
            }
            wrapper.append(term, description);
            els.documentInfo.appendChild(wrapper);
        });
    }

    function renderDocumentMetrics(doc) {
        els.documentMetrics.innerHTML = '';
        [
            ['Qdrant points', doc.qdrant_point_count ?? '-', doc.qdrant_collection || doc.collection],
            ['Neo4j entities', doc.neo4j_entity_count ?? '-', doc.neo4j_namespace || doc.neo4j_status],
            ['Neo4j relations', doc.neo4j_relation_count ?? '-', doc.neo4j_namespace || doc.neo4j_status],
            ['File size', doc.file_size ? `${doc.file_size} bytes` : '-', doc.content_type],
        ].forEach(([label, value, caption]) => {
            const item = document.createElement('div');
            item.className = 'metric-item';
            const strong = document.createElement('strong');
            strong.textContent = valueOrDash(value);
            const span = document.createElement('span');
            span.textContent = label;
            const small = document.createElement('small');
            small.textContent = valueOrDash(caption);
            item.append(strong, span, small);
            els.documentMetrics.appendChild(item);
        });
    }

    function renderMarkdown(doc) {
        const preview = doc.markdown_preview || '';
        if (preview) {
            setText(els.documentMarkdownPreview, preview);
            setText(
                els.documentPreviewNote,
                doc.markdown_preview_truncated
                    ? `Preview is truncated from ${doc.markdown_preview_path || doc.local_path}.`
                    : `Preview from ${doc.markdown_preview_path || doc.local_path}.`,
            );
            els.documentMarkdownPreview.dataset.empty = 'false';
            return;
        }

        setText(els.documentMarkdownPreview, doc.markdown_preview_error || 'No extracted Markdown preview is available.');
        setText(els.documentPreviewNote, doc.markdown_preview_error || 'Preview reads the recorded local path.');
        els.documentMarkdownPreview.dataset.empty = 'true';
    }

    function renderRelatedJobs(jobs) {
        setText(els.documentJobsCount, `${jobs.length} job${jobs.length === 1 ? '' : 's'} shown`);
        renderTable(els.documentRelatedJobs, ['Job', 'Type', 'Status', 'Finished', 'Error', 'Action'], jobs, (job) => [
            job.job_id,
            job.job_type,
            statusPill(job.status),
            formatDate(job.finished_at),
            job.error_message,
            retryJobButton(job),
        ]);
    }

    function renderIngestionHistory(history) {
        setText(els.ingestionCount, `${history.length} ingestion job${history.length === 1 ? '' : 's'} shown`);
        renderTable(els.ingestionHistory, ['Job', 'Status', 'Finished', 'Error', 'Action'], history, (job) => [
            job.job_id,
            statusPill(job.status),
            formatDate(job.finished_at),
            job.error_message,
            retryJobButton(job),
        ]);
    }

    function renderTable(container, headers, rows, mapper, emptyMessage = 'Nothing recorded yet.') {
        if (!container) return;
        container.innerHTML = '';
        if (rows.length === 0) {
            renderEmpty(container, emptyMessage);
            return;
        }

        const table = document.createElement('table');
        table.className = 'data-table';
        const thead = document.createElement('thead');
        const tr = document.createElement('tr');
        headers.forEach((header) => {
            const th = document.createElement('th');
            th.textContent = header;
            tr.appendChild(th);
        });
        thead.appendChild(tr);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        rows.forEach((row) => {
            const data = mapper(row);
            const bodyRow = document.createElement('tr');
            if (row.id && row.id === state.selectedDocumentId) {
                bodyRow.classList.add('is-selected');
            }
            data.forEach((value) => {
                const td = document.createElement('td');
                if (value instanceof HTMLElement) {
                    td.appendChild(value);
                } else {
                    td.textContent = valueOrDash(value);
                }
                bodyRow.appendChild(td);
            });
            tbody.appendChild(bodyRow);
        });
        table.appendChild(tbody);
        container.appendChild(table);
    }

    function renderEmpty(container, message) {
        if (!container) return;
        const empty = document.createElement('div');
        empty.className = 'empty-state';
        empty.textContent = message;
        container.appendChild(empty);
    }

    function lastIngestionLabel(lastIngestion) {
        if (!lastIngestion) return '';

        return `${lastIngestion.status || 'ingested'} ${formatDate(lastIngestion.finished_at)}`;
    }

    function setDocumentsLoading() {
        setText(els.documentCount, 'Loading documents...');
        if (els.documents) {
            els.documents.innerHTML = '';
            renderEmpty(els.documents, 'Loading documents...');
        }
    }

    async function resolveInitialDocumentDataset() {
        if (!state.selectedDocumentId || state.selectedDatasetId) {
            return;
        }

        try {
            const data = await requestJson(`documents/${encodeURIComponent(state.selectedDocumentId)}`);
            if (data.document?.dataset_id) {
                state.selectedDatasetId = data.document.dataset_id;
                localStorage.setItem('hawkiDatasetsDashboardDatasetId', state.selectedDatasetId);
            }
        } catch {
            state.selectedDocumentId = '';
        }
    }

    async function loadDatasets({ keepSelection = true } = {}) {
        const requestId = ++state.requestId;
        const data = await requestJson('datasets?limit=100');
        if (requestId !== state.requestId) return;

        state.datasets = Array.isArray(data.datasets) ? data.datasets : [];
        if (!keepSelection || !state.datasets.some((dataset) => dataset.dataset_id === state.selectedDatasetId)) {
            state.selectedDatasetId = state.datasets[0]?.dataset_id || '';
            state.selectedDocumentId = '';
        }

        renderDatasets(state.datasets);

        if (state.selectedDatasetId) {
            await loadDataset(state.selectedDatasetId, { renderList: false });
        } else {
            clearDetail();
        }
    }

    async function loadDataset(datasetId, { renderList = true, keepDocumentSelection = true, loadDocumentList = true } = {}) {
        if (!datasetId) return;
        const changedDataset = state.selectedDatasetId !== datasetId;
        state.selectedDatasetId = datasetId;
        localStorage.setItem('hawkiDatasetsDashboardDatasetId', datasetId);
        if (changedDataset || !keepDocumentSelection) {
            state.selectedDocumentId = '';
            localStorage.removeItem('hawkiDatasetsDashboardDocumentId');
        }
        setStatus(`Loading dataset ${datasetId}...`);
        setDocumentsLoading();

        const data = await requestJson(`datasets/${encodeURIComponent(datasetId)}`);
        renderDataset(data.dataset);

        if (renderList) {
            renderDatasets(state.datasets);
        }

        if (loadDocumentList) {
            await loadDocuments({ keepSelection: keepDocumentSelection && !changedDataset });
        }

        syncUrl();
    }

    async function loadDocuments({ keepSelection = true } = {}) {
        if (!state.selectedDatasetId) {
            state.documents = [];
            renderDocuments([]);
            clearDocumentDetail();
            return;
        }

        const requestId = ++state.documentRequestId;
        const params = new URLSearchParams({
            dataset_id: state.selectedDatasetId,
            limit: '150',
        });
        if (state.documentSearch) {
            params.set('q', state.documentSearch);
        }

        const data = await requestJson(`documents?${params.toString()}`);
        if (requestId !== state.documentRequestId) return;

        state.documents = Array.isArray(data.documents) ? data.documents : [];
        if (!keepSelection || !state.documents.some((doc) => doc.id === state.selectedDocumentId)) {
            state.selectedDocumentId = state.documents[0]?.id || '';
        }

        renderDocuments(state.documents);

        if (state.selectedDocumentId) {
            await loadDocument(state.selectedDocumentId, { renderList: false });
        } else {
            localStorage.removeItem('hawkiDatasetsDashboardDocumentId');
            clearDocumentDetail();
            syncUrl();
        }
    }

    async function loadDocument(documentId, { renderList = true } = {}) {
        if (!documentId) return;
        state.selectedDocumentId = documentId;
        localStorage.setItem('hawkiDatasetsDashboardDocumentId', documentId);
        setStatus(`Loading document ${documentId}...`);

        const data = await requestJson(`documents/${encodeURIComponent(documentId)}`);
        if (data.document?.dataset_id && data.document.dataset_id !== state.selectedDatasetId) {
            state.selectedDatasetId = data.document.dataset_id;
            localStorage.setItem('hawkiDatasetsDashboardDatasetId', state.selectedDatasetId);
        }

        renderDocument(data.document);

        if (renderList) {
            renderDocuments(state.documents);
        }

        setStatus(`Showing document ${documentId}.`);
    }

    async function retryTask(taskId) {
        setStatus(`Retrying failed jobs for ${taskId}...`);
        await requestJson(`pipeline/recovery/tasks/${encodeURIComponent(taskId)}/retry-failed`, { method: 'POST' });
        await loadDataset(state.selectedDatasetId, { renderList: false });
        setStatus(`Queued retry for ${taskId}.`, 'success');
    }

    async function retryJob(jobId) {
        setStatus(`Retrying ${jobId}...`);
        await requestJson(`pipeline/recovery/jobs/${encodeURIComponent(jobId)}/retry`, { method: 'POST' });
        await loadDataset(state.selectedDatasetId, { renderList: false });
        setStatus(`Queued retry for ${jobId}.`, 'success');
    }

    async function deleteDatasetStorage(dataset) {
        const qdrant = dataset.qdrant_collection || 'the linked Qdrant collection';
        const neo4j = dataset.neo4j_namespace || 'the linked Neo4j namespace';
        const confirmed = window.confirm(
            `Delete dataset ${dataset.dataset_id}?\n\nThis deletes Qdrant collection "${qdrant}", Neo4j graph data for "${neo4j}", and removes the dataset from this browser. Historical task and document records stay in the database.`,
        );
        if (!confirmed) return;

        setStatus(`Deleting dataset ${dataset.dataset_id}...`);
        const data = await requestJson(`datasets/${encodeURIComponent(dataset.dataset_id)}/storage`, { method: 'DELETE' });
        const qdrantMessage = data.cleanup?.qdrant?.message || 'Qdrant cleanup finished.';
        const neo4jNodes = data.cleanup?.neo4j?.nodes ?? 0;
        const neo4jRelationships = data.cleanup?.neo4j?.relationships ?? 0;

        if (dataset.dataset_id === state.selectedDatasetId) {
            state.selectedDatasetId = '';
            state.selectedDocumentId = '';
            localStorage.removeItem('hawkiDatasetsDashboardDatasetId');
            localStorage.removeItem('hawkiDatasetsDashboardDocumentId');
        }

        await loadDatasets({ keepSelection: false });
        setStatus(`Deleted ${dataset.dataset_id}. ${qdrantMessage} Neo4j deleted ${neo4jNodes} nodes and ${neo4jRelationships} relationships.`, 'success');
    }

    function clearDocumentDetail() {
        setText(els.documentUpdated, 'No document loaded.');
        if (els.documentState) {
            els.documentState.className = 'status-pill is-idle';
            renderStatusIndicator(els.documentState, 'idle');
        }
        [els.documentInfo, els.documentMetrics, els.documentRelatedJobs].filter(Boolean).forEach((container) => {
            container.innerHTML = '';
            renderEmpty(container, 'Nothing to show yet.');
        });
        setText(els.documentPreviewNote, 'Preview reads the recorded local path.');
        setText(els.documentMarkdownPreview, 'No extracted Markdown preview is available.');
        if (els.documentMarkdownPreview) {
            els.documentMarkdownPreview.dataset.empty = 'true';
        }
        setText(els.documentMetadata, '{}');
        setText(els.documentJobsCount, '0 jobs');
    }

    function clearDetail() {
        state.selectedDataset = null;
        setStatus('No datasets found.');
        setText(els.updated, 'No dataset loaded.');
        [els.info, els.metrics, els.tasks, els.documents, els.ingestionHistory]
            .filter(Boolean)
            .forEach((container) => {
                container.innerHTML = '';
                renderEmpty(container, 'Nothing to show yet.');
            });
        [els.taskCount, els.documentCount, els.ingestionCount].forEach((el) => setText(el, '0'));
        clearDocumentDetail();
    }

    function startPolling() {
        if (state.pollTimer) clearInterval(state.pollTimer);
        state.pollTimer = setInterval(async () => {
            try {
                await loadDatasets({ keepSelection: true });
            } catch {
                // Polling stays quiet; the visible status only changes on initial load or user actions.
            }
        }, 10000);
    }

    els.documentSearchForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        state.documentSearch = els.documentSearch?.value.trim() || '';
        state.selectedDocumentId = '';
        localStorage.removeItem('hawkiDatasetsDashboardDocumentId');

        try {
            setStatus('Filtering documents...');
            await loadDocuments({ keepSelection: false });
            setStatus('Documents filtered.', 'success');
        } catch (error) {
            setStatus(error.message || 'Document filter failed.', 'error');
        }
    });

    resolveInitialDocumentDataset()
        .then(async () => {
            await loadDatasets({ keepSelection: true });
        })
        .catch((error) => {
            setStatus(error.message || 'Could not load data browser.', 'error');
            clearDetail();
        });
    startPolling();
}
