import { apiUrl } from './urls.js';

const ingestStatus = document.getElementById('ingest-status');
const ingestProgress = document.getElementById('ingest-progress');
const ingestClearBtn = document.getElementById('ingest-clear-btn');
const ragDetails = document.getElementById('rag-details');
const ragStats = document.getElementById('rag-stats');
const ingestLiveStatus = document.getElementById('ingest-live-status');
const ingestLiveList = document.getElementById('ingest-live-list');
const activityFeed = document.getElementById('activity-feed');
const activityClearBtn = document.getElementById('activity-clear-btn');

let activityHistory = [];
const lastActivityBySource = new Map();
let lastRagStatsHash = '';
let lastIngestStatus = null;
let lastLiveIngestions = [];
let ingestStatusMode = 'default';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function appendDetailRow(container, label, value) {
    if (!container || value === undefined || value === null || value === '') return;
    const row = document.createElement('div');
    row.className = 'stat-row';
    row.innerHTML = `<span class="stat-label">${label}</span><span class="stat-value"></span>`;
    row.querySelector('.stat-value').textContent = String(value);
    container.appendChild(row);
}

function renderRagRuntimeDetails(payload) {
    if (!ragDetails) return;
    ragDetails.innerHTML = '';
    if (!payload) return;

    const runtime = payload.runtime || payload.data?.runtime || null;
    if (!runtime) return;

    const card = document.createElement('div');
    card.className = 'stat-card';
    card.innerHTML = '<h4>Runtime graph/KV setup</h4>';
    appendDetailRow(card, 'Working dir', runtime.working_dir);
    appendDetailRow(card, 'Graph backend', runtime.graph_storage);
    appendDetailRow(card, 'Doc status backend', runtime.doc_status_storage);
    appendDetailRow(card, 'Neo4j URI', runtime.neo4j?.uri);
    appendDetailRow(card, 'Neo4j DB', runtime.neo4j?.database);
    appendDetailRow(card, 'Graph model', runtime.models?.graph_model);
    appendDetailRow(card, 'Embed model', runtime.models?.embed_model);
    appendDetailRow(card, 'Graph max chars', runtime.limits?.graph_doc_max_chars);
    appendDetailRow(card, 'Graph max chunks', runtime.limits?.graph_doc_max_chunks);
    appendDetailRow(card, 'Chat timeout (s)', runtime.limits?.ollama_chat_timeout);
    appendDetailRow(card, 'Doc-status chunk files', runtime.doc_status_chunks?.count);
    if (Array.isArray(runtime.doc_status_chunks?.files) && runtime.doc_status_chunks.files.length) {
        appendDetailRow(card, 'Chunk file sample', runtime.doc_status_chunks.files.join(', '));
    }
    ragDetails.appendChild(card);
}

