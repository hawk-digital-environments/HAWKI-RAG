import { apiUrl, pageUrl } from './playground/urls.js';

const root = document.querySelector('[data-task-manager]');

if (root) {
    const url = new URL(window.location.href);
    const pathTaskId = document.querySelector('meta[name="hawki-selected-task-id"]')?.getAttribute('content') || '';
    const els = {
        refresh: document.getElementById('task-manager-refresh'),
        total: document.getElementById('task-manager-total'),
        queued: document.getElementById('task-manager-queued'),
        processing: document.getElementById('task-manager-processing'),
        failed: document.getElementById('task-manager-failed'),
        completed: document.getElementById('task-manager-completed'),
        taskCount: document.getElementById('task-manager-task-count'),
        taskList: document.getElementById('task-manager-task-list'),
        search: document.getElementById('task-manager-search'),
        statusFilter: document.getElementById('task-manager-status-filter'),
        status: document.getElementById('task-manager-status'),
        title: document.getElementById('task-manager-title'),
        updated: document.getElementById('task-manager-updated'),
        taskStatus: document.getElementById('task-manager-task-status'),
        info: document.getElementById('task-manager-info'),
        stages: document.getElementById('task-manager-stages'),
        counters: document.getElementById('task-manager-counters'),
        jobs: document.getElementById('task-manager-jobs'),
        jobsCount: document.getElementById('task-manager-jobs-count'),
        jobTypeFilter: document.getElementById('task-manager-job-type-filter'),
        documents: document.getElementById('task-manager-documents'),
        documentsCount: document.getElementById('task-manager-documents-count'),
        documentsLink: document.getElementById('task-manager-documents-link'),
        failedJobs: document.getElementById('task-manager-failed-jobs'),
        failedCount: document.getElementById('task-manager-failed-count'),
        retrySelected: document.getElementById('task-manager-retry-selected'),
        retryTask: document.getElementById('task-manager-retry-task'),
        events: document.getElementById('task-manager-events'),
        eventsCount: document.getElementById('task-manager-events-count'),
        eventTypeFilter: document.getElementById('task-manager-event-type-filter'),
        eventJobFilter: document.getElementById('task-manager-event-job-filter'),
    };

    const counterLabels = [
        ['queued', 'Queued'],
        ['scraped', 'Scraped'],
        ['files_found', 'Files found'],
        ['converted', 'Converted'],
        ['ingested', 'Ingested'],
        ['skipped', 'Skipped'],
        ['failed', 'Failed'],
        ['jobs_total', 'Total jobs'],
    ];

    const stageDefinitions = [
        ['scrape', 'Scrape'],
        ['convert', 'Convert'],
        ['ingest', 'Ingest'],
        ['graph', 'Graph'],
    ];

    const state = {
        selectedTaskId: pathTaskId || url.searchParams.get('task_id') || url.searchParams.get('taskId') || localStorage.getItem('hawkiTaskManagerTaskId') || '',
        activeTab: url.searchParams.get('tab') || localStorage.getItem('hawkiTaskManagerTab') || 'overview',
        taskSearch: url.searchParams.get('q') || '',
        taskStatus: url.searchParams.get('status') || '',
        jobTypeFilter: '',
        eventTypeFilter: '',
        eventJobFilter: '',
        tasks: [],
        selectedTask: null,
        failedJobs: [],
        documents: [],
        events: [],
        selectedFailedJobs: new Set(),
        requestId: 0,
        pollTimer: null,
    };

    if (els.search) els.search.value = state.taskSearch;
    if (els.statusFilter) els.statusFilter.value = state.taskStatus;

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

    function setText(el, value) {
        if (el) el.textContent = value;
    }

    function setStatus(message, tone = 'neutral') {
        if (!els.status) return;
        els.status.textContent = message;
        els.status.dataset.tone = tone;
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
        if (['failed', 'cancelled', 'partial'].includes(value)) return 'is-failed';
        if (['skipped', 'archived', 'disabled'].includes(value)) return 'is-skipped';
        return 'is-idle';
    }

    function statusPill(status) {
        const pill = document.createElement('span');
        pill.className = `status-pill ${statusClass(status)}`;
        pill.textContent = valueOrDash(status);
        return pill;
    }

    function taskLabel(task) {
        const metadata = task?.metadata || {};
        const request = metadata.request || {};
        const requestMetadata = request.metadata || {};
        return requestMetadata.catalog_task_label
            || requestMetadata.label
            || metadata.label
            || task?.taskId
            || 'Pipeline task';
    }

    function taskSearchText(task) {
        const jobs = Array.isArray(task.jobs) ? task.jobs : [];
        return [
            task.taskId,
            task.datasetId,
            task.status,
            taskLabel(task),
            ...jobs.flatMap((job) => [job.jobId, job.sourceUrl, job.localPath, job.errorMessage]),
        ].filter(Boolean).join(' ').toLowerCase();
    }

    function filteredTasks() {
        const query = state.taskSearch.trim().toLowerCase();
        const status = state.taskStatus.trim().toLowerCase();
        return state.tasks.filter((task) => {
            if (status && String(task.status || '').toLowerCase() !== status) {
                return false;
            }
            if (query && !taskSearchText(task).includes(query)) {
                return false;
            }
            return true;
        });
    }

    function summarizeTasks(tasks) {
        const summary = { total: tasks.length, queued: 0, processing: 0, failed: 0, completed: 0 };
        tasks.forEach((task) => {
            const status = String(task.status || '').toLowerCase();
            if (status === 'queued' || status === 'pending') summary.queued += 1;
            else if (status === 'running' || status === 'processing') summary.processing += 1;
            else if (status === 'failed') summary.failed += 1;
            else if (status === 'completed') summary.completed += 1;
        });
        return summary;
    }

    function renderSummary() {
        const summary = summarizeTasks(state.tasks);
        setText(els.total, summary.total);
        setText(els.queued, summary.queued);
        setText(els.processing, summary.processing);
        setText(els.failed, summary.failed);
        setText(els.completed, summary.completed);
    }

    function renderTasks() {
        if (!els.taskList) return;
        els.taskList.innerHTML = '';
        const tasks = filteredTasks();
        setText(els.taskCount, `${tasks.length} task${tasks.length === 1 ? '' : 's'} shown`);

        if (tasks.length === 0) {
            renderEmpty(els.taskList, 'No matching tasks.');
            return;
        }

        tasks.forEach((task) => {
            const counters = task.counters || {};
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'task-list-item';
            if (task.taskId === state.selectedTaskId) button.classList.add('is-selected');

            const top = document.createElement('span');
            top.className = 'task-list-top';
            const title = document.createElement('strong');
            title.textContent = taskLabel(task);
            top.append(title, statusPill(task.status));

            const id = document.createElement('span');
            id.className = 'task-list-id';
            id.textContent = task.taskId;

            const meta = document.createElement('span');
            meta.className = 'task-list-meta';
            meta.textContent = [
                task.datasetId ? `dataset ${task.datasetId}` : null,
                `${counters.jobs_total || 0} jobs`,
                counters.failed ? `${counters.failed} failed` : null,
                task.activeJobs ? `${task.activeJobs} active` : null,
            ].filter(Boolean).join(' | ');

            button.append(top, id, meta);
            button.addEventListener('click', () => loadTask(task.taskId));
            els.taskList.appendChild(button);
        });
    }

    function renderTask(task) {
        state.selectedTask = task;
        const jobs = Array.isArray(task.jobs) ? task.jobs : [];
        const failedJobs = state.failedJobs;

        setText(els.title, taskLabel(task));
        setText(els.updated, task.updatedAt ? `Updated ${formatDate(task.updatedAt)}` : 'Updated from database');
        els.taskStatus.className = `status-pill ${statusClass(task.status)}`;
        setText(els.taskStatus, task.status || 'unknown');

        renderInfo(task);
        renderStages(jobs);
        renderCounters(task.counters || {});
        renderJobs(jobs);
        renderDocuments(state.documents);
        renderFailedJobs(failedJobs);
        renderEventFilters(jobs, state.events);
        renderEvents(state.events);
        updateRetryButtons();

        setStatus(`Showing task ${task.taskId}.`, task.status === 'failed' ? 'error' : 'neutral');
    }

    function renderInfo(task) {
        if (!els.info) return;
        els.info.innerHTML = '';
        [
            ['Task ID', task.taskId],
            ['Dataset', task.datasetId],
            ['Status', task.status],
            ['Active jobs', task.activeJobs],
            ['Started', formatDate(task.startedAt)],
            ['Finished', formatDate(task.finishedAt)],
            ['Task URL', task.taskId ? pageUrl(`tasks/${encodeURIComponent(task.taskId)}`) : ''],
        ].forEach(([label, value]) => {
            const wrapper = document.createElement('div');
            const term = document.createElement('dt');
            const description = document.createElement('dd');
            term.textContent = label;
            if (label === 'Task URL' && value) {
                description.appendChild(makeLink(value, value));
            } else {
                description.textContent = valueOrDash(value);
            }
            wrapper.append(term, description);
            els.info.appendChild(wrapper);
        });
    }

    function renderStages(jobs) {
        if (!els.stages) return;
        els.stages.innerHTML = '';
        stageDefinitions.forEach(([type, label]) => {
            const stageJobs = jobs.filter((job) => job.jobType === type);
            const completed = stageJobs.filter((job) => String(job.status).toLowerCase() === 'completed').length;
            const failed = stageJobs.filter((job) => String(job.status).toLowerCase() === 'failed').length;
            const running = stageJobs.filter((job) => ['queued', 'running', 'pending', 'processing'].includes(String(job.status).toLowerCase())).length;
            const total = stageJobs.length;
            const progress = total > 0 ? Math.round((completed / total) * 100) : 0;

            const card = document.createElement('div');
            card.className = 'stage-card';
            const title = document.createElement('strong');
            title.textContent = label;
            const meta = document.createElement('span');
            meta.textContent = `${completed}/${total} completed | ${running} active | ${failed} failed`;
            const meter = document.createElement('div');
            meter.className = 'stage-meter';
            const bar = document.createElement('span');
            bar.style.width = `${progress}%`;
            meter.appendChild(bar);
            card.append(title, meta, meter);
            els.stages.appendChild(card);
        });
    }

    function renderCounters(counters) {
        if (!els.counters) return;
        els.counters.innerHTML = '';
        counterLabels.forEach(([key, label]) => {
            const item = document.createElement('div');
            item.className = 'counter-item';
            const value = document.createElement('strong');
            value.textContent = String(counters[key] ?? 0);
            const caption = document.createElement('span');
            caption.textContent = label;
            item.append(value, caption);
            els.counters.appendChild(item);
        });
    }

    function renderJobs(jobs) {
        const filtered = state.jobTypeFilter ? jobs.filter((job) => job.jobType === state.jobTypeFilter) : jobs;
        setText(els.jobsCount, `${filtered.length} job${filtered.length === 1 ? '' : 's'} shown`);
        renderTable(els.jobs, ['Job', 'Type', 'Status', 'Workflow', 'Schedule', 'Source', 'Path', 'Error', 'Started', 'Finished'], filtered, (job) => [
            mono(job.jobId),
            job.jobType,
            statusPill(job.status),
            mono(job.temporalWorkflowId),
            mono(job.temporalScheduleId),
            wrap(job.sourceUrl),
            wrap(job.localPath),
            errorText(job.errorMessage),
            formatDate(job.startedAt),
            formatDate(job.finishedAt),
        ]);
    }

    function renderDocuments(documents) {
        setText(els.documentsCount, `${documents.length} document${documents.length === 1 ? '' : 's'} shown`);
        if (els.documentsLink) {
            const datasetId = state.selectedTask?.datasetId || '';
            els.documentsLink.href = datasetId ? pageUrl(`documents?dataset_id=${encodeURIComponent(datasetId)}`) : pageUrl('documents');
        }
        renderTable(els.documents, ['Document', 'Status', 'Qdrant', 'Neo4j', 'Source', 'Job', 'Updated'], documents, (doc) => [
            makeLink(`/documents?document_id=${encodeURIComponent(doc.id)}`, doc.title || doc.originalFilename || doc.id),
            statusPill(doc.status),
            statusPill(doc.qdrantStatus),
            statusPill(doc.neo4jStatus),
            wrap(doc.sourceUrl || doc.localPath),
            mono(doc.jobId),
            formatDate(doc.updatedAt || doc.ingestedAt),
        ], 'No documents are linked to this task yet.');
    }

    function renderFailedJobs(failedJobs) {
        setText(els.failedCount, `${failedJobs.length} failed job${failedJobs.length === 1 ? '' : 's'}`);
        renderTable(els.failedJobs, ['', 'Job', 'Type', 'Error', 'Source', 'Retry', 'Finished', 'Action'], failedJobs, (job) => [
            failedCheckbox(job),
            mono(job.jobId),
            job.jobType,
            errorText(job.errorMessage),
            wrap(job.sourceUrl || job.localPath),
            String(job.metadata?.retry_count ?? job.retryCount ?? 0),
            formatDate(job.finishedAt || job.timestamp),
            retryJobButton(job),
        ], 'No failed jobs for this task.');
    }

    function renderEvents(events) {
        if (!els.events) return;
        els.events.innerHTML = '';
        setText(els.eventsCount, `${events.length} event${events.length === 1 ? '' : 's'} shown`);

        if (events.length === 0) {
            renderEmpty(els.events, 'No events recorded for this filter.');
            return;
        }

        events.forEach((event) => {
            const row = document.createElement('article');
            row.className = 'event-row';
            if (event.eventType === 'job.failed' || event.errorMessage) row.classList.add('is-error');

            const marker = document.createElement('span');
            marker.className = 'event-marker';

            const main = document.createElement('div');
            main.className = 'event-main';
            const title = document.createElement('strong');
            title.textContent = event.message || titleForEvent(event.eventType);
            const detail = document.createElement('span');
            detail.textContent = [
                event.eventType,
                event.jobId,
                event.source,
                event.sourceUrl,
                event.localPath,
            ].filter(Boolean).join(' | ');
            main.append(title, detail);

            const side = document.createElement('div');
            side.className = 'event-side';
            const time = document.createElement('span');
            time.textContent = formatDate(event.at);
            side.append(statusPill(event.status), time);

            row.append(marker, main, side);
            if (event.errorMessage) {
                const error = document.createElement('p');
                error.className = 'event-error';
                error.textContent = event.errorMessage;
                row.appendChild(error);
            }
            els.events.appendChild(row);
        });
    }

    function renderEventFilters(jobs, events) {
        renderSelectOptions(
            els.eventTypeFilter,
            'All event types',
            uniqueValues(events.map((event) => event.eventType)),
            state.eventTypeFilter,
        );
        renderSelectOptions(
            els.eventJobFilter,
            'All jobs',
            uniqueValues([...jobs.map((job) => job.jobId), ...events.map((event) => event.jobId)]),
            state.eventJobFilter,
        );
    }

    function renderSelectOptions(select, emptyLabel, values, selectedValue) {
        if (!select) return;
        select.innerHTML = '';
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = emptyLabel;
        select.appendChild(empty);
        values.forEach((value) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            select.appendChild(option);
        });
        select.value = values.includes(selectedValue) ? selectedValue : '';
    }

    function renderTable(container, headers, rows, mapRow, emptyMessage = 'No rows to show.') {
        if (!container) return;
        container.innerHTML = '';
        if (!Array.isArray(rows) || rows.length === 0) {
            renderEmpty(container, emptyMessage);
            return;
        }

        const table = document.createElement('table');
        table.className = 'manager-table';
        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        headers.forEach((header) => {
            const th = document.createElement('th');
            th.textContent = header;
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        rows.forEach((rowData) => {
            const tr = document.createElement('tr');
            mapRow(rowData).forEach((value) => appendCell(tr, value));
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        container.appendChild(table);
    }

    function appendCell(row, value) {
        const td = document.createElement('td');
        if (value instanceof Node) {
            td.appendChild(value);
        } else {
            td.textContent = valueOrDash(value);
        }
        row.appendChild(td);
    }

    function makeLink(href, text) {
        if (!href || !text) return document.createTextNode(valueOrDash(text));
        const link = document.createElement('a');
        link.href = href;
        link.textContent = text;
        link.className = 'table-link';
        return link;
    }

    function mono(value) {
        const span = document.createElement('span');
        span.className = 'mono';
        span.textContent = valueOrDash(value);
        return span;
    }

    function wrap(value) {
        const span = document.createElement('span');
        span.className = 'wrap';
        span.textContent = valueOrDash(value);
        return span;
    }

    function errorText(value) {
        const span = document.createElement('span');
        span.className = value ? 'error wrap' : 'wrap';
        span.textContent = valueOrDash(value);
        return span;
    }

    function failedCheckbox(job) {
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.value = job.jobId;
        checkbox.checked = state.selectedFailedJobs.has(job.jobId);
        checkbox.addEventListener('change', () => {
            if (checkbox.checked) {
                state.selectedFailedJobs.add(job.jobId);
            } else {
                state.selectedFailedJobs.delete(job.jobId);
            }
            updateRetryButtons();
        });
        return checkbox;
    }

    function retryJobButton(job) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'secondary-button';
        button.textContent = 'Retry';
        button.addEventListener('click', () => retryJob(job.jobId));
        return button;
    }

    function renderEmpty(container, message) {
        const empty = document.createElement('div');
        empty.className = 'empty-state';
        empty.textContent = message;
        container.appendChild(empty);
    }

    function titleForEvent(eventType) {
        return {
            'scrape.requested': 'Scrape queued',
            'scrape.monitor.requested': 'Scrape monitor queued',
            'page.scraped': 'Page scraped',
            'file.discovered': 'File discovered',
            'file.converted': 'File converted',
            'content.ingested': 'Content ingested',
            'job.failed': 'Job failed',
        }[eventType] || eventType || 'Pipeline event';
    }

    function uniqueValues(values) {
        return Array.from(new Set(values.filter((value) => typeof value === 'string' && value.trim() !== '')))
            .sort((a, b) => a.localeCompare(b));
    }

    function updateRetryButtons() {
        const failedCount = state.failedJobs.length;
        if (els.retryTask) {
            els.retryTask.disabled = !state.selectedTaskId || failedCount === 0;
        }
        if (els.retrySelected) {
            els.retrySelected.disabled = state.selectedFailedJobs.size === 0;
        }
    }

    function updateUrl() {
        const path = state.selectedTaskId ? `tasks/${encodeURIComponent(state.selectedTaskId)}` : 'tasks';
        const next = new URL(pageUrl(path));
        next.search = '';
        if (state.activeTab && state.activeTab !== 'overview') next.searchParams.set('tab', state.activeTab);
        if (state.taskSearch) next.searchParams.set('q', state.taskSearch);
        if (state.taskStatus) next.searchParams.set('status', state.taskStatus);
        window.history.replaceState({}, '', next.toString());
    }

    async function loadTasks({ keepSelection = true } = {}) {
        const requestId = ++state.requestId;
        const data = await requestJson('api/pipeline/tasks?limit=80');
        if (requestId !== state.requestId) return;
        state.tasks = Array.isArray(data.tasks) ? data.tasks : [];
        renderSummary();

        const exists = state.tasks.some((task) => task.taskId === state.selectedTaskId);
        if (!keepSelection || !exists) {
            state.selectedTaskId = state.tasks[0]?.taskId || '';
        }

        renderTasks();
        if (state.selectedTaskId) {
            await loadTask(state.selectedTaskId, { renderTaskList: false });
        } else {
            clearTask();
        }
    }

    async function loadTask(taskId, { renderTaskList = true } = {}) {
        if (!taskId) return;
        state.selectedTaskId = taskId;
        localStorage.setItem('hawkiTaskManagerTaskId', taskId);
        setStatus(`Loading task ${taskId}...`);

        const [taskData, failedData, eventsData] = await Promise.all([
            requestJson(`api/pipeline/tasks/${encodeURIComponent(taskId)}`),
            requestJson(`api/pipeline/tasks/${encodeURIComponent(taskId)}/failed-jobs`),
            requestJson(eventsPath(taskId)),
        ]);

        state.failedJobs = Array.isArray(failedData.jobs) ? failedData.jobs : [];
        state.selectedFailedJobs = new Set(
            Array.from(state.selectedFailedJobs).filter((jobId) => state.failedJobs.some((job) => job.jobId === jobId)),
        );
        state.events = Array.isArray(eventsData.events) ? eventsData.events : [];
        state.selectedTask = taskData.task || null;
        state.documents = await loadDocumentsForTask(state.selectedTask);
        renderTask(state.selectedTask);
        if (renderTaskList) renderTasks();
        updateUrl();
    }

    async function loadDocumentsForTask(task) {
        if (!task?.datasetId) return [];
        try {
            const params = new URLSearchParams({ dataset_id: task.datasetId, limit: '250' });
            const data = await requestJson(`api/documents?${params.toString()}`);
            const documents = Array.isArray(data.documents) ? data.documents : [];
            return documents.filter((doc) => {
                if (doc.taskId) return doc.taskId === task.taskId;
                return doc.datasetId === task.datasetId;
            });
        } catch {
            return [];
        }
    }

    function eventsPath(taskId) {
        const params = new URLSearchParams({ limit: '200' });
        if (state.eventTypeFilter) params.set('event_type', state.eventTypeFilter);
        if (state.eventJobFilter) params.set('job_id', state.eventJobFilter);
        return `api/pipeline/tasks/${encodeURIComponent(taskId)}/events?${params.toString()}`;
    }

    function clearTask() {
        state.selectedTask = null;
        state.failedJobs = [];
        state.documents = [];
        state.events = [];
        setStatus('No pipeline tasks found.');
        setText(els.title, 'No task selected');
        setText(els.updated, 'Waiting for task data.');
        els.taskStatus.className = 'status-pill is-idle';
        setText(els.taskStatus, 'idle');
        [
            [els.info, 'No task selected.'],
            [els.stages, 'No stage data.'],
            [els.counters, 'No counters.'],
            [els.jobs, 'No jobs.'],
            [els.documents, 'No documents.'],
            [els.failedJobs, 'No failed jobs.'],
            [els.events, 'No events.'],
        ].forEach(([container, message]) => {
            if (!container) return;
            container.innerHTML = '';
            renderEmpty(container, message);
        });
        [els.jobsCount, els.documentsCount, els.failedCount, els.eventsCount].forEach((el) => setText(el, '0'));
        updateRetryButtons();
    }

    function setActiveTab(tab) {
        const nextTab = ['overview', 'jobs', 'documents', 'failed', 'events'].includes(tab) ? tab : 'overview';
        state.activeTab = nextTab;
        localStorage.setItem('hawkiTaskManagerTab', nextTab);
        document.querySelectorAll('[data-task-tab]').forEach((button) => {
            button.classList.toggle('is-active', button.dataset.taskTab === nextTab);
        });
        document.querySelectorAll('[data-task-panel]').forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.taskPanel === nextTab);
        });
        updateUrl();
    }

    async function retryJob(jobId) {
        if (!jobId) return;
        setStatus(`Retrying ${jobId}...`);
        await requestJson(`api/pipeline/recovery/jobs/${encodeURIComponent(jobId)}/retry`, { method: 'POST' });
        state.selectedFailedJobs.delete(jobId);
        await loadTasks({ keepSelection: true });
        setStatus(`Queued retry for ${jobId}.`, 'success');
    }

    async function retrySelected() {
        const jobIds = Array.from(state.selectedFailedJobs);
        if (jobIds.length === 0) return;
        setStatus(`Retrying ${jobIds.length} selected job${jobIds.length === 1 ? '' : 's'}...`);
        await requestJson('api/pipeline/recovery/jobs/retry-selected', {
            method: 'POST',
            body: JSON.stringify({ job_ids: jobIds }),
        });
        state.selectedFailedJobs.clear();
        await loadTasks({ keepSelection: true });
        setStatus('Queued selected failed jobs.', 'success');
    }

    async function retryTask() {
        if (!state.selectedTaskId) return;
        setStatus(`Retrying failed jobs for ${state.selectedTaskId}...`);
        await requestJson(`api/pipeline/recovery/tasks/${encodeURIComponent(state.selectedTaskId)}/retry-failed`, {
            method: 'POST',
        });
        state.selectedFailedJobs.clear();
        await loadTasks({ keepSelection: true });
        setStatus(`Queued failed jobs for ${state.selectedTaskId}.`, 'success');
    }

    function startPolling() {
        if (state.pollTimer) clearInterval(state.pollTimer);
        state.pollTimer = setInterval(() => {
            if (!state.selectedTaskId) return;
            loadTask(state.selectedTaskId).catch(() => {});
        }, 5000);
    }

    els.refresh?.addEventListener('click', () => {
        setStatus('Refreshing task manager...');
        loadTasks({ keepSelection: true })
            .then(() => setStatus('Task manager refreshed.', 'success'))
            .catch((error) => setStatus(error.message || 'Refresh failed.', 'error'));
    });

    els.search?.addEventListener('input', () => {
        state.taskSearch = els.search.value;
        renderTasks();
        updateUrl();
    });

    els.statusFilter?.addEventListener('change', () => {
        state.taskStatus = els.statusFilter.value;
        renderTasks();
        updateUrl();
    });

    els.jobTypeFilter?.addEventListener('change', () => {
        state.jobTypeFilter = els.jobTypeFilter.value;
        renderJobs(Array.isArray(state.selectedTask?.jobs) ? state.selectedTask.jobs : []);
    });

    els.eventTypeFilter?.addEventListener('change', () => {
        state.eventTypeFilter = els.eventTypeFilter.value;
        if (state.selectedTaskId) {
            loadTask(state.selectedTaskId).catch((error) => setStatus(error.message || 'Event refresh failed.', 'error'));
        }
    });

    els.eventJobFilter?.addEventListener('change', () => {
        state.eventJobFilter = els.eventJobFilter.value;
        if (state.selectedTaskId) {
            loadTask(state.selectedTaskId).catch((error) => setStatus(error.message || 'Event refresh failed.', 'error'));
        }
    });

    els.retrySelected?.addEventListener('click', () => {
        retrySelected().catch((error) => setStatus(error.message || 'Selected retry failed.', 'error'));
    });

    els.retryTask?.addEventListener('click', () => {
        retryTask().catch((error) => setStatus(error.message || 'Task retry failed.', 'error'));
    });

    document.querySelectorAll('[data-task-tab]').forEach((button) => {
        button.addEventListener('click', () => setActiveTab(button.dataset.taskTab || 'overview'));
    });

    setActiveTab(state.activeTab);
    loadTasks({ keepSelection: true })
        .then(startPolling)
        .catch((error) => {
            setStatus(error.message || 'Could not load pipeline tasks.', 'error');
            clearTask();
        });
}
