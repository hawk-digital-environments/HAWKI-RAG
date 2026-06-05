import { apiUrl } from './urls.js';

const ingestStatus = document.getElementById('ingest-status');
const ingestProgress = document.getElementById('ingest-progress');
const ingestClearBtn = document.getElementById('ingest-clear-btn');
const ragDetails = document.getElementById('rag-details');
const ragMonitorStatus = document.getElementById('rag-monitor-status');
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

function createStatCard(title) {
    const card = document.createElement('div');
    card.className = 'stat-card';
    const heading = document.createElement('h4');
    heading.textContent = title;
    card.appendChild(heading);
    return card;
}

function formatFlag(value) {
    return value ? 'enabled' : 'disabled';
}

function formatUpdated(value) {
    if (!value) return null;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString([], {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

function appendIngestStatusCard(mode, status) {
    if (!ragDetails || !status) return;
    const card = createStatCard(`${mode === 'neo4j' ? 'Neo4j' : 'Qdrant'} ingest status`);
    appendDetailRow(card, 'Status', status.status || 'unknown');
    appendDetailRow(card, 'Collection', status.collection);
    appendDetailRow(card, 'Graph', status.graph ? 'enabled' : (status.graph_only ? 'graph-only' : 'disabled'));
    appendDetailRow(card, 'Resume mode', status.resume_mode);
    appendDetailRow(card, 'PID', status.pid);
    appendDetailRow(card, 'Updated', formatUpdated(status.updated_at));
    if (status.path) {
        appendDetailRow(card, 'Folder', String(status.path).split('/').filter(Boolean).pop() || status.path);
    }
    ragDetails.appendChild(card);
}

function renderRagMonitor(payload) {
    if (!ragDetails) return;
    ragDetails.innerHTML = '';
    if (!payload) {
        if (ragMonitorStatus) {
            ragMonitorStatus.dataset.state = 'warn';
            ragMonitorStatus.textContent = 'RAG-Anything monitor unavailable.';
        }
        return;
    }

    const bridge = payload.bridge || {};
    if (ragMonitorStatus) {
        ragMonitorStatus.dataset.state = bridge.ok ? 'ok' : 'fail';
        ragMonitorStatus.textContent = bridge.ok
            ? `Bridge online · ${bridge.latency_ms ?? 'n/a'}ms`
            : `Bridge offline · HTTP ${bridge.status ?? 502}`;
    }

    const config = payload.config || {};
    const configCard = createStatCard('Active graph extraction settings');
    appendDetailRow(configCard, 'Engine', config.graph_engine || 'raganything');
    appendDetailRow(configCard, 'Provider', config.graph_provider || 'ollama');
    appendDetailRow(configCard, 'Graph model', config.graph_model);
    appendDetailRow(configCard, 'Embedding model', config.embedding_model);
    appendDetailRow(configCard, 'RAG chunk size', config.chunk_size);
    appendDetailRow(configCard, 'RAG chunk overlap', config.chunk_overlap);
    appendDetailRow(configCard, 'Graph max chars', config.graph_doc_max_chars || 'unlimited');
    appendDetailRow(configCard, 'Graph max chunks', config.graph_doc_max_chunks || 'unlimited');
    appendDetailRow(configCard, 'Cache reset per doc', formatFlag(config.graph_reset_cache_per_doc));
    ragDetails.appendChild(configCard);

    const runtime = payload.runtime || null;
    if (runtime) {
        const card = createStatCard('Runtime graph/KV setup');
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

    const latestDocumentGraph = payload.latest_document_graph || null;
    if (latestDocumentGraph) {
        const card = createStatCard('Latest indexed document graph');
        appendDetailRow(card, 'Document', latestDocumentGraph.title || latestDocumentGraph.document_id);
        appendDetailRow(card, 'Dataset', latestDocumentGraph.dataset_id);
        appendDetailRow(card, 'Collection', latestDocumentGraph.collection);
        appendDetailRow(card, 'Qdrant points', latestDocumentGraph.qdrant_points);
        appendDetailRow(card, 'Graph enabled', formatFlag(latestDocumentGraph.graph_enabled));
        appendDetailRow(card, 'Graph triplets', latestDocumentGraph.graph_triplets);
        appendDetailRow(card, 'Docs with triplets', latestDocumentGraph.docs_with_triplets);
        appendDetailRow(card, 'Updated', formatUpdated(latestDocumentGraph.updated_at));
        ragDetails.appendChild(card);
    }

    const summary = payload.summary?.data || null;
    if (summary) {
        const graph = summary.graph_preview || null;
        const card = createStatCard('Latest ingest summary');
        appendDetailRow(card, 'Updated', formatUpdated(payload.summary?.updated_at));
        appendDetailRow(card, 'Qdrant collection', summary.qdrant_preview?.collection || summary.collection);
        appendDetailRow(card, 'Qdrant points', summary.qdrant_preview?.planned_points || summary.planned_points);
        appendDetailRow(card, 'Documents', summary.documents?.processed_docs);
        appendDetailRow(card, 'RAG chunks', summary.documents?.total_chunks);
        appendDetailRow(card, 'Graph enabled', formatFlag(summary.graph?.enabled || graph));
        appendDetailRow(card, 'Graph triplets', graph?.total_triplets);
        appendDetailRow(card, 'Docs with triplets', graph?.docs_with_triplets);
        appendDetailRow(card, 'Total time', summary.total_ms ? `${Math.round(summary.total_ms)}ms` : null);
        ragDetails.appendChild(card);
    }

    const preview = payload.graph_preview?.data || summary?.graph_preview || null;
    if (preview) {
        const card = createStatCard('Latest graph preview');
        appendDetailRow(card, 'Updated', formatUpdated(payload.graph_preview?.updated_at || preview.timestamp));
        appendDetailRow(card, 'Total docs', preview.total_docs);
        appendDetailRow(card, 'Total chunks', preview.total_chunks);
        appendDetailRow(card, 'Docs with triplets', preview.docs_with_triplets);
        appendDetailRow(card, 'Total triplets', preview.total_triplets);
        Object.entries(preview.per_doc || {}).slice(0, 3).forEach(([docId, item]) => {
            const title = item.title || item.source_url || docId;
            appendDetailRow(card, String(title).slice(0, 36), `${item.triplets ?? 0} triplets · ${item.chunks ?? 0} chunks`);
        });
        ragDetails.appendChild(card);
    }

    appendIngestStatusCard('neo4j', payload.latest_ingest?.neo4j);
    appendIngestStatusCard('default', payload.latest_ingest?.default);

    if (Array.isArray(payload.graph_failures) && payload.graph_failures.length) {
        const card = createStatCard('Recent graph extraction failures');
        payload.graph_failures.forEach((failure, index) => {
            appendDetailRow(card, `Failure ${index + 1}`, failure.error || failure.message || 'unknown');
        });
        ragDetails.appendChild(card);
    }
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

async function pollRagMonitor() {
    if (!ragDetails) return;
    try {
        const response = await fetch(apiUrl('rag/monitor'), {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            ragDetails.innerHTML = '';
            if (ragMonitorStatus) {
                ragMonitorStatus.dataset.state = 'fail';
                ragMonitorStatus.textContent = 'RAG-Anything monitor offline.';
            }
            pushActivity('RAG', 'status: offline');
            return;
        }
        renderRagMonitor(data);
        pushActivity('RAG', `status: ${data.bridge?.ok ? 'ok' : 'offline'} · ${data.bridge?.latency_ms ?? 'n/a'}ms`);
    } catch (error) {
        ragDetails.innerHTML = '';
        if (ragMonitorStatus) {
            ragMonitorStatus.dataset.state = 'fail';
            ragMonitorStatus.textContent = 'RAG-Anything monitor offline.';
        }
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
    pollRagMonitor();
    setInterval(pollRagMonitor, 5000);
}

pollRagStats();
setInterval(pollRagStats, 2500);
