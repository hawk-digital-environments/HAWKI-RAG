import { apiUrl } from './urls.js';

const unavailableTasksMessage = 'Please build the HAWKI-Scraper for getting available tasks.';

const urlInput = document.getElementById('pipeline-url');
const labelInput = document.getElementById('pipeline-label');
const maxPagesInput = document.getElementById('pipeline-max-pages');
const skipImagesInput = document.getElementById('pipeline-skip-images');
const startButton = document.getElementById('pipeline-start-btn');
const currentEl = document.getElementById('pipeline-current');
const jobIdEl = document.getElementById('pipeline-job-id');
const datasetPathEl = document.getElementById('pipeline-dataset-path');
const updatedAtEl = document.getElementById('pipeline-updated-at');
const stagesEl = document.getElementById('pipeline-stages');
const taskSelect = document.getElementById('pipeline-task-select');
const taskNote = document.getElementById('pipeline-task-note');
const taskRefreshButton = document.getElementById('pipeline-task-refresh-btn');
const taskStartButton = document.getElementById('pipeline-task-start-btn');

let activeJobId = localStorage.getItem('hawkiPipelineJobId') || '';
let activeTaskId = localStorage.getItem('hawkiPipelineTaskId') || '';
let availableTasks = [];
let pollTimer = null;

