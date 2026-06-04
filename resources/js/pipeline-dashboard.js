import { apiUrl } from './playground/urls.js';

const root = document.querySelector('[data-pipeline-dashboard]');

if (root) {
    const query = new URLSearchParams(window.location.search);
    const els = {
        refresh: document.getElementById('pipeline-dashboard-refresh'),
        retry: document.getElementById('pipeline-dashboard-retry'),
        status: document.getElementById('pipeline-dashboard-status'),
        taskCount: document.getElementById('pipeline-dashboard-task-count'),
        taskList: document.getElementById('pipeline-dashboard-task-list'),
        taskStatus: document.getElementById('pipeline-dashboard-task-status'),
        taskInfo: document.getElementById('pipeline-dashboard-task-info'),
        updated: document.getElementById('pipeline-dashboard-updated'),
        counters: document.getElementById('pipeline-dashboard-counters'),
        scrapeJobs: document.getElementById('pipeline-dashboard-scrape-jobs'),
        convertJobs: document.getElementById('pipeline-dashboard-convert-jobs'),
        ingestJobs: document.getElementById('pipeline-dashboard-ingest-jobs'),
        failedJobs: document.getElementById('pipeline-dashboard-failed-jobs'),
        scrapeCount: document.getElementById('pipeline-dashboard-scrape-count'),
        convertCount: document.getElementById('pipeline-dashboard-convert-count'),
        ingestCount: document.getElementById('pipeline-dashboard-ingest-count'),
        failedCount: document.getElementById('pipeline-dashboard-failed-count'),
        events: document.getElementById('pipeline-dashboard-events'),
        eventsCount: document.getElementById('pipeline-dashboard-events-count'),
        eventTypeFilter: document.getElementById('pipeline-dashboard-event-type-filter'),
        jobFilter: document.getElementById('pipeline-dashboard-job-filter'),
    };

    const state = {
        selectedTaskId: query.get('task_id') || query.get('taskId') || localStorage.getItem('hawkiPipelineDashboardTaskId') || '',
        tasks: [],
        failedJobs: [],
        eventTypeFilter: '',
        jobFilter: '',
        pollTimer: null,
        requestId: 0,
    };

    const pipelineEventTypes = [
        'scrape.requested',
        'page.scraped',
        'file.discovered',
        'file.converted',
        'content.ingested',
        'job.failed',
    ];

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
        if (value === 'completed') return 'is-completed';
        if (value === 'running' || value === 'queued' || value === 'pending') return 'is-running';
        if (value === 'failed') return 'is-failed';
        if (value === 'skipped') return 'is-skipped';

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

    function renderTasks(tasks) {
        if (!els.taskList) return;
        els.taskList.innerHTML = '';
        setText(els.taskCount, `${tasks.length} task${tasks.length === 1 ? '' : 's'}`);

        if (tasks.length === 0) {
            renderEmpty(els.taskList, 'No pipeline tasks yet.');
            return;
        }

        tasks.forEach((task) => {
            const counters = task.counters || {};
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'task-list-item';
            if (task.taskId === state.selectedTaskId) {
                button.classList.add('is-selected');
            }

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
            ].filter(Boolean).join(' | ');

            button.append(top, id, meta);
            button.addEventListener('click', () => loadTask(task.taskId));
            els.taskList.appendChild(button);
        });
    }

    function renderTask(task, failedJobs, events, eventFilters = {}) {
        const jobs = Array.isArray(task.jobs) ? task.jobs : [];
        const scrapeJobs = jobs.filter((job) => job.jobType === 'scrape');
        const convertJobs = jobs.filter((job) => job.jobType === 'convert');
        const ingestJobs = jobs.filter((job) => job.jobType === 'ingest');

        els.taskStatus.className = `status-pill ${statusClass(task.status)}`;
        setText(els.taskStatus, task.status || 'unknown');
        setText(els.updated, task.updatedAt ? `Updated ${formatDate(task.updatedAt)}` : 'Updated from database');

        renderTaskInfo(task);
        renderCounters(task.counters || {});
        renderJobTable(els.scrapeJobs, els.scrapeCount, scrapeJobs);
        renderJobTable(els.convertJobs, els.convertCount, convertJobs);
        renderJobTable(els.ingestJobs, els.ingestCount, ingestJobs);
        renderJobTable(els.failedJobs, els.failedCount, failedJobs);
        renderTimelineFilters(jobs, events, eventFilters);
        renderEvents(events);

        els.retry.disabled = failedJobs.length === 0 || !state.selectedTaskId;
        setStatus(`Showing task ${task.taskId}.`, task.status === 'failed' ? 'error' : 'neutral');
    }

    function renderTaskInfo(task) {
        if (!els.taskInfo) return;
        els.taskInfo.innerHTML = '';
        [
            ['Task ID', task.taskId],
            ['Dataset ID', task.datasetId],
            ['Status', task.status],
            ['Active jobs', task.activeJobs],
            ['Started', formatDate(task.startedAt)],
            ['Finished', formatDate(task.finishedAt)],
        ].forEach(([label, value]) => {
            const wrapper = document.createElement('div');
            const term = document.createElement('dt');
            const description = document.createElement('dd');
            term.textContent = label;
            description.textContent = valueOrDash(value);
            wrapper.append(term, description);
            els.taskInfo.appendChild(wrapper);
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

    function renderJobTable(container, countEl, jobs) {
        if (!container) return;
        container.innerHTML = '';
        setText(countEl, `${jobs.length} job${jobs.length === 1 ? '' : 's'}`);

        if (jobs.length === 0) {
            renderEmpty(container, 'No jobs in this stage yet.');
            return;
        }

        const table = document.createElement('table');
        table.className = 'job-table';
        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        ['Job', 'Type', 'Status', 'Source URL', 'Local path', 'Error', 'Started', 'Finished'].forEach((label) => {
            const th = document.createElement('th');
            th.textContent = label;
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        jobs.forEach((job) => {
            const row = document.createElement('tr');
            appendCell(row, job.jobId, 'mono');
            appendCell(row, job.jobType);
            const statusCell = document.createElement('td');
            statusCell.appendChild(statusPill(job.status));
            row.appendChild(statusCell);
            appendCell(row, job.sourceUrl, 'wrap');
            appendCell(row, job.localPath, 'wrap');
            appendCell(row, job.errorMessage, 'error wrap');
            appendCell(row, formatDate(job.startedAt));
            appendCell(row, formatDate(job.finishedAt));
            tbody.appendChild(row);
        });

        table.appendChild(tbody);
        container.appendChild(table);
    }

    function appendCell(row, value, className = '') {
        const td = document.createElement('td');
        td.textContent = valueOrDash(value);
        if (className) td.className = className;
        row.appendChild(td);
    }

    function renderEvents(events) {
        if (!els.events) return;
        els.events.innerHTML = '';
        setText(els.eventsCount, `${events.length} event${events.length === 1 ? '' : 's'} shown`);

        if (events.length === 0) {
            renderEmpty(els.events, 'No events recorded yet.');
            return;
        }

        events.forEach((event) => {
            const row = document.createElement('article');
            row.className = 'event-row';
            if (event.eventType === 'job.failed' || event.errorMessage) {
                row.classList.add('is-error');
            }

            const marker = document.createElement('span');
            marker.className = 'event-marker';

            const main = document.createElement('div');
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

    function titleForEvent(eventType) {
        return {
            'scrape.requested': 'URL queued',
            'page.scraped': 'Page scraped',
            'file.discovered': 'File discovered',
            'file.converted': 'File converted',
            'content.ingested': 'Content ingested',
            'job.failed': 'Job failed',
        }[eventType] || eventType || 'Pipeline event';
    }

    function renderTimelineFilters(jobs, events, filters = {}) {
        renderSelectOptions(
            els.eventTypeFilter,
            'All event types',
            uniqueValues([
                ...pipelineEventTypes,
                ...(Array.isArray(filters.eventTypes) ? filters.eventTypes : []),
                ...events.map((event) => event.eventType),
            ]),
            state.eventTypeFilter,
        );
        renderSelectOptions(
            els.jobFilter,
            'All jobs',
            uniqueValues([
                ...(Array.isArray(filters.jobIds) ? filters.jobIds : []),
                ...jobs.map((job) => job.jobId),
                ...events.map((event) => event.jobId),
            ]),
            state.jobFilter,
        );
    }

    function renderSelectOptions(select, emptyLabel, values, selectedValue) {
        if (!select) return;

        const previousValue = selectedValue || '';
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

        select.value = values.includes(previousValue) ? previousValue : '';
        if (previousValue && select.value === '') {
            if (select === els.eventTypeFilter) state.eventTypeFilter = '';
            if (select === els.jobFilter) state.jobFilter = '';
        }
    }

    function uniqueValues(values) {
        return Array.from(new Set(values.filter((value) => typeof value === 'string' && value.trim() !== '')))
            .sort((a, b) => a.localeCompare(b));
    }

    function renderEmpty(container, message) {
        const empty = document.createElement('div');
        empty.className = 'empty-state';
        empty.textContent = message;
        container.appendChild(empty);
    }

    async function loadTasks({ keepSelection = true } = {}) {
        const requestId = ++state.requestId;
        const data = await requestJson('api/pipeline/tasks?limit=50');
        if (requestId !== state.requestId) return;

        state.tasks = Array.isArray(data.tasks) ? data.tasks : [];

        if (!keepSelection || !state.tasks.some((task) => task.taskId === state.selectedTaskId)) {
            state.selectedTaskId = state.tasks[0]?.taskId || '';
        }

        renderTasks(state.tasks);

        if (state.selectedTaskId) {
            await loadTask(state.selectedTaskId, { renderTaskList: false });
        } else {
            clearTaskDetail();
        }
    }

    async function loadTask(taskId, { renderTaskList = true } = {}) {
        if (!taskId) return;
        state.selectedTaskId = taskId;
        localStorage.setItem('hawkiPipelineDashboardTaskId', taskId);
        setStatus(`Loading task ${taskId}...`);

        const [taskData, failedData, eventsData] = await Promise.all([
            requestJson(`api/pipeline/tasks/${encodeURIComponent(taskId)}`),
            requestJson(`api/pipeline/tasks/${encodeURIComponent(taskId)}/failed-jobs`),
            requestJson(eventsPath(taskId)),
        ]);

        state.failedJobs = Array.isArray(failedData.jobs) ? failedData.jobs : [];
        renderTask(
            taskData.task,
            state.failedJobs,
            Array.isArray(eventsData.events) ? eventsData.events : [],
            eventsData.filters || {},
        );

        if (renderTaskList) {
            renderTasks(state.tasks);
        }
    }

    function eventsPath(taskId) {
        const params = new URLSearchParams({ limit: '150' });
        if (state.eventTypeFilter) params.set('event_type', state.eventTypeFilter);
        if (state.jobFilter) params.set('job_id', state.jobFilter);

        return `api/pipeline/tasks/${encodeURIComponent(taskId)}/events?${params.toString()}`;
    }

    function clearTaskDetail() {
        setStatus('No pipeline tasks found.');
        setText(els.taskStatus, 'idle');
        els.taskStatus.className = 'status-pill is-idle';
        setText(els.updated, 'No task loaded.');
        [els.taskInfo, els.counters, els.scrapeJobs, els.convertJobs, els.ingestJobs, els.failedJobs, els.events]
            .filter(Boolean)
            .forEach((container) => {
                container.innerHTML = '';
                renderEmpty(container, 'Nothing to show yet.');
            });
        [els.scrapeCount, els.convertCount, els.ingestCount, els.failedCount, els.eventsCount]
            .forEach((el) => setText(el, '0'));
        renderSelectOptions(els.eventTypeFilter, 'All event types', pipelineEventTypes, state.eventTypeFilter);
        renderSelectOptions(els.jobFilter, 'All jobs', [], state.jobFilter);
        els.retry.disabled = true;
    }

    async function retryFailedJobs() {
        if (!state.selectedTaskId) return;

        els.retry.disabled = true;
        setStatus(`Retrying failed jobs for ${state.selectedTaskId}...`);

        try {
            await requestJson(`api/pipeline/tasks/${encodeURIComponent(state.selectedTaskId)}/retry-failed-jobs`, {
                method: 'POST',
            });
            await loadTasks({ keepSelection: true });
            setStatus(`Retried failed jobs for ${state.selectedTaskId}.`, 'success');
        } catch (error) {
            setStatus(error.message || 'Retry failed.', 'error');
        }
    }

    function startPolling() {
        if (state.pollTimer) clearInterval(state.pollTimer);

        state.pollTimer = setInterval(async () => {
            if (!state.selectedTaskId) return;

            try {
                await loadTask(state.selectedTaskId);
            } catch {
                // The manual refresh button surfaces errors; polling should stay quiet.
            }
        }, 5000);
    }

    els.refresh?.addEventListener('click', async () => {
        try {
            setStatus('Refreshing dashboard...');
            await loadTasks({ keepSelection: true });
            setStatus('Dashboard refreshed.', 'success');
        } catch (error) {
            setStatus(error.message || 'Refresh failed.', 'error');
        }
    });

    els.retry?.addEventListener('click', retryFailedJobs);
    els.eventTypeFilter?.addEventListener('change', () => {
        state.eventTypeFilter = els.eventTypeFilter.value;
        if (state.selectedTaskId) {
            loadTask(state.selectedTaskId).catch((error) => setStatus(error.message || 'Timeline refresh failed.', 'error'));
        }
    });
    els.jobFilter?.addEventListener('change', () => {
        state.jobFilter = els.jobFilter.value;
        if (state.selectedTaskId) {
            loadTask(state.selectedTaskId).catch((error) => setStatus(error.message || 'Timeline refresh failed.', 'error'));
        }
    });

    loadTasks({ keepSelection: true }).catch((error) => {
        setStatus(error.message || 'Could not load pipeline tasks.', 'error');
        clearTaskDetail();
    });
    startPolling();
}
