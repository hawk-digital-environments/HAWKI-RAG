import { apiUrl } from './playground/urls.js';

const root = document.querySelector('[data-datasets-dashboard]');

if (root) {
    const els = {
        refresh: document.getElementById('datasets-refresh'),
        status: document.getElementById('datasets-status'),
        count: document.getElementById('datasets-count'),
        list: document.getElementById('datasets-list'),
        state: document.getElementById('datasets-state'),
        updated: document.getElementById('datasets-updated'),
        info: document.getElementById('datasets-info'),
        metrics: document.getElementById('datasets-metrics'),
        taskCount: document.getElementById('datasets-task-count'),
        documentCount: document.getElementById('datasets-document-count'),
        ingestionCount: document.getElementById('datasets-ingestion-count'),
        tasks: document.getElementById('datasets-tasks'),
        documents: document.getElementById('datasets-documents'),
        ingestionHistory: document.getElementById('datasets-ingestion-history'),
    };

    const state = {
        selectedDatasetId: localStorage.getItem('hawkiDatasetsDashboardDatasetId') || '',
        datasets: [],
        pollTimer: null,
        requestId: 0,
    };

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
        if (value === 'completed' || value === 'active') return 'is-completed';
        if (value === 'running' || value === 'queued' || value === 'pending') return 'is-running';
        if (value === 'failed') return 'is-failed';
        if (value === 'skipped' || value === 'archived') return 'is-skipped';

        return 'is-idle';
    }

    function statusPill(status) {
        const pill = document.createElement('span');
        pill.className = `status-pill ${statusClass(status)}`;
        pill.textContent = valueOrDash(status);

        return pill;
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
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dataset-list-item';
            if (dataset.datasetId === state.selectedDatasetId) {
                button.classList.add('is-selected');
            }

            const top = document.createElement('span');
            top.className = 'dataset-list-top';
            const title = document.createElement('strong');
            title.textContent = dataset.name || dataset.datasetId;
            top.append(title, statusPill(dataset.status));

            const id = document.createElement('span');
            id.className = 'dataset-list-id';
            id.textContent = dataset.datasetId;

            const meta = document.createElement('span');
            meta.className = 'dataset-list-meta';
            meta.textContent = [
                `${dataset.taskCount || 0} tasks`,
                `${dataset.documentCount || 0} documents`,
                lastIngestionLabel(dataset.lastIngestion),
            ].filter(Boolean).join(' | ');

            button.append(top, id, meta);
            button.addEventListener('click', () => loadDataset(dataset.datasetId));
            els.list.appendChild(button);
        });
    }

    function renderDataset(dataset) {
        els.state.className = `status-pill ${statusClass(dataset.status)}`;
        setText(els.state, dataset.status || 'unknown');
        setText(els.updated, `Updated ${formatDate(new Date().toISOString())}`);
        setStatus(`Showing dataset ${dataset.datasetId}.`);

        renderInfo(dataset);
        renderMetrics(dataset);
        renderTasks(dataset.tasks || []);
        renderDocuments(dataset.documents || []);
        renderIngestionHistory(dataset.ingestionHistory || []);
    }

    function renderInfo(dataset) {
        els.info.innerHTML = '';
        [
            ['Dataset ID', dataset.datasetId],
            ['Name', dataset.name],
            ['Status', dataset.status],
            ['Created', formatDate(dataset.createdAt)],
            ['Qdrant collection', dataset.qdrantCollection],
            ['Neo4j namespace', dataset.neo4jNamespace],
            ['Description', dataset.description],
            ['Last ingestion', lastIngestionLabel(dataset.lastIngestion) || '-'],
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
        const qdrant = dataset.graphStats?.qdrant || {};
        const neo4j = dataset.graphStats?.neo4j || {};

        [
            ['Documents', dataset.documentCount ?? 0, 'Database documents'],
            ['Tasks', dataset.taskCount ?? 0, 'Pipeline tasks'],
            ['Qdrant points', qdrant.points ?? '-', qdrant.ok === false ? qdrant.error : qdrant.collection],
            ['Graph nodes', neo4j.nodes ?? '-', neo4j.ok === false ? neo4j.error : neo4j.namespace],
            ['Graph relationships', neo4j.relationships ?? '-', neo4j.ok === false ? neo4j.error : neo4j.namespace],
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
        renderTable(els.tasks, ['Task', 'Status', 'Profile', 'Jobs', 'Started', 'Finished'], tasks, (task) => [
            task.taskId,
            statusPill(task.status),
            task.profileId,
            task.counters?.jobs_total ?? 0,
            formatDate(task.startedAt),
            formatDate(task.finishedAt),
        ]);
    }

    function renderDocuments(documents) {
        setText(els.documentCount, `${documents.length} document${documents.length === 1 ? '' : 's'} shown`);
        renderTable(els.documents, ['Document', 'Status', 'Collection', 'Source URL', 'Path', 'Updated'], documents, (document) => [
            document.title || document.originalFilename || document.id,
            statusPill(document.status),
            document.collection,
            document.sourceUrl,
            document.storagePath,
            formatDate(document.updatedAt || document.createdAt),
        ]);
    }

    function renderIngestionHistory(history) {
        setText(els.ingestionCount, `${history.length} ingestion job${history.length === 1 ? '' : 's'} shown`);
        renderTable(els.ingestionHistory, ['Job', 'Task', 'Status', 'Source URL', 'Path', 'Error', 'Finished'], history, (job) => [
            job.jobId,
            job.taskId,
            statusPill(job.status),
            job.sourceUrl,
            job.localPath,
            job.errorMessage,
            formatDate(job.finishedAt),
        ]);
    }

    function renderTable(container, headers, rows, mapper) {
        container.innerHTML = '';
        if (rows.length === 0) {
            renderEmpty(container, 'Nothing recorded yet.');
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
        const empty = document.createElement('div');
        empty.className = 'empty-state';
        empty.textContent = message;
        container.appendChild(empty);
    }

    function lastIngestionLabel(lastIngestion) {
        if (!lastIngestion) return '';

        return `${lastIngestion.status || 'ingested'} ${formatDate(lastIngestion.finishedAt)}`;
    }

    async function loadDatasets({ keepSelection = true } = {}) {
        const requestId = ++state.requestId;
        const data = await requestJson('api/datasets?limit=100');
        if (requestId !== state.requestId) return;

        state.datasets = Array.isArray(data.datasets) ? data.datasets : [];
        if (!keepSelection || !state.datasets.some((dataset) => dataset.datasetId === state.selectedDatasetId)) {
            state.selectedDatasetId = state.datasets[0]?.datasetId || '';
        }

        renderDatasets(state.datasets);

        if (state.selectedDatasetId) {
            await loadDataset(state.selectedDatasetId, { renderList: false });
        } else {
            clearDetail();
        }
    }

    async function loadDataset(datasetId, { renderList = true } = {}) {
        if (!datasetId) return;
        state.selectedDatasetId = datasetId;
        localStorage.setItem('hawkiDatasetsDashboardDatasetId', datasetId);
        setStatus(`Loading dataset ${datasetId}...`);

        const data = await requestJson(`api/datasets/${encodeURIComponent(datasetId)}`);
        renderDataset(data.dataset);

        if (renderList) {
            renderDatasets(state.datasets);
        }
    }

    function clearDetail() {
        setStatus('No datasets found.');
        els.state.className = 'status-pill is-idle';
        setText(els.state, 'idle');
        setText(els.updated, 'No dataset loaded.');
        [els.info, els.metrics, els.tasks, els.documents, els.ingestionHistory]
            .filter(Boolean)
            .forEach((container) => {
                container.innerHTML = '';
                renderEmpty(container, 'Nothing to show yet.');
            });
        [els.taskCount, els.documentCount, els.ingestionCount].forEach((el) => setText(el, '0'));
    }

    function startPolling() {
        if (state.pollTimer) clearInterval(state.pollTimer);
        state.pollTimer = setInterval(async () => {
            if (!state.selectedDatasetId) return;
            try {
                await loadDatasets({ keepSelection: true });
            } catch {
                // Polling stays quiet; manual refresh reports errors.
            }
        }, 10000);
    }

    els.refresh?.addEventListener('click', async () => {
        try {
            setStatus('Refreshing datasets...');
            await loadDatasets({ keepSelection: true });
            setStatus('Datasets refreshed.', 'success');
        } catch (error) {
            setStatus(error.message || 'Refresh failed.', 'error');
        }
    });

    loadDatasets({ keepSelection: true }).catch((error) => {
        setStatus(error.message || 'Could not load datasets.', 'error');
        clearDetail();
    });
    startPolling();
}
