import { apiUrl } from './playground/urls.js';

const root = document.querySelector('[data-documents-dashboard]');

if (root) {
    const query = new URLSearchParams(window.location.search);
    const els = {
        refresh: document.getElementById('documents-refresh'),
        filters: document.getElementById('documents-filters'),
        datasetFilter: document.getElementById('documents-dataset-filter'),
        searchFilter: document.getElementById('documents-search-filter'),
        status: document.getElementById('documents-status'),
        count: document.getElementById('documents-count'),
        list: document.getElementById('documents-list'),
        state: document.getElementById('documents-state'),
        updated: document.getElementById('documents-updated'),
        info: document.getElementById('documents-info'),
        metrics: document.getElementById('documents-metrics'),
        previewNote: document.getElementById('documents-preview-note'),
        markdownPreview: document.getElementById('documents-markdown-preview'),
        jobsCount: document.getElementById('documents-jobs-count'),
        relatedJobs: document.getElementById('documents-related-jobs'),
        metadata: document.getElementById('documents-metadata'),
    };

    const state = {
        selectedDocumentId: query.get('document_id') || query.get('documentId') || localStorage.getItem('hawkiDocumentsDashboardDocumentId') || '',
        datasetId: query.get('dataset_id') || query.get('datasetId') || localStorage.getItem('hawkiDocumentsDashboardDatasetId') || '',
        search: query.get('q') || '',
        documents: [],
        requestId: 0,
    };

    if (els.datasetFilter) els.datasetFilter.value = state.datasetId;
    if (els.searchFilter) els.searchFilter.value = state.search;

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
        pill.textContent = valueOrDash(status);

        return pill;
    }

    function makeLink(href, text) {
        if (!href || !text) return valueOrDash(text);

        const link = document.createElement('a');
        link.href = href;
        link.textContent = text;
        link.className = 'table-link';

        return link;
    }

    function taskLink(taskId) {
        return taskId ? `/pipeline-dashboard?task_id=${encodeURIComponent(taskId)}` : '';
    }

    function renderDocuments(documents) {
        if (!els.list) return;
        els.list.innerHTML = '';
        setText(els.count, `${documents.length} document${documents.length === 1 ? '' : 's'} shown`);

        if (documents.length === 0) {
            renderEmpty(els.list, 'No ingested documents found.');
            return;
        }

        documents.forEach((document) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'document-list-item';
            if (document.id === state.selectedDocumentId) {
                button.classList.add('is-selected');
            }

            const top = document.createElement('span');
            top.className = 'document-list-top';
            const title = document.createElement('strong');
            title.textContent = document.title || document.originalFilename || document.id;
            top.append(title, statusPill(document.qdrantStatus || document.status));

            const source = document.createElement('span');
            source.className = 'document-list-source';
            source.textContent = document.sourceUrl || document.localPath || document.contentHash;

            const meta = document.createElement('span');
            meta.className = 'document-list-meta';
            meta.textContent = [
                document.datasetId,
                document.contentType,
                `Qdrant ${document.qdrantStatus || 'unknown'}`,
                `Neo4j ${document.neo4jStatus || 'unknown'}`,
            ].filter(Boolean).join(' | ');

            button.append(top, source, meta);
            button.addEventListener('click', () => loadDocument(document.id));
            els.list.appendChild(button);
        });
    }

    function renderDocument(document) {
        state.selectedDocumentId = document.id;
        localStorage.setItem('hawkiDocumentsDashboardDocumentId', document.id);
        els.state.className = `status-pill ${statusClass(document.status)}`;
        setText(els.state, document.status || 'unknown');
        setText(els.updated, `Updated ${formatDate(document.updatedAt || new Date().toISOString())}`);
        setStatus(`Showing document ${document.id}.`);

        renderInfo(document);
        renderMetrics(document);
        renderMarkdown(document);
        renderRelatedJobs(document.relatedJobs || []);
        setText(els.metadata, JSON.stringify(document.metadata || {}, null, 2));
        renderDocuments(state.documents);
    }

    function renderInfo(document) {
        els.info.innerHTML = '';

        [
            ['Dataset ID', makeLink(`/documents?dataset_id=${encodeURIComponent(document.datasetId || '')}`, document.datasetId)],
            ['Task ID', makeLink(taskLink(document.taskId), document.taskId)],
            ['Job ID', makeLink(taskLink(document.taskId), document.jobId)],
            ['Source URL', document.sourceUrl],
            ['Local path', document.localPath],
            ['Content type', document.contentType],
            ['Content hash', document.contentHash],
            ['Qdrant status', statusPill(document.qdrantStatus)],
            ['Neo4j status', statusPill(document.neo4jStatus)],
            ['Ingested at', formatDate(document.ingestedAt)],
            ['Qdrant collection', document.qdrantCollection || document.collection],
            ['Neo4j namespace', document.neo4jNamespace],
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
            els.info.appendChild(wrapper);
        });
    }

    function renderMetrics(document) {
        els.metrics.innerHTML = '';
        [
            ['Qdrant points', document.qdrantPointCount ?? '-', document.qdrantCollection || document.collection],
            ['Neo4j entities', document.neo4jEntityCount ?? '-', document.neo4jNamespace || document.neo4jStatus],
            ['Neo4j relations', document.neo4jRelationCount ?? '-', document.neo4jNamespace || document.neo4jStatus],
            ['File size', document.fileSize ? `${document.fileSize} bytes` : '-', document.contentType],
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

    function renderMarkdown(document) {
        const preview = document.markdownPreview || '';
        if (preview) {
            setText(els.markdownPreview, preview);
            setText(
                els.previewNote,
                document.markdownPreviewTruncated
                    ? `Preview is truncated from ${document.markdownPreviewPath || document.localPath}.`
                    : `Preview from ${document.markdownPreviewPath || document.localPath}.`,
            );
            els.markdownPreview.dataset.empty = 'false';
            return;
        }

        setText(els.markdownPreview, document.markdownPreviewError || 'No extracted Markdown preview is available.');
        setText(els.previewNote, document.markdownPreviewError || 'Preview reads the recorded local path.');
        els.markdownPreview.dataset.empty = 'true';
    }

    function renderRelatedJobs(jobs) {
        setText(els.jobsCount, `${jobs.length} job${jobs.length === 1 ? '' : 's'} shown`);
        renderTable(els.relatedJobs, ['Job', 'Task', 'Type', 'Status', 'Source URL', 'Path', 'Error', 'Finished'], jobs, (job) => [
            makeLink(taskLink(job.taskId), job.jobId),
            makeLink(taskLink(job.taskId), job.taskId),
            job.jobType,
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

    function clearDetail() {
        setStatus('No documents found.');
        els.state.className = 'status-pill is-idle';
        setText(els.state, 'idle');
        setText(els.updated, 'No document loaded.');
        [els.info, els.metrics, els.relatedJobs].filter(Boolean).forEach((container) => {
            container.innerHTML = '';
            renderEmpty(container, 'Nothing to show yet.');
        });
        setText(els.markdownPreview, 'No extracted Markdown preview is available.');
        els.markdownPreview.dataset.empty = 'true';
        setText(els.metadata, '{}');
        setText(els.jobsCount, '0 jobs');
    }

    async function loadDocuments({ keepSelection = true } = {}) {
        const requestId = ++state.requestId;
        const params = new URLSearchParams({ limit: '150' });
        if (state.datasetId) params.set('dataset_id', state.datasetId);
        if (state.search) params.set('q', state.search);

        const data = await requestJson(`api/documents?${params.toString()}`);
        if (requestId !== state.requestId) return;

        state.documents = Array.isArray(data.documents) ? data.documents : [];
        if (!keepSelection || !state.documents.some((document) => document.id === state.selectedDocumentId)) {
            state.selectedDocumentId = state.documents[0]?.id || '';
        }

        renderDocuments(state.documents);

        if (state.selectedDocumentId) {
            await loadDocument(state.selectedDocumentId, { renderList: false });
        } else {
            clearDetail();
        }
    }

    async function loadDocument(documentId, { renderList = true } = {}) {
        if (!documentId) return;
        state.selectedDocumentId = documentId;
        localStorage.setItem('hawkiDocumentsDashboardDocumentId', documentId);
        setStatus(`Loading document ${documentId}...`);

        const data = await requestJson(`api/documents/${encodeURIComponent(documentId)}`);
        renderDocument(data.document);

        if (renderList) {
            renderDocuments(state.documents);
        }
    }

    els.filters?.addEventListener('submit', async (event) => {
        event.preventDefault();
        state.datasetId = els.datasetFilter?.value.trim() || '';
        state.search = els.searchFilter?.value.trim() || '';
        localStorage.setItem('hawkiDocumentsDashboardDatasetId', state.datasetId);
        state.selectedDocumentId = '';

        try {
            setStatus('Loading filtered documents...');
            await loadDocuments({ keepSelection: false });
        } catch (error) {
            setStatus(error.message || 'Filter failed.', 'error');
            clearDetail();
        }
    });

    els.refresh?.addEventListener('click', async () => {
        try {
            setStatus('Refreshing documents...');
            await loadDocuments({ keepSelection: true });
            setStatus('Documents refreshed.', 'success');
        } catch (error) {
            setStatus(error.message || 'Refresh failed.', 'error');
        }
    });

    root.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="/documents?document_id="]');
        if (!link) return;
        event.preventDefault();
        const next = new URL(link.href, window.location.origin);
        loadDocument(next.searchParams.get('document_id')).catch((error) => setStatus(error.message || 'Document load failed.', 'error'));
    });

    loadDocuments({ keepSelection: true }).catch((error) => {
        setStatus(error.message || 'Could not load documents.', 'error');
        clearDetail();
    });
}