function formatTime(date) {
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function renderActivity() {
    if (!activityFeed) return;
    activityFeed.innerHTML = '';
    activityHistory.forEach((entry) => {
        const row = document.createElement('div');
        row.className = 'activity-item';
        row.innerHTML = `
            <div>${formatTime(entry.time)}</div>
            <strong>${entry.source}</strong>
            <div>${entry.message}</div>
        `;
        activityFeed.appendChild(row);
    });
}

function pushActivity(source, message) {
    if (!activityFeed || !message) return;
    if (lastActivityBySource.get(source) === message) return;
    lastActivityBySource.set(source, message);
    activityHistory.unshift({ time: new Date(), source, message });
    if (activityHistory.length > 20) {
        activityHistory = activityHistory.slice(0, 20);
    }
    renderActivity();
}

function formatProgress(progress) {
    if (!progress) return null;
    if (progress.sent !== undefined && progress.total !== undefined) {
        const mode = progress.mode === 'dry' ? 'dry-run' : 'ingest';
        return `${mode} progress: ${progress.sent}/${progress.total} docs`;
    }
    if (progress.found_pdfs !== undefined) {
        return `found PDFs: ${progress.found_pdfs}`;
    }
    return null;
}

async function fetchIngestStatus(mode) {
    const response = await fetch(apiUrl(`ingest/status?mode=${mode}`), {
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });
    if (!response.ok) return null;
    return await response.json();
}

async function pollIngestStatus() {
    if (!ingestStatus || !ingestProgress) return;
    try {
        let data = await fetchIngestStatus(ingestStatusMode);
        if (!data || (!data.status && !(Array.isArray(data.log_lines) && data.log_lines.length))) {
            const fallbackMode = ingestStatusMode === 'neo4j' ? 'default' : 'neo4j';
            const fallback = await fetchIngestStatus(fallbackMode);
            if (fallback && (fallback.status || (Array.isArray(fallback.log_lines) && fallback.log_lines.length))) {
                data = fallback;
                ingestStatusMode = fallbackMode;
            }
        }
        if (!data) return;

        const status = data.status || null;
        if (status) {
            lastIngestStatus = status;
            const state = status.status || 'unknown';
            const updated = status.updated_at || '';
            ingestStatus.textContent = `status: ${state}${updated ? ` · ${updated}` : ''}`;
            pushActivity('Ingest', `status: ${state}${updated ? ` · ${updated}` : ''}`);
        } else {
            lastIngestStatus = null;
        }

        ingestProgress.innerHTML = '';
        if (status) {
            const progressText = formatProgress(status.progress);
            if (progressText) {
                const row = document.createElement('div');
                row.className = 'badge';
                row.textContent = progressText;
                ingestProgress.appendChild(row);
            }
            if (status.progress && status.progress.folders) {
                const row = document.createElement('div');
                row.className = 'badge';
                row.textContent = `folders: ${status.progress.folders.current}/${status.progress.folders.total}`;
                ingestProgress.appendChild(row);
            }
            if (status.last_line) {
                const row = document.createElement('div');
                row.className = 'badge';
                row.textContent = status.last_line;
                ingestProgress.appendChild(row);
                pushActivity('Ingest', status.last_line);
            }
        }

        if (Array.isArray(data.log_lines) && data.log_lines.length) {
            data.log_lines.slice(-6).forEach((line) => {
                const row = document.createElement('div');
                row.className = 'badge';
                row.textContent = line;
                ingestProgress.appendChild(row);
            });
        }
    } catch (error) {
        // Polling should never block the rest of the playground.
    }
}

async function fetchIngestLive(mode) {
    const response = await fetch(apiUrl(`ingest/live?mode=${mode}`), {
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });
    if (!response.ok) return null;
    return await response.json();
}

async function pollIngestLive() {
    if (!ingestLiveStatus || !ingestLiveList) return;
    try {
        let data = await fetchIngestLive(ingestStatusMode);
        if (!data || !Array.isArray(data.live_ingestions) || !data.live_ingestions.length) {
            const fallbackMode = ingestStatusMode === 'neo4j' ? 'default' : 'neo4j';
            const fallback = await fetchIngestLive(fallbackMode);
            if (fallback && Array.isArray(fallback.live_ingestions) && fallback.live_ingestions.length) {
                data = fallback;
                ingestStatusMode = fallbackMode;
            }
        }
        if (!data) return;

        let live = data.live_ingestions || [];
        lastLiveIngestions = Array.isArray(live) ? live : [];
        if (!live.length && lastIngestStatus && (lastIngestStatus.pid || lastIngestStatus.path)) {
            live = [{
                pid: lastIngestStatus.pid || null,
                path: lastIngestStatus.path || null,
                status: lastIngestStatus.status || null,
                source: 'status',
                alive: lastIngestStatus.status === 'running' ? null : false,
            }];
        }

        ingestLiveList.innerHTML = '';
        if (!live.length) {
            ingestLiveStatus.textContent = 'No ingest status available.';
            return;
        }

        const runningCount = live.filter((item) => item.alive === true || (item.alive === null && item.status === 'running')).length;
        ingestLiveStatus.textContent = `running: ${runningCount} · last seen: ${live.length}`;
        live.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'badge';
            const path = item.path || '';
            const name = path ? path.split('/').filter(Boolean).pop() : 'unknown';
            const pidLabel = item.pid ? `pid ${item.pid}` : 'pid n/a';
            const sourceLabel = item.source ? ` · ${item.source}` : '';
            const aliveLabel = item.alive === true ? ' · running' : (item.alive === false ? ' · stopped' : '');
            const collectionLabel = item.collection ? ` · ${item.collection}` : '';
            row.textContent = `${name} · ${pidLabel}${sourceLabel}${collectionLabel}${aliveLabel}`;
            ingestLiveList.appendChild(row);
        });
    } catch (error) {
        // Ignore polling errors.
    }
}

