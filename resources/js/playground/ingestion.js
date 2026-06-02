import { apiUrl } from './urls.js';

const GRAPH_COLLECTION_NAME = 'graphCol';

const ingestFolder = document.getElementById('ingest-folder');
const ingestBtn = document.getElementById('ingest-btn');
const ingestGraphOnlyBtn = document.getElementById('ingest-graph-only-btn');
const ingestAction = document.getElementById('ingest-action');
const ingestCollection = document.getElementById('ingest-collection');
const ingestEmbeddingModel = document.getElementById('ingest-embedding-model');
const ingestGraphModel = document.getElementById('ingest-graph-model');
const ingestBatchSize = document.getElementById('ingest-batch-size');
const ingestChunkChars = document.getElementById('ingest-chunk-chars');
const ingestResumeMode = document.getElementById('ingest-resume-mode');
const ingestStopBtn = document.getElementById('ingest-stop-btn');
const ingestDeleteBtn = document.getElementById('ingest-delete-btn');

function logs() {
    return window.playgroundLogs || {};
}

function csrfToken() {
    return logs().csrfToken ? logs().csrfToken() : document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function pushActivity(source, message) {
    if (logs().pushActivity) logs().pushActivity(source, message);
}

function summarizeLiveIngestions(items) {
    if (!Array.isArray(items) || !items.length) return null;
    return items.map((item) => {
        const path = item.path || '';
        const name = path ? path.split('/').filter(Boolean).pop() : 'unknown';
        return `${name} (pid ${item.pid})`;
    }).join(', ');
}

function selectedCollectionName(path) {
    return ingestCollection?.value.trim() || path.split('/').pop();
}

function ingestPayload(path, collectionName, { graphOnly = false } = {}) {
    const batchValue = ingestBatchSize ? parseInt(ingestBatchSize.value, 10) : null;
    const chunkCharsValue = ingestChunkChars ? parseInt(ingestChunkChars.value, 10) : null;
    return {
        path,
        collection: collectionName,
        embedding_model: ingestEmbeddingModel ? ingestEmbeddingModel.value : undefined,
        graph_model: ingestGraphModel ? ingestGraphModel.value : undefined,
        batch: Number.isFinite(batchValue) && batchValue > 0 ? batchValue : undefined,
        chunk_chars: Number.isFinite(chunkCharsValue) && chunkCharsValue > 0 ? chunkCharsValue : undefined,
        graph: graphOnly,
        graph_only: graphOnly || undefined,
        resume_mode: ingestResumeMode ? ingestResumeMode.value : 'resume',
    };
}

async function loadIngestFolders() {
    if (!ingestFolder) return;
    try {
        const response = await fetch(apiUrl('ingest/folders'), {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
        if (!response.ok) {
            ingestFolder.innerHTML = '<option value="">Failed to load folders</option>';
            return;
        }
        const data = await response.json();
        renderFolderOptions(data && data.folders);
    } catch (error) {
        ingestFolder.innerHTML = '<option value="">Failed to load folders</option>';
    }
}

function renderFolderOptions(folders) {
    if (!ingestFolder) return;
    if (!Array.isArray(folders) || !folders.length) {
        ingestFolder.innerHTML = '<option value="">No folders found</option>';
        return;
    }
    ingestFolder.innerHTML = '';
    folders.forEach((item) => {
        const option = document.createElement('option');
        option.value = item.path;
        option.textContent = item.name;
        ingestFolder.appendChild(option);
    });
}

async function startIngest({ graphOnly = false } = {}) {
    const button = graphOnly ? ingestGraphOnlyBtn : ingestBtn;
    if (!button || !ingestFolder || !ingestAction) return;

    const path = ingestFolder.value;
    if (!path) {
        ingestAction.textContent = 'Select a folder first.';
        return;
    }

    const collectionName = selectedCollectionName(path);
    button.disabled = true;
    ingestAction.textContent = graphOnly ? 'Starting graph ingest…' : 'Starting ingest…';

    try {
        const response = await fetch(apiUrl('ingest/start'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(ingestPayload(path, collectionName, { graphOnly })),
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            ingestAction.textContent = data.message || (graphOnly ? 'Failed to start graph ingest.' : 'Failed to start ingest.');
            pushActivity('Ingest', ingestAction.textContent);
            return;
        }

        logs().setIngestStatusMode?.(graphOnly ? 'neo4j' : 'default');
        ingestAction.textContent = graphOnly
            ? 'Graph ingest started. Monitor progress above.'
            : 'Ingest started. Monitor progress above.';
        pushActivity('Ingest', `Started ${graphOnly ? 'graph ingest' : 'ingest'} for ${collectionName} · Graph collection: ${GRAPH_COLLECTION_NAME}`);
        logs().pollIngestStatus?.();
        logs().pollIngestLive?.();
    } catch (error) {
        ingestAction.textContent = graphOnly ? 'Failed to start graph ingest.' : 'Failed to start ingest.';
        pushActivity('Ingest', ingestAction.textContent);
    } finally {
        button.disabled = false;
    }
}

async function stopIngest() {
    if (!ingestStopBtn || !ingestAction) return;
    ingestStopBtn.disabled = true;
    ingestAction.textContent = 'Stopping ingest…';
    try {
        const stopPayload = { mode: logs().getIngestStatusMode?.() || 'default' };
        const livePids = Array.isArray(logs().getLastLiveIngestions?.())
            ? logs().getLastLiveIngestions().map((item) => item.pid).filter(Boolean)
            : [];
        const lastStatus = logs().getLastIngestStatus?.();
        if (livePids.length) {
            stopPayload.pids = livePids;
        } else if (lastStatus && lastStatus.pid) {
            stopPayload.pid = lastStatus.pid;
        }

        const response = await fetch(apiUrl('ingest/stop'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(stopPayload),
        });
        const data = await response.json();
        const liveSummary = summarizeLiveIngestions(data && data.live_ingestions);
        if (liveSummary) {
            pushActivity('Ingest', `Live ingestions before stop: ${liveSummary}`);
        }
        if (!response.ok || !data.ok) {
            ingestAction.textContent = data.message || 'Failed to stop ingest.';
            pushActivity('Ingest', ingestAction.textContent);
            return;
        }
        ingestAction.textContent = 'Ingest stopped.';
        pushActivity('Ingest', 'Ingest stopped');
        logs().pollIngestStatus?.();
        logs().pollIngestLive?.();
    } catch (error) {
        ingestAction.textContent = 'Failed to stop ingest.';
        pushActivity('Ingest', ingestAction.textContent);
    } finally {
        ingestStopBtn.disabled = false;
    }
}

async function deleteIngestFolder() {
    if (!ingestDeleteBtn || !ingestFolder || !ingestAction) return;
    const path = ingestFolder.value;
    if (!path) {
        ingestAction.textContent = 'Select a folder first.';
        return;
    }

    const name = path.split('/').filter(Boolean).pop() || path;
    if (!confirm(`Delete ${name}? This cannot be undone.`)) return;

    ingestDeleteBtn.disabled = true;
    ingestAction.textContent = 'Deleting folder…';
    try {
        const response = await fetch(apiUrl('ingest/delete'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ path }),
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            ingestAction.textContent = data.message || 'Failed to delete folder.';
            pushActivity('Ingest', ingestAction.textContent);
            return;
        }

        ingestAction.textContent = `Deleted ${name}.`;
        pushActivity('Ingest', `Deleted folder ${name}`);
        if (data.folders) {
            renderFolderOptions(data.folders);
        } else {
            loadIngestFolders();
        }
    } catch (error) {
        ingestAction.textContent = 'Failed to delete folder.';
        pushActivity('Ingest', ingestAction.textContent);
    } finally {
        ingestDeleteBtn.disabled = false;
    }
}

if (ingestBtn) {
    ingestBtn.addEventListener('click', () => startIngest({ graphOnly: false }));
}

if (ingestGraphOnlyBtn) {
    ingestGraphOnlyBtn.addEventListener('click', () => startIngest({ graphOnly: true }));
}

if (ingestStopBtn) {
    ingestStopBtn.addEventListener('click', stopIngest);
}

if (ingestDeleteBtn) {
    ingestDeleteBtn.addEventListener('click', deleteIngestFolder);
}

loadIngestFolders();
