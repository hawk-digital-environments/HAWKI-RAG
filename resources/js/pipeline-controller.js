import './bootstrap';
import './health-gate.js';
import './playground/pipeline.js';
import { apiUrl } from './playground/urls.js';

const refreshButton = document.getElementById('pipeline-controller-refresh');
const fileForm = document.getElementById('pipeline-file-form');
const fileInput = document.getElementById('pipeline-file-input');
const graphInput = document.getElementById('pipeline-file-graph');
const submitButton = document.getElementById('pipeline-file-submit');
const fileNote = document.getElementById('pipeline-file-note');

refreshButton?.addEventListener('click', () => {
    window.location.reload();
});

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
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

function setFileNote(message, tone = 'info') {
    if (!fileNote) return;
    fileNote.textContent = message || '';
    fileNote.dataset.tone = tone;
}

function supportedFileMessage() {
    return 'Choose a file to convert.';
}

fileForm?.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (!fileInput?.files?.length) {
        setFileNote(supportedFileMessage(), 'warn');
        return;
    }

    const formData = new FormData(fileForm);
    formData.set('graph', graphInput?.checked ? 'true' : 'false');

    if (submitButton) submitButton.disabled = true;
    setFileNote('Queueing converter job...');

    try {
        const response = await fetch(apiUrl('pipeline/controller/files'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: formData,
        });
        const data = await parseResponseJson(response);

        if (!response.ok || !data.success || !data.taskId) {
            throw new Error(data.message || `File pipeline start failed (${response.status})`);
        }

        setFileNote(`Queued convert job ${data.jobId}. Workers will continue ingestion.`, 'success');
        window.hawkiPipelineController?.selectTask?.(data.taskId);
        window.hawkiPipelineController?.refreshRuns?.();
    } catch (error) {
        setFileNote(error.message || 'File pipeline start failed.', 'error');
    } finally {
        if (submitButton) submitButton.disabled = false;
    }
});
