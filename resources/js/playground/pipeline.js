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
const stageLogStatusEl = document.getElementById('pipeline-stage-log-status');
const stageLogViewerEl = document.getElementById('pipeline-stage-log-viewer');

let activeJobId = '';
let activePipelineTaskId = '';
let activePipelineDatasetId = '';
let activeStageLog = normalizeStageLog(localStorage.getItem('hawkiPipelineControllerStageLog') || '');
let stageLogRequestId = 0;
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
    activePipelineDatasetId = '';

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
    updateStageLogActions();
}

function normalizeStageLog(stage) {
    return {
        scrape: 'scraper',
        scraper: 'scraper',
        convert: 'converter',
        converter: 'converter',
        ingest: 'ingest',
        ingestion: 'ingest',
    }[String(stage || '').trim().toLowerCase()] || '';
}

function stageLogLabel(stage) {
    return {
        scraper: 'Scraper',
        converter: 'Converter',
        ingest: 'Ingest',
    }[normalizeStageLog(stage)] || 'Stage';
}

function stageLogPath(stage, download = false) {
    const suffix = download ? '/download' : '';

    return `pipeline/tasks/${encodeURIComponent(activePipelineTaskId)}/stages/${encodeURIComponent(normalizeStageLog(stage))}/logs${suffix}`;
}

function stageLogFilename(stage) {
    const datasetId = String(activePipelineDatasetId || activePipelineTaskId || 'dataset')
        .replace(/[^A-Za-z0-9._-]+/g, '_')
        .replace(/^[._-]+|[._-]+$/g, '') || 'dataset';

    return `${normalizeStageLog(stage)}_log_${datasetId}.txt`;
}

function updateStageLogActions() {
    document.querySelectorAll('[data-controller-stage-log]').forEach((button) => {
        const stage = normalizeStageLog(button.dataset.controllerStageLog || '');
        button.disabled = !activePipelineTaskId;
        button.classList.toggle('is-active', stage === activeStageLog);
        button.setAttribute('aria-pressed', stage === activeStageLog ? 'true' : 'false');
    });

    document.querySelectorAll('[data-controller-stage-download]').forEach((link) => {
        const stage = normalizeStageLog(link.dataset.controllerStageDownload || '');
        if (!activePipelineTaskId || !stage) {
            link.href = '#';
            link.removeAttribute('download');
            link.setAttribute('aria-disabled', 'true');
            return;
        }

        link.href = apiUrl(stageLogPath(stage, true));
        link.download = stageLogFilename(stage);
        link.setAttribute('aria-disabled', 'false');
    });
}

async function loadStageLogs(stage, { quiet = false } = {}) {
    stage = normalizeStageLog(stage);
    if (!activePipelineTaskId || !stage) {
        if (!activePipelineTaskId) {
            if (stageLogStatusEl) stageLogStatusEl.textContent = 'Select a pipeline task to view stage logs.';
            if (stageLogViewerEl) stageLogViewerEl.textContent = 'No pipeline task selected.';
        }
        return;
    }

    const requestId = ++stageLogRequestId;
    activeStageLog = stage;
    localStorage.setItem('hawkiPipelineControllerStageLog', stage);
    updateStageLogActions();

    if (!quiet) {
        if (stageLogStatusEl) stageLogStatusEl.textContent = `Loading ${stageLogLabel(stage)} logs...`;
        if (stageLogViewerEl) stageLogViewerEl.textContent = 'Loading logs...';
    }

    const response = await fetch(apiUrl(stageLogPath(stage)), {
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });
    const data = await parseResponseJson(response);
    if (!response.ok || !data.success) {
        throw new Error(data.message || `Stage logs failed (${response.status})`);
    }
    if (requestId !== stageLogRequestId) return;

    const log = data.log || {};
    if (stageLogStatusEl) {
        stageLogStatusEl.textContent = `${log.label || stageLogLabel(stage)} logs | ${log.filename || stageLogFilename(stage)} | ${log.lineCount ?? 0} lines`;
    }
    if (stageLogViewerEl) {
        stageLogViewerEl.textContent = log.text || 'No log lines found for this stage yet.';
    }
    updateStageLogActions();
}

