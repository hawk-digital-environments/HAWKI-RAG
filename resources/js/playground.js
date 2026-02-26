const form = document.getElementById('query-form');
const statusEl = document.getElementById('status');
const runBtn = document.getElementById('run-btn');
const results = document.getElementById('results');
const answerBlock = document.getElementById('answer-block');
const answerEl = document.getElementById('answer');
const hitsBlock = document.getElementById('hits-block');
const hitsEl = document.getElementById('hits');
const kgBlock = document.getElementById('kg-block');
const kgBody = document.querySelector('#kg-table tbody');
const rawJson = document.getElementById('raw-json');
const metaEl = document.getElementById('meta');
const provenanceBanner = document.getElementById('provenance-banner');
const mcpLatest = null;
const mcpLog = null;
const ingestStatus = document.getElementById('ingest-status');
const ingestProgress = document.getElementById('ingest-progress');
const ingestFolder = document.getElementById('ingest-folder');
const ingestBtn = document.getElementById('ingest-btn');
const ingestGraphOnlyBtn = document.getElementById('ingest-graph-only-btn');
const ingestAction = document.getElementById('ingest-action');
const ingestGraph = null;
const ingestCollection = document.getElementById('ingest-collection');
const ingestEmbeddingModel = document.getElementById('ingest-embedding-model');
const ingestGraphModel = document.getElementById('ingest-graph-model');
const ingestBatchSize = document.getElementById('ingest-batch-size');
const ingestChunkChars = document.getElementById('ingest-chunk-chars');
const ingestResumeMode = document.getElementById('ingest-resume-mode');
const ingestClearBtn = document.getElementById('ingest-clear-btn');
const ingestStopBtn = document.getElementById('ingest-stop-btn');
const ingestDeleteBtn = document.getElementById('ingest-delete-btn');
const ragStatus = document.getElementById('rag-status');
const ragDetails = document.getElementById('rag-details');
const ragStats = document.getElementById('rag-stats');
const neo4jClearBtn = document.getElementById('neo4j-clear-btn');
const neo4jClearNote = document.getElementById('neo4j-clear-note');
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
const GRAPH_COLLECTION_NAME = 'graphCol';

