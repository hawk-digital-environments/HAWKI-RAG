import { apiUrl } from './playground/urls.js';

const root = document.querySelector('[data-failed-jobs-dashboard]');

if (root) {
    const els = {
        refresh: document.getElementById('failed-jobs-refresh'),
        status: document.getElementById('failed-jobs-status'),
        count: document.getElementById('failed-jobs-count'),
        datasetFilter: document.getElementById('failed-jobs-dataset-filter'),
        taskFilter: document.getElementById('failed-jobs-task-filter'),
        clearFilters: document.getElementById('failed-jobs-clear-filters'),
        retrySelected: document.getElementById('failed-jobs-retry-selected'),
        retryTask: document.getElementById('failed-jobs-retry-task'),
        retryDataset: document.getElementById('failed-jobs-retry-dataset'),
        retryAll: document.getElementById('failed-jobs-retry-all'),
        table: document.getElementById('failed-jobs-table'),
    };

    const state = {
        jobs: [],
        selectedJobIds: new Set(),
        datasetFilter: '',
        taskFilter: '',
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

    function statusPill(status) {
        const pill = document.createElement('span');
        pill.className = `status-pill is-${String(status || 'idle').toLowerCase()}`;
        pill.textContent = valueOrDash(status);

        return pill;
    }

    function filteredJobs() {
        return state.jobs.filter((job) => {
            if (state.datasetFilter && job.datasetId !== state.datasetFilter) return false;
            if (state.taskFilter && job.taskId !== state.taskFilter) return false;
            return true;
        });
    }

    function render() {
        const jobs = filteredJobs();
        renderFilters();
        renderTable(jobs);
        els.count.textContent = `${jobs.length} failed job${jobs.length === 1 ? '' : 's'} shown`;
        els.retrySelected.disabled = state.selectedJobIds.size === 0;
        els.retrySelected.textContent = state.selectedJobIds.size === 1
            ? 'Retry selected job'
            : `Retry ${state.selectedJobIds.size} selected jobs`;
        els.retryTask.disabled = !state.taskFilter;
        els.retryDataset.disabled = !state.datasetFilter;
        els.retryAll.disabled = state.jobs.length === 0;
    }

    function renderFilters() {
        renderSelect(
            els.datasetFilter,
            'All datasets',
            uniqueValues(state.jobs.map((job) => job.datasetId)),
            state.datasetFilter,
        );
        renderSelect(
            els.taskFilter,
            'All tasks',
            uniqueValues(state.jobs
                .filter((job) => !state.datasetFilter || job.datasetId === state.datasetFilter)
                .map((job) => job.taskId)),
            state.taskFilter,
        );
    }

    function renderSelect(select, emptyLabel, values, selectedValue) {
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
            if (select === els.datasetFilter) state.datasetFilter = '';
            if (select === els.taskFilter) state.taskFilter = '';
        }
    }

    function renderTable(jobs) {
        els.table.innerHTML = '';
        if (jobs.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'empty-state';
            empty.textContent = 'No failed jobs match the current filters.';
            els.table.appendChild(empty);
            return;
        }

        const table = document.createElement('table');
        table.className = 'failed-table';
        const thead = document.createElement('thead');
        const header = document.createElement('tr');
        ['', 'Task ID', 'Dataset', 'Job ID', 'Type', 'Source URL', 'Error', 'Retries', 'Timestamp', 'Action'].forEach((label) => {
            const th = document.createElement('th');
            th.textContent = label;
            header.appendChild(th);
        });
        thead.appendChild(header);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        jobs.forEach((job) => {
            const row = document.createElement('tr');
            if (state.selectedJobIds.has(job.jobId)) row.classList.add('is-selected');

            const selectCell = document.createElement('td');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = state.selectedJobIds.has(job.jobId);
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) {
                    state.selectedJobIds.add(job.jobId);
                } else {
                    state.selectedJobIds.delete(job.jobId);
                }
                render();
            });
            selectCell.appendChild(checkbox);
            row.appendChild(selectCell);

            appendCell(row, job.taskId, 'mono');
            appendCell(row, job.datasetId, 'mono');
            appendCell(row, job.jobId, 'mono');
            const typeCell = document.createElement('td');
            typeCell.appendChild(statusPill(job.jobType));
            row.appendChild(typeCell);
            appendCell(row, job.sourceUrl, 'wrap');
            appendCell(row, job.errorMessage, 'error wrap');
            appendCell(row, job.retryCount);
            appendCell(row, formatDate(job.timestamp));

            const actionCell = document.createElement('td');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'inline-button';
            button.textContent = 'Retry';
            button.addEventListener('click', () => retrySingle(job.jobId));
            actionCell.appendChild(button);
            row.appendChild(actionCell);

            tbody.appendChild(row);
        });

        table.appendChild(tbody);
        els.table.appendChild(table);
    }

    function appendCell(row, value, className = '') {
        const cell = document.createElement('td');
        cell.textContent = valueOrDash(value);
        if (className) cell.className = className;
        row.appendChild(cell);
    }

    function uniqueValues(values) {
        return Array.from(new Set(values.filter((value) => typeof value === 'string' && value.trim() !== '')))
            .sort((a, b) => a.localeCompare(b));
    }

    async function loadJobs({ quiet = false } = {}) {
        const requestId = ++state.requestId;
        if (!quiet) setStatus('Loading failed jobs...');
        const data = await requestJson('api/pipeline/recovery/failed-jobs?limit=500');
        if (requestId !== state.requestId) return;

        state.jobs = Array.isArray(data.jobs) ? data.jobs : [];
        const available = new Set(state.jobs.map((job) => job.jobId));
        state.selectedJobIds = new Set(Array.from(state.selectedJobIds).filter((jobId) => available.has(jobId)));
        render();
        if (!quiet) setStatus(`Loaded ${state.jobs.length} failed job${state.jobs.length === 1 ? '' : 's'}.`, 'success');
    }

    async function recover(path, options = {}) {
        setBusy(true);
        setStatus('Submitting recovery request...');
        try {
            const data = await requestJson(path, {
                method: 'POST',
                ...options,
            });
            const summary = data.recovery || {};
            state.selectedJobIds.clear();
            await loadJobs({ quiet: true });
            setStatus(
                `Recovery complete: ${summary.retried || 0} retried, ${summary.skipped || 0} skipped, ${summary.failed || 0} failed.`,
                summary.failed ? 'error' : 'success',
            );
        } catch (error) {
            setStatus(error.message || 'Recovery request failed.', 'error');
        } finally {
            setBusy(false);
        }
    }

    function retrySingle(jobId) {
        recover(`api/pipeline/recovery/jobs/${encodeURIComponent(jobId)}/retry`);
    }

    function setBusy(disabled) {
        if (disabled) {
            [els.retrySelected, els.retryTask, els.retryDataset, els.retryAll, els.refresh].forEach((button) => {
                button.disabled = true;
            });
            return;
        }

        els.refresh.disabled = false;
        render();
    }

    els.refresh.addEventListener('click', () => {
        loadJobs().catch((error) => setStatus(error.message || 'Could not load failed jobs.', 'error'));
    });

    els.datasetFilter.addEventListener('change', () => {
        state.datasetFilter = els.datasetFilter.value;
        if (state.taskFilter && !filteredJobs().some((job) => job.taskId === state.taskFilter)) {
            state.taskFilter = '';
        }
        render();
    });

    els.taskFilter.addEventListener('change', () => {
        state.taskFilter = els.taskFilter.value;
        render();
    });

    els.clearFilters.addEventListener('click', () => {
        state.datasetFilter = '';
        state.taskFilter = '';
        render();
    });

    els.retrySelected.addEventListener('click', () => {
        recover('api/pipeline/recovery/jobs/retry-selected', {
            body: JSON.stringify({ job_ids: Array.from(state.selectedJobIds) }),
        });
    });

    els.retryTask.addEventListener('click', () => {
        if (!state.taskFilter) return;
        recover(`api/pipeline/recovery/tasks/${encodeURIComponent(state.taskFilter)}/retry-failed`);
    });

    els.retryDataset.addEventListener('click', () => {
        if (!state.datasetFilter) return;
        recover(`api/pipeline/recovery/datasets/${encodeURIComponent(state.datasetFilter)}/retry-failed`);
    });

    els.retryAll.addEventListener('click', () => {
        recover('api/pipeline/recovery/retry-all');
    });

    loadJobs().catch((error) => setStatus(error.message || 'Could not load failed jobs.', 'error'));
    state.pollTimer = setInterval(() => {
        loadJobs({ quiet: true }).catch(() => {});
    }, 10000);
}
