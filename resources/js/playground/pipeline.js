import { apiUrl } from './urls.js';

const unavailableTasksMessage = 'Please build the HAWKI-Scraper for getting available tasks.';

const currentEl = document.getElementById('pipeline-current');
const jobIdEl = document.getElementById('pipeline-job-id');
const datasetPathEl = document.getElementById('pipeline-dataset-path');
const updatedAtEl = document.getElementById('pipeline-updated-at');
const stagesEl = document.getElementById('pipeline-stages');
const taskSelect = document.getElementById('pipeline-task-select');
const taskNote = document.getElementById('pipeline-task-note');
const taskRefreshButton = document.getElementById('pipeline-task-refresh-btn');
const taskStartButton = document.getElementById('pipeline-task-start-btn');
const taskCountEl = document.getElementById('pipeline-task-count');
const taskSourceEl = document.getElementById('pipeline-task-source');
const taskDetailEl = document.getElementById('pipeline-task-detail');
const taskRunEl = document.getElementById('pipeline-task-run');
const runListEl = document.getElementById('pipeline-run-list');
const runRefreshButton = document.getElementById('pipeline-run-refresh-btn');

let activeJobId = '';
let activePipelineTaskId = '';
let selectedCatalogTaskId = localStorage.getItem('hawkiSelectedScraperTaskId')
    || localStorage.getItem('hawkiPipelineTaskId')
    || '';
let availableTasks = [];
let pipelineTaskRuns = [];
let pollTimer = null;

localStorage.removeItem('hawkiPipelineJobId');
localStorage.removeItem('hawkiPipelineRunTaskId');