function refreshActiveStageLogs({ quiet = true } = {}) {
    if (!activeStageLog || !activePipelineTaskId) {
        updateStageLogActions();
        return;
    }

    loadStageLogs(activeStageLog, { quiet }).catch((error) => {
        if (!quiet) {
            if (stageLogStatusEl) stageLogStatusEl.textContent = error.message || 'Could not load stage logs.';
            if (stageLogViewerEl) stageLogViewerEl.textContent = '';
        }
    });
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
    if (['partial', 'skipped', 'n/a', 'not_available', 'unavailable'].includes(value)) return 'is-partial';
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
    const logStage = normalizeStageLog(name);
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
    const side = document.createElement('div');
    side.className = 'pipeline-stage-side';
    side.appendChild(status);

    const actions = document.createElement('div');
    actions.className = 'pipeline-stage-actions';

    const logs = document.createElement('button');
    logs.type = 'button';
    logs.className = 'pipeline-stage-log-btn';
    logs.textContent = 'Logs';
    logs.dataset.controllerStageLog = logStage;
    logs.addEventListener('click', () => {
        loadStageLogs(logStage).catch((error) => {
            if (stageLogStatusEl) stageLogStatusEl.textContent = error.message || 'Could not load stage logs.';
            if (stageLogViewerEl) stageLogViewerEl.textContent = '';
        });
    });

    const download = document.createElement('a');
    download.className = 'pipeline-stage-download-btn';
    download.textContent = 'Download';
    download.href = '#';
    download.dataset.controllerStageDownload = logStage;

    actions.append(logs, download);
    side.appendChild(actions);
    header.append(title, side);
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

    updateStageLogActions();

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

function explicitStageEntries(task) {
    const stages = task?.stages && typeof task.stages === 'object' ? task.stages : {};
    if (Object.keys(stages).length === 0) return null;

    return [
        ['scrape', stages.scrape || { status: 'queued' }],
        ['convert', stages.convert || { status: 'queued' }],
        ['ingest', stages.ingest || { status: 'queued' }],
    ];
}

function renderPipelineTask(task) {
    if (!currentEl || !stagesEl || !task) return;

    const counters = task.counters || {};
    const jobs = Array.isArray(task.jobs) ? task.jobs : [];
    activePipelineDatasetId = task.datasetId || '';
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
    const explicitStages = explicitStageEntries(task);
    if (explicitStages) {
        explicitStages.forEach(([label, stage]) => {
            stagesEl.appendChild(renderStageCard(label, stage));
        });
        refreshActiveStageLogs({ quiet: true });
        return;
    }

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
    refreshActiveStageLogs({ quiet: true });
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

function isPipelineTaskCancellable(task) {
    const status = String(task?.status || '').toLowerCase();
    if (['completed', 'failed', 'cancelled', 'canceled', 'skipped'].includes(status)) {
        return false;
    }

    const counters = task?.counters || {};
    const active = Number(task?.activeJobs ?? counters.jobs_active ?? counters.jobs_running ?? 0);

    return active > 0 || ['pending', 'queued', 'running', 'processing', 'received'].includes(status);
}

function renderPipelineTaskRuns(tasks) {
    if (!runListEl) return;
    runListEl.innerHTML = '';

    if (!tasks.length) {
        const empty = document.createElement('button');
        empty.type = 'button';
        empty.className = 'pipeline-run-select';
        empty.disabled = true;
        empty.textContent = 'No pipeline tasks yet.';
        runListEl.appendChild(empty);
        return;
    }

    tasks.forEach((task) => {
        const row = document.createElement('div');
        row.className = 'pipeline-run-row';
        if (task.taskId === activePipelineTaskId) {
            row.classList.add('is-selected');
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'pipeline-run-select';
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

        const actions = document.createElement('div');
        actions.className = 'pipeline-run-actions';

        if (isPipelineTaskCancellable(task)) {
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'pipeline-run-cancel';
            cancel.textContent = 'Cancel';
            cancel.title = 'Cancel active processing for this pipeline task';
            cancel.addEventListener('click', () => cancelPipelineTask(task.taskId, cancel));
            actions.appendChild(cancel);
        }

        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'pipeline-run-delete';
        deleteButton.textContent = 'Delete';
        deleteButton.title = 'Delete this cached pipeline task history';
        deleteButton.addEventListener('click', () => deletePipelineTask(task.taskId, deleteButton));
        actions.appendChild(deleteButton);

        row.append(button, actions);
        runListEl.appendChild(row);
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
        if (stageLogStatusEl) stageLogStatusEl.textContent = 'Select Scrape, Convert, or Ingest logs from a stage card.';
        if (stageLogViewerEl) stageLogViewerEl.textContent = 'No stage log selected.';
        clearTaskRun();
        stagesEl.innerHTML = '';
        updateStageLogActions();
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
    updateStageLogActions();
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

async function cancelPipelineTask(taskId, button = null) {
    if (!taskId) return;

    const confirmed = window.confirm(`Cancel active processing for pipeline task ${taskId}?`);
    if (!confirmed) return;

    if (button) button.disabled = true;
    setTaskNote(`Cancelling pipeline task ${taskId}...`, 'warn');

    try {
        const response = await fetch(apiUrl(`pipeline/tasks/${encodeURIComponent(taskId)}/cancel`), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
        const data = await parseResponseJson(response);
        if (!response.ok || !data.success) {
            throw new Error(data.message || `Pipeline task cancel failed (${response.status})`);
        }

        if (data.task) {
            updatePipelineTaskRun(data.task);
            if (taskId === activePipelineTaskId) {
                renderPipelineTask(data.task);
            }
        }

        pushActivity('Pipeline', `cancel requested for task ${taskId}`);
        setTaskNote(`Cancel requested for ${taskId}.`, 'success');
        await loadPipelineTaskRuns();
    } catch (error) {
        setTaskNote(error.message || 'Pipeline task cancel failed.', 'error');
        pushActivity('Pipeline', error.message || 'Pipeline task cancel failed.');
    } finally {
        if (button) button.disabled = false;
    }
}

async function deletePipelineTask(taskId, button = null) {
    if (!taskId) return;

    const task = pipelineTaskRuns.find((candidate) => candidate.taskId === taskId);
    const activeWarning = isPipelineTaskCancellable(task)
        ? '\n\nThis task still looks active. Delete only removes the cached task history; use Cancel to stop active processing.'
        : '';
    const confirmed = window.confirm(
        `Delete cached pipeline task ${taskId}?${activeWarning}\n\nThis removes its task, job, and stage history from Pipeline Tasks. Dataset content is not deleted.`,
    );
    if (!confirmed) return;

    if (button) button.disabled = true;
    setTaskNote(`Deleting cached task ${taskId}...`, 'warn');

    try {
        const response = await fetch(apiUrl(`pipeline/tasks/${encodeURIComponent(taskId)}`), {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
        const data = await parseResponseJson(response);
        if (!response.ok || !data.success) {
            throw new Error(data.message || `Pipeline task delete failed (${response.status})`);
        }

        pipelineTaskRuns = pipelineTaskRuns.filter((candidate) => candidate.taskId !== taskId);

        if (taskId === activePipelineTaskId) {
            activePipelineTaskId = '';
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            renderPipeline(null);
        }

        renderPipelineTaskRuns(pipelineTaskRuns);
        pushActivity('Pipeline', `deleted cached task ${taskId}`);
        setTaskNote(`Deleted cached task ${taskId}.`, 'success');
        await loadPipelineTaskRuns();
    } catch (error) {
        setTaskNote(error.message || 'Pipeline task delete failed.', 'error');
        pushActivity('Pipeline', error.message || 'Pipeline task delete failed.');
    } finally {
        if (button) button.disabled = false;
    }
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

window.hawkiPipelineController = {
    selectTask: selectPipelineTask,
    refreshRuns: loadPipelineTaskRuns,
    renderTask: renderPipelineTask,
};

renderPipeline(null);
loadScraperTasks();
loadPipelineTaskRuns();