function csrfToken() {
    return window.playgroundLogs?.csrfToken
        ? window.playgroundLogs.csrfToken()
        : document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function pushActivity(source, message) {
    window.playgroundLogs?.pushActivity?.(source, message);
}

function fallbackLabel(url) {
    try {
        return new URL(url).hostname.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').toLowerCase() || 'pipeline-test';
    } catch {
        return 'pipeline-test';
    }
}

function normalizeUrlInput(value) {
    const url = value.trim();
    if (!url || /^[a-z][a-z0-9+.-]*:\/\//i.test(url)) {
        return url;
    }

    return `https://${url}`;
}

function sanitizeLabel(value) {
    return value
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9_-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

async function parseResponseJson(response) {
    const body = await response.text();
    if (!body) return {};

    try {
        return JSON.parse(body);
    } catch {
        return {
            success: false,
            message: body.trim() || `HTTP ${response.status}`,
        };
    }
}

function failureMessage(data, status) {
    const errors = data?.result?.errors || data?.errors || [];
    const firstError = Array.isArray(errors) ? errors[0] : errors;
    const errorMessage = typeof firstError === 'string'
        ? firstError
        : firstError?.message;

    return data?.message || errorMessage || `Pipeline start failed (${status})`;
}

function setTaskNote(message, tone = 'info') {
    if (!taskNote) return;
    taskNote.textContent = message || '';
    taskNote.dataset.tone = tone;
}

function setActiveJob(jobId, taskId = '') {
    activeJobId = jobId || '';
    activeTaskId = taskId || '';

    if (activeJobId) {
        localStorage.setItem('hawkiPipelineJobId', activeJobId);
    } else {
        localStorage.removeItem('hawkiPipelineJobId');
    }

    if (activeTaskId) {
        localStorage.setItem('hawkiPipelineTaskId', activeTaskId);
    } else {
        localStorage.removeItem('hawkiPipelineTaskId');
    }

    if (jobIdEl) {
        jobIdEl.textContent = activeJobId ? `Job ID: ${activeJobId}` : 'Job ID: none';
    }
}

function stageSummary(stage) {
    const counts = stage?.counts || {};
    if (counts.pagesCrawled !== undefined || counts.totalPages !== undefined) {
        return `${counts.pagesCrawled || 0}/${counts.totalPages || 0} pages`;
    }
    if (counts.convertedFiles !== undefined || counts.sourceFiles !== undefined) {
        return `${counts.convertedFiles || 0}/${counts.sourceFiles || 0} files`;
    }
    if (counts.completed !== undefined || counts.total !== undefined) {
        return `${counts.completed || 0}/${counts.total || 0} docs`;
    }
    return stage?.message || '';
}

function stageStatusClass(status) {
    const value = String(status || 'pending').toLowerCase();
    if (['completed', 'success'].includes(value)) return 'is-completed';
    if (['running', 'processing', 'received'].includes(value)) return 'is-running';
    if (['failed', 'error'].includes(value)) return 'is-failed';
    if (['partial', 'skipped'].includes(value)) return 'is-partial';
    return 'is-pending';
}

function appendMetric(parent, label, value) {
    if (value === undefined || value === null || value === '') return;
    const metric = document.createElement('div');
    metric.className = 'pipeline-metric';

    const labelEl = document.createElement('span');
    labelEl.textContent = label;
    const valueEl = document.createElement('strong');
    valueEl.textContent = String(value);

    metric.append(labelEl, valueEl);
    parent.appendChild(metric);
}

function renderStageCard(name, stage = {}) {
    const card = document.createElement('article');
    card.className = `pipeline-stage-card ${stageStatusClass(stage.status)}`;

    const header = document.createElement('div');
    header.className = 'pipeline-stage-card-header';

    const title = document.createElement('div');
    const label = document.createElement('span');
    label.className = 'pipeline-stage-label';
    label.textContent = name;
    const summary = document.createElement('small');
    summary.textContent = stageSummary(stage);
    title.append(label, summary);

    const status = document.createElement('strong');
    status.className = 'pipeline-stage-status';
    status.textContent = stage.status || 'pending';
    header.append(title, status);
    card.appendChild(header);

    const metrics = document.createElement('div');
    metrics.className = 'pipeline-metrics';
    const counts = stage.counts || {};
    Object.entries(counts).forEach(([key, value]) => appendMetric(metrics, key, value));

    if (stage.retry) {
        appendMetric(metrics, 'retry', stage.retry.retryCount);
        appendMetric(metrics, 'max retries', stage.retry.maxRetries);
    }

    if (stage.startedAt) appendMetric(metrics, 'started', formatDate(stage.startedAt));
    if (stage.completedAt) appendMetric(metrics, 'completed', formatDate(stage.completedAt));
    card.appendChild(metrics);

    const errors = Array.isArray(stage.errors) ? stage.errors : [];
    if (errors.length > 0) {
        const errorBox = document.createElement('div');
        errorBox.className = 'pipeline-stage-errors';
        errorBox.textContent = errors
            .slice(0, 3)
            .map((error) => typeof error === 'string' ? error : (error.message || error.error_message || JSON.stringify(error)))
            .join(' | ');
        card.appendChild(errorBox);
    }

    return card;
}

function formatDate(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function renderPipeline(data) {
    if (!currentEl || !stagesEl) return;
    if (!data || !data.jobId) {
        currentEl.textContent = 'No pipeline selected.';
        if (jobIdEl) jobIdEl.textContent = 'Job ID: none';
        if (datasetPathEl) datasetPathEl.textContent = 'Dataset path: none';
        if (updatedAtEl) updatedAtEl.textContent = '';
        stagesEl.innerHTML = '';
        return;
    }

    const stageName = data.currentStage || 'pending';
    const status = data.status || 'unknown';
    currentEl.textContent = `${stageName} · ${status}`;
    if (jobIdEl) jobIdEl.textContent = `Job ID: ${data.jobId}`;
    if (datasetPathEl) datasetPathEl.textContent = `Dataset path: ${data.datasetPath || 'none'}`;
    if (updatedAtEl) updatedAtEl.textContent = data.updatedAt ? `Updated ${formatDate(data.updatedAt)}` : '';

    const stages = data.stages || {};
    stagesEl.innerHTML = '';
    ['scrape', 'convert', 'ingest'].forEach((name) => {
        stagesEl.appendChild(renderStageCard(name, stages[name] || {}));
    });
}

function renderTaskOptions(tasks, message = '') {
    if (!taskSelect || !taskStartButton) return;
    taskSelect.innerHTML = '';

    if (!tasks.length) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = unavailableTasksMessage;
        taskSelect.appendChild(option);
        taskSelect.disabled = true;
        taskStartButton.disabled = true;
        setTaskNote(message || unavailableTasksMessage, 'warn');
        return;
    }

    tasks.forEach((task) => {
        const option = document.createElement('option');
        option.value = task.id;
        option.textContent = task.label || task.id;
        option.title = task.description || task.id;
        taskSelect.appendChild(option);
    });

    if (activeTaskId && tasks.some((task) => task.id === activeTaskId)) {
        taskSelect.value = activeTaskId;
    }

    taskSelect.disabled = false;
    taskStartButton.disabled = false;
    const selected = tasks.find((task) => task.id === taskSelect.value) || tasks[0];
    setTaskNote(selected?.description || message || `${tasks.length} task(s) available.`, 'info');
}

async function loadScraperTasks() {
    if (!taskSelect) return;
    if (taskRefreshButton) taskRefreshButton.disabled = true;
    setTaskNote('Loading scraper tasks...');

    try {
        const response = await fetch(apiUrl('scraper/tasks'), {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
        const data = await parseResponseJson(response);
        availableTasks = Array.isArray(data.tasks) ? data.tasks : [];
        renderTaskOptions(availableTasks, data.message || unavailableTasksMessage);
    } catch (error) {
        availableTasks = [];
        renderTaskOptions([], error.message || unavailableTasksMessage);
    } finally {
        if (taskRefreshButton) taskRefreshButton.disabled = false;
    }
}

async function startSelectedTask() {
    if (!taskSelect || !taskStartButton) return;
    const taskId = taskSelect.value;
    if (!taskId) {
        setTaskNote(unavailableTasksMessage, 'warn');
        return;
    }

    taskStartButton.disabled = true;
    setTaskNote('Starting scraper task...');

    try {
        const response = await fetch(apiUrl('scraper/tasks/start'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ taskId }),
        });
        const data = await parseResponseJson(response);
        if (!response.ok || !data.success || !data.jobId) {
            setTaskNote(data.message || `Task start failed (${response.status})`, 'error');
            pushActivity('Pipeline', data.message || 'Scraper task start failed.');
            return;
        }

        setActiveJob(data.jobId, taskId);
        setTaskNote(`Created scraper job ID ${data.jobId}`, 'success');
        pushActivity('Pipeline', `task ${taskId} created job ${data.jobId}`);
        renderPipeline({ jobId: data.jobId, currentStage: 'scrape', status: 'running', stages: {} });
        startPolling();
    } catch (error) {
        setTaskNote(error.message || 'Scraper task start failed.', 'error');
        pushActivity('Pipeline', error.message || 'Scraper task start failed.');
    } finally {
        taskStartButton.disabled = false;
    }
}

async function pollPipeline() {
    if (!activeJobId) return;
    try {
        const response = await fetch(apiUrl(`pipeline/status/${encodeURIComponent(activeJobId)}`), {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
        const data = await parseResponseJson(response);
        if (!response.ok || !data.success) return;
        renderPipeline(data);
        if (['completed', 'failed', 'partial', 'skipped'].includes(data.status)) {
            pushActivity('Pipeline', `${data.currentStage}: ${data.status}`);
        }
    } catch {
        // UI polling should stay quiet on transient failures.
    }
}

function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollPipeline();
    pollTimer = setInterval(pollPipeline, 3000);
}

async function startPipeline() {
    if (!urlInput || !startButton) return;
    const url = normalizeUrlInput(urlInput.value);
    if (!url) {
        currentEl.textContent = 'URL is required.';
        return;
    }

    const label = sanitizeLabel(labelInput?.value || '') || fallbackLabel(url);
    urlInput.value = url;
    if (labelInput) labelInput.value = label;
    startButton.disabled = true;
    currentEl.textContent = 'Starting pipeline...';

    try {
        const response = await fetch(apiUrl('requestScrape'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                url,
                label,
                maxPages: Math.max(1, parseInt(maxPagesInput?.value || '2', 10)),
                skipImages: Boolean(skipImagesInput?.checked),
            }),
        });
        const data = await parseResponseJson(response);
        if (!response.ok || !data.success || !data.jobId) {
            currentEl.textContent = failureMessage(data, response.status);
            pushActivity('Pipeline', currentEl.textContent);
            return;
        }

        setActiveJob(data.jobId, '');
        pushActivity('Pipeline', `started ${data.jobId}`);
        renderPipeline({ jobId: data.jobId, currentStage: 'scrape', status: 'running', stages: {} });
        startPolling();
    } catch (error) {
        currentEl.textContent = 'Pipeline start failed.';
        pushActivity('Pipeline', error.message || 'Pipeline start failed.');
    } finally {
        startButton.disabled = false;
    }
}

taskSelect?.addEventListener('change', () => {
    const selected = availableTasks.find((task) => task.id === taskSelect.value);
    setTaskNote(selected?.description || selected?.id || '');
});
taskRefreshButton?.addEventListener('click', loadScraperTasks);
taskStartButton?.addEventListener('click', startSelectedTask);
startButton?.addEventListener('click', startPipeline);

if (activeJobId) {
    setActiveJob(activeJobId, activeTaskId);
    startPolling();
}

loadScraperTasks();