function csrfToken() {
    return window.playgroundLogs?.csrfToken
        ? window.playgroundLogs.csrfToken()
        : document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function pushActivity(source, message) {
    window.playgroundLogs?.pushActivity?.(source, message);
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

function setTaskNote(message, tone = 'info') {
    if (!taskNote) return;
    taskNote.textContent = message || '';
    taskNote.dataset.tone = tone;
}

function setActiveJob(jobId) {
    activeJobId = jobId || '';
    activePipelineTaskId = '';

    if (jobIdEl) {
        jobIdEl.textContent = activeJobId ? `Job ID: ${activeJobId}` : 'Job ID: none';
    }
}

function setActivePipelineTask(taskId) {
    activePipelineTaskId = taskId || '';
    activeJobId = '';

    if (jobIdEl) {
        jobIdEl.textContent = activePipelineTaskId ? `Task ID: ${activePipelineTaskId}` : 'Task ID: none';
    }

    renderPipelineTaskRuns(pipelineTaskRuns);
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
    const value = String(status || 'queued').toLowerCase();
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
    status.textContent = stage.status || 'queued';
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

function clearTaskRun() {
    if (!taskRunEl) return;
    taskRunEl.hidden = true;
    taskRunEl.innerHTML = '';
}

function appendTaskStat(parent, label, value) {
    const stat = document.createElement('div');
    stat.className = 'pipeline-task-stat';

    const labelEl = document.createElement('span');
    labelEl.textContent = label;
    const valueEl = document.createElement('strong');
    valueEl.textContent = String(value ?? 0);

    stat.append(labelEl, valueEl);
    parent.appendChild(stat);
}

function jobStatusCounts(jobs, type) {
    const matching = jobs.filter((job) => (job.jobType || job.job_type || '') === type);
    const counts = {
        total: matching.length,
        queued: 0,
        running: 0,
        completed: 0,
        skipped: 0,
        failed: 0,
    };

    matching.forEach((job) => {
        const status = String(job.status || 'queued').toLowerCase();
        if (status === 'pending') {
            counts.queued += 1;
            return;
        }
        if (counts[status] !== undefined) {
            counts[status] += 1;
        }
    });

    return counts;
}

function statusFromCounts(counts) {
    if (!counts.total) return 'queued';
    if (counts.failed > 0) return 'failed';
    if (counts.running > 0) return 'running';
    if (counts.queued > 0) return 'queued';
    if (counts.skipped === counts.total) return 'skipped';
    if (counts.completed + counts.skipped === counts.total) return 'completed';
    return 'partial';
}

function renderPipelineTask(task) {
    if (!currentEl || !stagesEl || !task) return;

    const counters = task.counters || {};
    const jobs = Array.isArray(task.jobs) ? task.jobs : [];
    currentEl.textContent = `Task ${task.status || 'unknown'} · ${task.activeJobs || 0} active`;
    if (jobIdEl) jobIdEl.textContent = `Task ID: ${task.taskId || 'none'}`;
    if (datasetPathEl) datasetPathEl.textContent = `Dataset: ${task.datasetId || 'none'}`;
    if (updatedAtEl) updatedAtEl.textContent = task.updatedAt ? `Updated ${formatDate(task.updatedAt)}` : '';

    if (taskRunEl) {
        taskRunEl.innerHTML = '';
        appendTaskStat(taskRunEl, 'Total jobs', counters.jobs_total || jobs.length);
        appendTaskStat(taskRunEl, 'Running', counters.jobs_running || task.activeJobs || 0);
        appendTaskStat(taskRunEl, 'Completed', counters.jobs_completed || 0);
        appendTaskStat(taskRunEl, 'Skipped', counters.jobs_skipped || 0);
        taskRunEl.hidden = false;
    }

    stagesEl.innerHTML = '';
    [
        ['scrape', 'scrape'],
        ['convert', 'convert'],
        ['ingest', 'ingest'],
    ].forEach(([label, type]) => {
        const counts = jobStatusCounts(jobs, type);
        stagesEl.appendChild(renderStageCard(label, {
            status: statusFromCounts(counts),
            counts,
        }));
    });
}

function taskRunLabel(task) {
    const requestMetadata = task?.metadata?.request?.metadata || {};
    return requestMetadata.catalog_task_label
        || requestMetadata.label
        || task.datasetId
        || task.taskId;
}

function taskRunMeta(task) {
    const counters = task.counters || {};
    const total = counters.jobs_total ?? 0;
    const done = (counters.jobs_completed ?? 0) + (counters.jobs_skipped ?? 0);
    const active = task.activeJobs ?? counters.jobs_active ?? 0;
    const started = task.startedAt ? formatDate(task.startedAt) : 'not started';

    return `${done}/${total} done · ${active} active · ${started}`;
}

function renderPipelineTaskRuns(tasks) {
    if (!runListEl) return;
    runListEl.innerHTML = '';

    if (!tasks.length) {
        const empty = document.createElement('button');
        empty.type = 'button';
        empty.disabled = true;
        empty.textContent = 'No pipeline tasks yet.';
        runListEl.appendChild(empty);
        return;
    }

    tasks.forEach((task) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.taskId = task.taskId;
        if (task.taskId === activePipelineTaskId) {
            button.classList.add('is-selected');
        }

        const title = document.createElement('div');
        title.className = 'pipeline-run-title';

        const label = document.createElement('strong');
        label.textContent = taskRunLabel(task);

        const status = document.createElement('span');
        status.className = 'pipeline-run-status';
        status.textContent = task.status || 'unknown';
        title.append(label, status);

        const meta = document.createElement('div');
        meta.className = 'pipeline-run-meta';

        const id = document.createElement('span');
        id.textContent = task.taskId;
        const counts = document.createElement('span');
        counts.textContent = taskRunMeta(task);
        meta.append(id, counts);

        button.append(title, meta);
        button.addEventListener('click', () => selectPipelineTask(task.taskId));
        runListEl.appendChild(button);
    });
}

function updatePipelineTaskRun(task) {
    if (!task?.taskId) return;
    const index = pipelineTaskRuns.findIndex((candidate) => candidate.taskId === task.taskId);
    if (index >= 0) {
        pipelineTaskRuns[index] = {
            ...pipelineTaskRuns[index],
            ...task,
        };
    } else {
        pipelineTaskRuns.unshift(task);
    }
    renderPipelineTaskRuns(pipelineTaskRuns);
}

function renderPipeline(data) {
    if (!currentEl || !stagesEl) return;
    if (!data || !data.jobId) {
        currentEl.textContent = 'No pipeline selected.';
        if (jobIdEl) jobIdEl.textContent = 'Job ID: none';
        if (datasetPathEl) datasetPathEl.textContent = 'Dataset path: none';
        if (updatedAtEl) updatedAtEl.textContent = '';
        clearTaskRun();
        stagesEl.innerHTML = '';
        return;
    }

    clearTaskRun();
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

function sourceLabel(task) {
    if (!task) return 'none';
    if (task.source === 'scraper-task-ui') return 'HAWKI-Scraper UI';
    if (task.source === 'crawler-api') return 'Crawler API';
    return task.source || 'unknown';
}

function settingValue(settings, key) {
    const value = settings?.[key];
    if (value === true) return 'on';
    if (value === false) return 'off';
    return value === undefined || value === null || value === '' ? null : String(value);
}

function renderSelectedTaskDetail(task) {
    if (!taskDetailEl) return;
    taskDetailEl.innerHTML = '';

    if (!task) {
        taskDetailEl.hidden = true;
        return;
    }

    const title = document.createElement('h4');
    title.textContent = task.label || task.id;

    const url = document.createElement('p');
    url.textContent = task.primaryUrl || task.sitemapUrl || task.description || task.id;

    const chips = document.createElement('div');
    chips.className = 'pipeline-task-chips';
    [
        sourceLabel(task),
        task.type || null,
        task.schedule ? `schedule ${task.schedule}` : null,
        settingValue(task.settings, 'max_pages') ? `${settingValue(task.settings, 'max_pages')} pages` : null,
        settingValue(task.settings, 'skip_images') ? `images ${settingValue(task.settings, 'skip_images')}` : null,
        settingValue(task.settings, 'discovery_mode') ? `discovery ${settingValue(task.settings, 'discovery_mode')}` : null,
    ].filter(Boolean).forEach((value) => {
        const chip = document.createElement('span');
        chip.textContent = value;
        chips.appendChild(chip);
    });

    taskDetailEl.append(title, url, chips);
    taskDetailEl.hidden = false;
}

function renderTaskOptions(tasks, message = '') {
    if (!taskSelect || !taskStartButton) return;
    taskSelect.innerHTML = '';

    if (taskCountEl) taskCountEl.textContent = String(tasks.length);
    if (taskSourceEl) {
        const sources = [...new Set(tasks.map(sourceLabel).filter(Boolean))];
        taskSourceEl.textContent = `Source: ${sources.length ? sources.join(', ') : 'none'}`;
    }

    if (!tasks.length) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = unavailableTasksMessage;
        taskSelect.appendChild(option);
        taskSelect.disabled = true;
        taskStartButton.disabled = true;
        renderSelectedTaskDetail(null);
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

    if (selectedCatalogTaskId && tasks.some((task) => task.id === selectedCatalogTaskId)) {
        taskSelect.value = selectedCatalogTaskId;
    }

    taskSelect.disabled = false;
    taskStartButton.disabled = false;
    const selected = tasks.find((task) => task.id === taskSelect.value) || tasks[0];
    renderSelectedTaskDetail(selected);
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

async function loadPipelineTaskRuns() {
    if (!runListEl) return;
    if (runRefreshButton) runRefreshButton.disabled = true;

    try {
        const response = await fetch(apiUrl('pipeline/tasks?limit=30'), {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
        const data = await parseResponseJson(response);
        pipelineTaskRuns = Array.isArray(data.tasks) ? data.tasks : [];
        renderPipelineTaskRuns(pipelineTaskRuns);
    } catch (error) {
        pipelineTaskRuns = [];
        renderPipelineTaskRuns([]);
        pushActivity('Pipeline', error.message || 'Pipeline task list failed.');
    } finally {
        if (runRefreshButton) runRefreshButton.disabled = false;
    }
}

async function selectPipelineTask(taskId) {
    if (!taskId) return;
    setActivePipelineTask(taskId);
    startPolling();
    await pollPipeline();
}

function pipelineTaskIdFor(task) {
    const base = sanitizeLabel(task.id || task.label || 'scraper-task') || 'scraper-task';
    return `task_${base}_${Date.now()}`;
}

function settingsMetadata(task) {
    const settings = task?.settings && typeof task.settings === 'object' ? task.settings : {};
    const metadata = {};

    [
        'max_pages',
        'max_concurrency',
        'max_rpm',
        'skip_images',
        'discovery_mode',
        'rescrape_failed',
        'max_images_per_page',
        'max_link_density',
    ].forEach((key) => {
        if (settings[key] !== undefined && settings[key] !== null && settings[key] !== '') {
            metadata[key] = settings[key];
        }
    });

    return metadata;
}

function pipelineTaskPayload(task) {
    const sourceUrl = task.primaryUrl || '';
    const sitemapUrl = task.sitemapUrl || '';
    const metadata = {
        source: task.source || 'scraper-task',
        catalog_task_id: task.id,
        catalog_task_label: task.label || task.id,
        catalog_task_type: task.type || null,
        ...settingsMetadata(task),
    };

    return {
        taskId: pipelineTaskIdFor(task),
        datasetId: sanitizeLabel(task.label || task.id),
        sourceUrl,
        sitemapUrl,
        urls: sourceUrl ? [sourceUrl] : [],
        metadata,
    };
}

function canStartAsPipelineTask(task) {
    return task?.source === 'scraper-task-ui' && Boolean(task.primaryUrl || task.sitemapUrl);
}

async function startPipelineTaskFromCatalog(task) {
    const response = await fetch(apiUrl('pipeline/tasks/start'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(pipelineTaskPayload(task)),
    });
    const data = await parseResponseJson(response);
    if (!response.ok || !data.success || !data.taskId) {
        throw new Error(data.message || `Pipeline task start failed (${response.status})`);
    }

    return data;
}

async function startLegacyScraperTask(taskId) {
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
        throw new Error(data.message || `Task start failed (${response.status})`);
    }

    return data;
}

async function startSelectedTask() {
    if (!taskSelect || !taskStartButton) return;
    const taskId = taskSelect.value;
    if (!taskId) {
        setTaskNote(unavailableTasksMessage, 'warn');
        return;
    }

    const task = availableTasks.find((candidate) => candidate.id === taskId);
    if (!task) {
        setTaskNote('Selected scraper task is no longer available.', 'warn');
        return;
    }

    taskStartButton.disabled = true;
    setTaskNote(canStartAsPipelineTask(task) ? 'Starting pipeline task...' : 'Starting scraper task...');

    try {
        if (canStartAsPipelineTask(task)) {
            const data = await startPipelineTaskFromCatalog(task);
            setActivePipelineTask(data.taskId);
            setTaskNote(`Created pipeline task ${data.taskId}`, 'success');
            pushActivity('Pipeline', `task ${data.taskId} created from ${taskId}`);
            renderPipelineTask(data.task);
            updatePipelineTaskRun(data.task);
            await loadPipelineTaskRuns();
            startPolling();
            return;
        }

        const data = await startLegacyScraperTask(taskId);
        setActiveJob(data.jobId);
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
    if (activePipelineTaskId) {
        try {
            const response = await fetch(apiUrl(`pipeline/tasks/${encodeURIComponent(activePipelineTaskId)}`), {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });
            const data = await parseResponseJson(response);
            if (!response.ok || !data.success || !data.task) return;
            renderPipelineTask(data.task);
            updatePipelineTaskRun(data.task);
            if (['completed', 'failed', 'cancelled'].includes(data.task.status)) {
                pushActivity('Pipeline', `task ${data.task.taskId}: ${data.task.status}`);
            }
        } catch {
            // UI polling should stay quiet on transient failures.
        }
        return;
    }

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

taskSelect?.addEventListener('change', () => {
    const selected = availableTasks.find((task) => task.id === taskSelect.value);
    selectedCatalogTaskId = selected?.id || '';
    if (selectedCatalogTaskId) {
        localStorage.setItem('hawkiSelectedScraperTaskId', selectedCatalogTaskId);
    } else {
        localStorage.removeItem('hawkiSelectedScraperTaskId');
    }
    renderSelectedTaskDetail(selected);
    setTaskNote(selected?.description || selected?.id || '');
});
taskRefreshButton?.addEventListener('click', loadScraperTasks);
taskStartButton?.addEventListener('click', startSelectedTask);
runRefreshButton?.addEventListener('click', loadPipelineTaskRuns);

renderPipeline(null);
loadScraperTasks();
loadPipelineTaskRuns();
