const basePath = import.meta.env.BASE_URL ?? '/';

const urlInput = document.getElementById('pipeline-url');
const labelInput = document.getElementById('pipeline-label');
const maxPagesInput = document.getElementById('pipeline-max-pages');
const skipImagesInput = document.getElementById('pipeline-skip-images');
const startButton = document.getElementById('pipeline-start-btn');
const currentEl = document.getElementById('pipeline-current');
const stagesEl = document.getElementById('pipeline-stages');

let activeJobId = localStorage.getItem('hawkiPipelineJobId') || '';
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

function renderPipeline(data) {
    if (!currentEl || !stagesEl) return;
    if (!data || !data.jobId) {
        currentEl.textContent = 'No pipeline selected.';
        stagesEl.innerHTML = '';
        return;
    }

    const stageName = data.currentStage || 'pending';
    const status = data.status || 'unknown';
    currentEl.textContent = `${data.jobId} · ${stageName} · ${status}`;

    const stages = data.stages || {};
    stagesEl.innerHTML = '';
    ['scrape', 'convert', 'ingest'].forEach((name) => {
        const stage = stages[name] || {};
        const row = document.createElement('div');
        row.className = 'pipeline-stage-row';
        row.innerHTML = '<strong></strong><span></span><small></small>';
        row.querySelector('strong').textContent = name;
        row.querySelector('span').textContent = stage.status || 'pending';
        row.querySelector('small').textContent = stageSummary(stage);
        stagesEl.appendChild(row);
    });
}

async function pollPipeline() {
    if (!activeJobId) return;
    try {
        const response = await fetch(`${basePath}pipeline/status/${encodeURIComponent(activeJobId)}`, {
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
        const response = await fetch(basePath + 'requestScrape', {
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

        activeJobId = data.jobId;
        localStorage.setItem('hawkiPipelineJobId', activeJobId);
        pushActivity('Pipeline', `started ${activeJobId}`);
        renderPipeline({ jobId: activeJobId, currentStage: 'scrape', status: 'running', stages: {} });
        startPolling();
    } catch (error) {
        currentEl.textContent = 'Pipeline start failed.';
        pushActivity('Pipeline', error.message || 'Pipeline start failed.');
    } finally {
        startButton.disabled = false;
    }
}

startButton?.addEventListener('click', startPipeline);

if (activeJobId) {
    startPolling();
}