async function pollRagHealth() {
    if (!ragDetails) return;
    try {
        const response = await fetch(apiUrl('rag/health'), {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            ragDetails.innerHTML = '';
            pushActivity('RAG', 'status: offline');
            return;
        }
        renderRagRuntimeDetails(data);
        pushActivity('RAG', `status: ok · ${data.latency_ms}ms`);
    } catch (error) {
        ragDetails.innerHTML = '';
        pushActivity('RAG', 'status: offline');
    }
}

async function pollRagStats() {
    if (!ragStats) return;
    try {
        const response = await fetch(apiUrl('rag/stats'), {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            ragStats.innerHTML = '<div class="badge">stats: unavailable</div>';
            pushActivity('Stats', 'stats: unavailable');
            return;
        }

        ragStats.innerHTML = '';
        const statsSnapshot = [];
        if (data.qdrant && data.qdrant.collections) {
            const card = document.createElement('div');
            card.className = 'stat-card';
            card.innerHTML = '<h4>Qdrant collections</h4>';
            let hiddenEmpty = 0;
            data.qdrant.collections.forEach((col) => {
                const count = col.count ?? 'n/a';
                if (typeof count === 'number' && count === 0) {
                    hiddenEmpty += 1;
                    return;
                }
                const row = document.createElement('div');
                row.className = 'stat-row';
                row.innerHTML = `<span class="stat-label">${col.name}</span><span class="stat-value">${count} points</span>`;
                card.appendChild(row);
                statsSnapshot.push(`${col.name}:${count}`);
            });
            if (hiddenEmpty > 0) {
                const note = document.createElement('div');
                note.className = 'stat-row';
                note.innerHTML = `<span class="stat-label">Hidden empty collections</span><span class="stat-value">${hiddenEmpty}</span>`;
                card.appendChild(note);
            }
            ragStats.appendChild(card);
        }

        if (data.neo4j && data.neo4j.ok) {
            const card = document.createElement('div');
            card.className = 'stat-card';
            card.innerHTML = '<h4>Neo4j graph stats</h4>';
            const row = document.createElement('div');
            row.className = 'stat-row';
            row.innerHTML = `<span class="stat-label">Triplets</span><span class="stat-value">${data.neo4j.triplets}</span>`;
            card.appendChild(row);
            const row2 = document.createElement('div');
            row2.className = 'stat-row';
            row2.innerHTML = `<span class="stat-label">Entities</span><span class="stat-value">${data.neo4j.entities}</span>`;
            card.appendChild(row2);
            statsSnapshot.push(`neo4j:${data.neo4j.triplets}:${data.neo4j.entities}`);

            if (Array.isArray(data.neo4j.relationship_types) && data.neo4j.relationship_types.length) {
                data.neo4j.relationship_types.slice(0, 6).forEach((rel) => {
                    const relRow = document.createElement('div');
                    relRow.className = 'stat-row';
                    relRow.innerHTML = `<span class="stat-label">${rel.type}</span><span class="stat-value">${rel.count}</span>`;
                    card.appendChild(relRow);
                });
            }
            ragStats.appendChild(card);
        }

        const statsHash = statsSnapshot.join('|');
        if (statsHash && statsHash !== lastRagStatsHash) {
            pushActivity('Stats', `Updated · Qdrant collections: ${data.qdrant?.collections?.length ?? 0} · Neo4j triplets: ${data.neo4j?.triplets ?? 0}`);
            lastRagStatsHash = statsHash;
        }
    } catch (error) {
        ragStats.innerHTML = '<div class="badge">stats: unavailable</div>';
        pushActivity('Stats', 'stats: unavailable');
    }
}

if (ingestClearBtn) {
    ingestClearBtn.addEventListener('click', async () => {
        ingestClearBtn.disabled = true;
        try {
            await fetch(apiUrl('ingest/status/clear?mode=all'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });
            if (ingestStatus) ingestStatus.textContent = 'No ingest activity yet.';
            if (ingestProgress) ingestProgress.innerHTML = '';
            pushActivity('Ingest', 'Ingest logs cleared');
        } finally {
            ingestClearBtn.disabled = false;
        }
    });
}

if (activityClearBtn) {
    activityClearBtn.addEventListener('click', () => {
        activityHistory = [];
        lastActivityBySource.clear();
        renderActivity();
    });
}

window.playgroundLogs = {
    csrfToken,
    pushActivity,
    pollRagStats,
    pollIngestStatus,
    pollIngestLive,
    getIngestStatusMode: () => ingestStatusMode,
    setIngestStatusMode: (mode) => {
        ingestStatusMode = mode || 'default';
    },
    getLastIngestStatus: () => lastIngestStatus,
    getLastLiveIngestions: () => lastLiveIngestions,
};

pollIngestStatus();
setInterval(pollIngestStatus, 2500);
pollIngestLive();
setInterval(pollIngestLive, 4000);

if (ragDetails) {
    pollRagHealth();
    setInterval(pollRagHealth, 5000);
}

pollRagStats();
setInterval(pollRagStats, 2500);