function badge(text) {
    const span = document.createElement('span');
    span.className = 'badge';
    span.textContent = text;
    return span;
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

function firstValue(value) {
    if (Array.isArray(value)) {
        return value[0] || '';
    }
    return value || '';
}

function summarizeLiveIngestions(items) {
    if (!Array.isArray(items) || !items.length) return null;
    return items.map((item) => {
        const path = item.path || '';
        const name = path ? path.split('/').filter(Boolean).pop() : 'unknown';
        return `${name} (pid ${item.pid})`;
    }).join(', ');
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
    if (!activityFeed) return;
    if (!message) return;
    if (lastActivityBySource.get(source) === message) return;
    lastActivityBySource.set(source, message);
    activityHistory.unshift({
        time: new Date(),
        source,
        message,
    });
    if (activityHistory.length > 20) {
        activityHistory = activityHistory.slice(0, 20);
    }
    renderActivity();
}

function extractHost(url) {
    if (!url) return null;
    try {
        return new URL(url).hostname.replace(/^www\./i, '');
    } catch (error) {
        return null;
    }
}

function parseTimestamp(value) {
    if (!value && value !== 0) return null;
    if (typeof value === 'number') {
        const ms = value > 1e12 ? value : value * 1000;
        const date = new Date(ms);
        return Number.isNaN(date.getTime()) ? null : date;
    }
    const text = String(value).trim();
    if (!text) return null;
    const parsed = Date.parse(text);
    if (Number.isNaN(parsed)) return null;
    const date = new Date(parsed);
    return Number.isNaN(date.getTime()) ? null : date;
}

function formatISODate(date) {
    if (!(date instanceof Date)) return null;
    if (Number.isNaN(date.getTime())) return null;
    return date.toISOString().slice(0, 10);
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const query = document.getElementById('question').value.trim();
    if (!query) return;

    statusEl.textContent = 'Running HAWKI RAG retrieval...';
    pushActivity('HAWKI RAG', `Query started: "${query.slice(0, 80)}"`);
    runBtn.disabled = true;
    results.style.display = 'none';
    answerBlock.style.display = 'none';
    hitsBlock.style.display = 'none';
    kgBlock.style.display = 'none';
    metaEl.innerHTML = '';
    provenanceBanner.style.display = 'none';
    provenanceBanner.textContent = '';

    const fastMode = document.getElementById('fast-mode').checked;
    const payload = {
        query,
        top_k: Number(document.getElementById('topk').value) || 5,
        generate: false,
        fast_mode: fastMode,
        smart_lookup: !fastMode,
    };

    const startedAt = performance.now();
    try {
        const response = await fetch('/hawki-rag/query', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify(payload),
        });

        const rawBody = await response.text();
        let data = null;
        if (rawBody) {
            try {
                data = JSON.parse(rawBody);
            } catch (parseErr) {
                console.error('HAWKI RAG returned non-JSON response', parseErr, rawBody);
            }
        }

        if (!response.ok) {
            const message = data && typeof data === 'object'
                ? (data.message || JSON.stringify(data))
                : `HAWKI RAG request failed (${response.status})`;
            if (!data && rawBody) {
                throw new Error(`${message}. Body excerpt: ${rawBody.slice(0, 200)}`);
            }
            throw new Error(message);
        }

        if (!data) {
            results.style.display = 'block';
            rawJson.textContent = rawBody || '';
            throw new Error('HAWKI RAG bridge returned an invalid JSON payload. Check HAWKI RAG service logs.');
        }

        results.style.display = 'block';
        rawJson.textContent = JSON.stringify(data, null, 2);
        const elapsedMs = Math.round(performance.now() - startedAt);

        const hitCount = typeof data.count === 'number'
            ? data.count
            : (Array.isArray(data.hits) ? data.hits.length : 0);
        metaEl.appendChild(badge(`hits: ${hitCount}`));
        metaEl.appendChild(badge(`latency: ${elapsedMs} ms`));
        if (data.retrieval && data.retrieval.rewrite) {
            const rewrite = data.retrieval.rewrite;
            if (rewrite.query) metaEl.appendChild(badge('rewrite: on'));
            const modal = Array.isArray(rewrite.modality_hints) ? rewrite.modality_hints.filter(Boolean) : [];
            if (modal.length) metaEl.appendChild(badge(`modalities: ${modal.slice(0, 3).join(', ')}`));
            const entities = Array.isArray(rewrite.entity_terms) ? rewrite.entity_terms.filter(Boolean) : [];
            if (entities.length) metaEl.appendChild(badge(`entities: ${entities.slice(0, 3).join(', ')}`));
        }
        if (Array.isArray(data.kg)) metaEl.appendChild(badge(`kg facts: ${data.kg.length}`));
        if (data.summary && data.summary.qdrant && data.summary.qdrant.primary_point_count !== undefined) {
            metaEl.appendChild(badge(`qdrant points: ${data.summary.qdrant.primary_point_count}`));
        }

        answerBlock.style.display = 'none';

        if (data.retrieval && data.retrieval.rewrite) {
            const rewrite = data.retrieval.rewrite;
            if (rewrite.query) {
                pushActivity('Query', `Rewrite: ${rewrite.query.slice(0, 140)}`);
            }
            if (Array.isArray(rewrite.modality_hints) && rewrite.modality_hints.length) {
                pushActivity('Query', `Modalities: ${rewrite.modality_hints.join(', ')}`);
            }
            if (Array.isArray(rewrite.entity_terms) && rewrite.entity_terms.length) {
                pushActivity('Query', `Entities: ${rewrite.entity_terms.slice(0, 6).join(', ')}`);
            }
        }

        const hits = Array.isArray(data.hits) ? data.hits : [];
        if (hits.length) {
            hitsBlock.style.display = 'block';
            hitsEl.innerHTML = '';
            hits.forEach((hit) => {
                const payload = hit.payload || {};
                const div = document.createElement('div');
                div.className = 'hit';
                const title = payload.title_text || firstValue(payload.title) || 'Untitled';
                const url = payload.page_url_text || firstValue(payload.page_url) || payload.source_url || '';
                const sourceUrl = payload.source_url || '';
                const pdfsRaw = Array.isArray(payload.pdfs) ? payload.pdfs : (payload.pdfs ? [payload.pdfs] : []);
                const pdfs = pdfsRaw.map((entry) => {
                    if (!entry) return '';
                    if (typeof entry === 'string') {
                        const match = entry.match(/https?:\/\/[^\s'"]+?\.pdf/gi);
                        return match ? match[0] : entry;
                    }
                    if (typeof entry === 'object' && entry.url) return entry.url;
                    return '';
                }).filter(Boolean);
                const parentUrl = payload.parent_url || payload.parent_page_url || '';
                const parentNode = payload.parent_node || payload.parent_id || '';
                const snippet = (payload.snippet || payload.content || '').slice(0, 400);
                const score = typeof hit.score === 'number' ? hit.score.toFixed(4) : 'n/a';
                const componentType = payload.component_type || payload.type || 'chunk';
                const sourceFormat = payload.source_format || payload.format || '';
                const detailBits = [componentType];
                if (sourceFormat) detailBits.push(sourceFormat);
                div.innerHTML = `
                            <h3>${title}</h3>
                            <p style="margin:0 0 0.35rem;font-size:0.85rem;color:#bae6fd;">score: ${score}</p>
                            <p style="margin:0 0 0.35rem;font-size:0.85rem;color:#a5b4fc;">${detailBits.join(' · ')}</p>
                            ${url ? `<p style=\"margin:0 0 0.35rem;font-size:0.9rem;\"><a href=\"${url}\" target=\"_blank\" rel=\"noopener noreferrer\">${url}</a></p>` : ''}
                            ${sourceUrl ? `<p style=\"margin:0 0 0.35rem;font-size:0.85rem;color:#cbd5f5;\">resource: <a href=\"${sourceUrl}\" target=\"_blank\" rel=\"noopener noreferrer\">${sourceUrl}</a></p>` : ''}
                            ${pdfs.length ? `<p style=\"margin:0 0 0.35rem;font-size:0.85rem;color:#cbd5f5;\">pdf: <a href=\"${pdfs[0]}\" target=\"_blank\" rel=\"noopener noreferrer\">${pdfs[0]}</a>${pdfs.length > 1 ? ` (+${pdfs.length - 1})` : ''}</p>` : ''}
                            ${parentUrl ? `<p style=\"margin:0 0 0.3rem;font-size:0.85rem;color:#fef3c7;\">parent url: ${parentUrl}</p>` : ''}
                            ${parentNode ? `<p style=\"margin:0 0 0.3rem;font-size:0.85rem;color:#fef08a;\">parent node: ${parentNode}</p>` : ''}
                            <p style=\"margin:0;font-size:0.9rem;line-height:1.55;\">${snippet.replace(/</g,'&lt;')}</p>
                        `;
                hitsEl.appendChild(div);
            });
        }

        const kg = Array.isArray(data.kg) ? data.kg : [];
        if (kg.length) {
            kgBlock.style.display = 'block';
            kgBody.innerHTML = '';
            kg.forEach((fact) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                            <td>${(fact.subject || '').replace(/</g,'&lt;')}</td>
                            <td>${(fact.relation || '').replace(/</g,'&lt;')}</td>
                            <td>${(fact.object || '').replace(/</g,'&lt;')}</td>
                        `;
                kgBody.appendChild(tr);
            });
        }

        const hostSet = new Set();
        const timestamps = [];
        const dateFields = ['ingested_at', 'updated_at', 'modified_at', 'crawled_at', 'captured_at', 'published_at', 'date'];
        hits.forEach((hit) => {
            const payload = hit.payload || {};
            const host = extractHost(
                payload.page_url_text
                || firstValue(payload.page_url)
                || payload.source_url
                || payload.parent_url
                || payload.parent_page_url
                || ''
            );
            if (host) hostSet.add(host);
            dateFields.forEach((field) => {
                const parsed = parseTimestamp(payload[field]);
                if (parsed) timestamps.push(parsed);
            });
        });
        let hostSummary = Array.from(hostSet).slice(0, 3).join(', ');
        if (hostSet.size > 3) hostSummary += ', …';
        if (!hostSummary) hostSummary = 'internal corpus';
        let latestLabel = 'unknown date';
        if (timestamps.length) {
            timestamps.sort((a, b) => b.getTime() - a.getTime());
            const formatted = formatISODate(timestamps[0]);
            if (formatted) latestLabel = formatted;
        }
        if (hits.length) {
            provenanceBanner.textContent = `Answer based on ${hits.length} internal source${hits.length === 1 ? '' : 's'} (${hostSummary}), as of ${latestLabel}.`;
        } else {
            provenanceBanner.textContent = 'No supporting sources were retrieved. Treat this as a "no answer" result.';
        }
        provenanceBanner.style.display = 'block';

        statusEl.textContent = `Done · ${elapsedMs} ms`;
        pushActivity('HAWKI RAG', `Query completed · hits: ${hitCount} · ${elapsedMs} ms`);
    } catch (error) {
        statusEl.textContent = error.message;
        console.error(error);
        provenanceBanner.textContent = 'No answer available – HAWKI RAG could not retrieve grounded sources.';
        provenanceBanner.style.display = 'block';
        pushActivity('HAWKI RAG', `Query failed: ${error.message}`);
    } finally {
        runBtn.disabled = false;
    }
});

async function pollMcpMonitor() {
    // MCP monitor removed from UI.
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
    const response = await fetch(`/ingest/status?mode=${mode}`, {
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')},
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
        const status = data ? data.status : null;
        if (status) {
            lastIngestStatus = status;
            const state = status.status || 'unknown';
            const updated = status.updated_at || '';
            ingestStatus.textContent = `status: ${state}${updated ? ` · ${updated}` : ''}`;
            pushActivity('Ingest', `status: ${state}${updated ? ` · ${updated}` : ''}`);
        }
        if (!status) {
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
        // Ignore polling errors to keep UI responsive.
    }
}

async function fetchIngestLive(mode) {
    const response = await fetch(`/ingest/live?mode=${mode}`, {
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')},
    });
    if (!response.ok) return null;
    return await response.json();
}

async function pollIngestLive() {
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
        let live = (data && data.live_ingestions) || [];
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
        const runningCount = live.filter(item => item.alive === true || (item.alive === null && item.status === 'running')).length;
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

async function loadIngestFolders() {
    try {
        const response = await fetch('/ingest/folders', {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
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

ingestBtn.addEventListener('click', async () => {
    const path = ingestFolder.value;
    if (!path) {
        ingestAction.textContent = 'Select a folder first.';
        return;
    }
    const collectionName = ingestCollection.value.trim() || path.split('/').pop();
    ingestBtn.disabled = true;
    ingestAction.textContent = 'Starting ingest…';
    try {
        const batchValue = ingestBatchSize ? parseInt(ingestBatchSize.value, 10) : null;
        const chunkCharsValue = ingestChunkChars ? parseInt(ingestChunkChars.value, 10) : null;
        const response = await fetch('/ingest/start', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                path,
                collection: collectionName,
                embedding_model: ingestEmbeddingModel ? ingestEmbeddingModel.value : undefined,
                graph_model: ingestGraphModel ? ingestGraphModel.value : undefined,
                batch: Number.isFinite(batchValue) && batchValue > 0 ? batchValue : undefined,
                chunk_chars: Number.isFinite(chunkCharsValue) && chunkCharsValue > 0 ? chunkCharsValue : undefined,
                graph: false,
                resume_mode: ingestResumeMode ? ingestResumeMode.value : 'resume',
            }),
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            ingestAction.textContent = data.message || 'Failed to start ingest.';
            pushActivity('Ingest', ingestAction.textContent);
        } else {
            ingestStatusMode = 'default';
            ingestAction.textContent = 'Ingest started. Monitor progress above.';
            pushActivity('Ingest', `Started ingest for ${collectionName} · Graph collection: ${GRAPH_COLLECTION_NAME}`);
        }
    } catch (error) {
        ingestAction.textContent = 'Failed to start ingest.';
        pushActivity('Ingest', ingestAction.textContent);
    } finally {
        ingestBtn.disabled = false;
    }
});

ingestGraphOnlyBtn.addEventListener('click', async () => {
    const path = ingestFolder.value;
    if (!path) {
        ingestAction.textContent = 'Select a folder first.';
        return;
    }
    const collectionName = ingestCollection.value.trim() || path.split('/').pop();
    ingestGraphOnlyBtn.disabled = true;
    ingestAction.textContent = 'Starting graph ingest…';
    try {
        const batchValue = ingestBatchSize ? parseInt(ingestBatchSize.value, 10) : null;
        const chunkCharsValue = ingestChunkChars ? parseInt(ingestChunkChars.value, 10) : null;
        const response = await fetch('/ingest/start', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                path,
                collection: collectionName,
                embedding_model: ingestEmbeddingModel ? ingestEmbeddingModel.value : undefined,
                graph_model: ingestGraphModel ? ingestGraphModel.value : undefined,
                batch: Number.isFinite(batchValue) && batchValue > 0 ? batchValue : undefined,
                chunk_chars: Number.isFinite(chunkCharsValue) && chunkCharsValue > 0 ? chunkCharsValue : undefined,
                graph: true,
                graph_only: true,
                resume_mode: ingestResumeMode ? ingestResumeMode.value : 'resume',
            }),
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            ingestAction.textContent = data.message || 'Failed to start graph ingest.';
            pushActivity('Ingest', ingestAction.textContent);
        } else {
            ingestStatusMode = 'neo4j';
            ingestAction.textContent = 'Graph ingest started. Monitor progress above.';
            pushActivity('Ingest', `Started graph ingest for ${collectionName} · Graph collection: ${GRAPH_COLLECTION_NAME}`);
        }
    } catch (error) {
        ingestAction.textContent = 'Failed to start graph ingest.';
        pushActivity('Ingest', ingestAction.textContent);
    } finally {
        ingestGraphOnlyBtn.disabled = false;
    }
});


ingestStopBtn.addEventListener('click', async () => {
    ingestStopBtn.disabled = true;
    ingestAction.textContent = 'Stopping ingest…';
    try {
        const stopPayload = { mode: ingestStatusMode };
        const livePids = Array.isArray(lastLiveIngestions)
            ? lastLiveIngestions.map(item => item.pid).filter(Boolean)
            : [];
        if (livePids.length) {
            stopPayload.pids = livePids;
        } else if (lastIngestStatus && lastIngestStatus.pid) {
            stopPayload.pid = lastIngestStatus.pid;
        }
        const response = await fetch('/ingest/stop', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
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
        } else {
            ingestAction.textContent = 'Ingest stopped.';
            pushActivity('Ingest', 'Ingest stopped');
        }
    } catch (error) {
        ingestAction.textContent = 'Failed to stop ingest.';
        pushActivity('Ingest', ingestAction.textContent);
    } finally {
        ingestStopBtn.disabled = false;
    }
});

ingestDeleteBtn.addEventListener('click', async () => {
    const path = ingestFolder.value;
    if (!path) {
        ingestAction.textContent = 'Select a folder first.';
        return;
    }
    const name = path.split('/').filter(Boolean).pop() || path;
    if (!confirm(`Delete ${name}? This cannot be undone.`)) {
        return;
    }
    ingestDeleteBtn.disabled = true;
    ingestAction.textContent = 'Deleting folder…';
    try {
        const response = await fetch('/ingest/delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({ path }),
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            ingestAction.textContent = data.message || 'Failed to delete folder.';
            pushActivity('Ingest', ingestAction.textContent);
        } else {
            ingestAction.textContent = `Deleted ${name}.`;
            pushActivity('Ingest', `Deleted folder ${name}`);
            if (data.folders) {
                renderFolderOptions(data.folders);
            } else {
                loadIngestFolders();
            }
        }
    } catch (error) {
        ingestAction.textContent = 'Failed to delete folder.';
        pushActivity('Ingest', ingestAction.textContent);
    } finally {
        ingestDeleteBtn.disabled = false;
    }
});

// MCP monitor removed from UI.
pollIngestLive();
setInterval(pollIngestLive, 4000);
loadIngestFolders();
if (ragDetails) {
    pollRagHealth();
    setInterval(pollRagHealth, 5000);
}
pollRagStats();
setInterval(pollRagStats, 2500);

// MCP controls removed from UI.

if (neo4jClearBtn) {
    neo4jClearBtn.addEventListener('click', async () => {
        const ok = confirm('This will delete ALL Neo4j graph data. This cannot be undone. Continue?');
        if (!ok) return;
        neo4jClearBtn.disabled = true;
        if (neo4jClearNote) neo4jClearNote.textContent = 'Clearing Neo4j graph…';
        try {
            const response = await fetch('/rag/neo4j/clear', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') },
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                if (neo4jClearNote) neo4jClearNote.textContent = data.message || 'Failed to clear Neo4j graph.';
                pushActivity('Graph', 'Neo4j clear failed');
            } else {
                if (neo4jClearNote) neo4jClearNote.textContent = 'Neo4j graph cleared.';
                pushActivity('Graph', 'Neo4j graph cleared');
                pollRagStats();
            }
        } catch (error) {
            if (neo4jClearNote) neo4jClearNote.textContent = 'Failed to clear Neo4j graph.';
            pushActivity('Graph', 'Neo4j clear failed');
        } finally {
            neo4jClearBtn.disabled = false;
        }
    });
}

if (ingestClearBtn) {
    ingestClearBtn.addEventListener('click', async () => {
        ingestClearBtn.disabled = true;
        try {
            await fetch('/ingest/status/clear?mode=all', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                },
            });
            if (ingestStatus) ingestStatus.textContent = 'No ingest activity yet.';
            if (ingestProgress) ingestProgress.innerHTML = '';
            pushActivity('Ingest', 'Ingest logs cleared');
        } catch (error) {
            // Ignore failures.
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

async function pollRagHealth() {
    if (!ragDetails) return;
    try {
        const response = await fetch('/rag/health', {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')},
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
    try {
        const response = await fetch('/rag/stats', {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') },
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
