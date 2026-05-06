const basePath = import.meta.env.BASE_URL ?? '/';

const form = document.getElementById('query-form');
const statusEl = document.getElementById('status');
const runBtn = document.getElementById('run-btn');
const results = document.getElementById('results');
const answerBlock = document.getElementById('answer-block');
const hitsBlock = document.getElementById('hits-block');
const hitsEl = document.getElementById('hits');
const kgBlock = document.getElementById('kg-block');
const kgBody = document.querySelector('#kg-table tbody');
const rawJson = document.getElementById('raw-json');
const metaEl = document.getElementById('meta');
const provenanceBanner = document.getElementById('provenance-banner');

function csrfToken() {
    return window.playgroundLogs?.csrfToken
        ? window.playgroundLogs.csrfToken()
        : document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function pushActivity(source, message) {
    window.playgroundLogs?.pushActivity?.(source, message);
}

function badge(text) {
    const span = document.createElement('span');
    span.className = 'badge';
    span.textContent = text;
    return span;
}

function firstValue(value) {
    if (Array.isArray(value)) return value[0] || '';
    return value || '';
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
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) return null;
    return date.toISOString().slice(0, 10);
}

function escapeHtml(value) {
    return String(value || '').replace(/</g, '&lt;');
}

function resetQueryUi() {
    results.style.display = 'none';
    answerBlock.style.display = 'none';
    hitsBlock.style.display = 'none';
    kgBlock.style.display = 'none';
    metaEl.innerHTML = '';
    provenanceBanner.style.display = 'none';
    provenanceBanner.textContent = '';
}

function renderHit(hit) {
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
        <h3>${escapeHtml(title)}</h3>
        <p style="margin:0 0 0.35rem;font-size:0.85rem;color:#bae6fd;">score: ${score}</p>
        <p style="margin:0 0 0.35rem;font-size:0.85rem;color:#a5b4fc;">${detailBits.join(' · ')}</p>
        ${url ? `<p style="margin:0 0 0.35rem;font-size:0.9rem;"><a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a></p>` : ''}
        ${sourceUrl ? `<p style="margin:0 0 0.35rem;font-size:0.85rem;color:#cbd5f5;">resource: <a href="${sourceUrl}" target="_blank" rel="noopener noreferrer">${sourceUrl}</a></p>` : ''}
        ${pdfs.length ? `<p style="margin:0 0 0.35rem;font-size:0.85rem;color:#cbd5f5;">pdf: <a href="${pdfs[0]}" target="_blank" rel="noopener noreferrer">${pdfs[0]}</a>${pdfs.length > 1 ? ` (+${pdfs.length - 1})` : ''}</p>` : ''}
        ${parentUrl ? `<p style="margin:0 0 0.3rem;font-size:0.85rem;color:#fef3c7;">parent url: ${parentUrl}</p>` : ''}
        ${parentNode ? `<p style="margin:0 0 0.3rem;font-size:0.85rem;color:#fef08a;">parent node: ${parentNode}</p>` : ''}
        <p style="margin:0;font-size:0.9rem;line-height:1.55;">${escapeHtml(snippet)}</p>
    `;
    return div;
}

function renderQueryMeta(data, elapsedMs, hitCount) {
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
}

function pushRewriteActivity(data) {
    const rewrite = data.retrieval?.rewrite;
    if (!rewrite) return;
    if (rewrite.query) pushActivity('Query', `Rewrite: ${rewrite.query.slice(0, 140)}`);
    if (Array.isArray(rewrite.modality_hints) && rewrite.modality_hints.length) {
        pushActivity('Query', `Modalities: ${rewrite.modality_hints.join(', ')}`);
    }
    if (Array.isArray(rewrite.entity_terms) && rewrite.entity_terms.length) {
        pushActivity('Query', `Entities: ${rewrite.entity_terms.slice(0, 6).join(', ')}`);
    }
}

function renderHits(hits) {
    if (!hits.length) return;
    hitsBlock.style.display = 'block';
    hitsEl.innerHTML = '';
    hits.forEach((hit) => hitsEl.appendChild(renderHit(hit)));
}

function renderKgFacts(kg) {
    if (!kg.length) return;
    kgBlock.style.display = 'block';
    kgBody.innerHTML = '';
    kg.forEach((fact) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${escapeHtml(fact.subject)}</td>
            <td>${escapeHtml(fact.relation)}</td>
            <td>${escapeHtml(fact.object)}</td>
        `;
        kgBody.appendChild(tr);
    });
}

function renderProvenance(hits) {
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
    if (hostSet.size > 3) hostSummary += ', ...';
    if (!hostSummary) hostSummary = 'internal corpus';
    let latestLabel = 'unknown date';
    if (timestamps.length) {
        timestamps.sort((a, b) => b.getTime() - a.getTime());
        const formatted = formatISODate(timestamps[0]);
        if (formatted) latestLabel = formatted;
    }

    provenanceBanner.textContent = hits.length
        ? `Answer based on ${hits.length} internal source${hits.length === 1 ? '' : 's'} (${hostSummary}), as of ${latestLabel}.`
        : 'No supporting sources were retrieved. Treat this as a "no answer" result.';
    provenanceBanner.style.display = 'block';
}

async function runQuery(event) {
    event.preventDefault();
    const query = document.getElementById('question')?.value.trim();
    if (!query) return;

    statusEl.textContent = 'Running HAWKI RAG retrieval...';
    pushActivity('HAWKI RAG', `Query started: "${query.slice(0, 80)}"`);
    runBtn.disabled = true;
    resetQueryUi();

    const fastMode = document.getElementById('fast-mode')?.checked;
    const payload = {
        query,
        top_k: Number(document.getElementById('topk')?.value) || 5,
        generate: false,
        fast_mode: fastMode,
        smart_lookup: !fastMode,
    };

    const startedAt = performance.now();
    try {
        const response = await fetch(basePath + 'query', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
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
            throw new Error(!data && rawBody ? `${message}. Body excerpt: ${rawBody.slice(0, 200)}` : message);
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

        renderQueryMeta(data, elapsedMs, hitCount);
        answerBlock.style.display = 'none';
        pushRewriteActivity(data);

        const hits = Array.isArray(data.hits) ? data.hits : [];
        renderHits(hits);
        renderKgFacts(Array.isArray(data.kg) ? data.kg : []);
        renderProvenance(hits);

        statusEl.textContent = `Done · ${elapsedMs} ms`;
        pushActivity('HAWKI RAG', `Query completed · hits: ${hitCount} · ${elapsedMs} ms`);
    } catch (error) {
        statusEl.textContent = error.message;
        console.error(error);
        provenanceBanner.textContent = 'No answer available - HAWKI RAG could not retrieve grounded sources.';
        provenanceBanner.style.display = 'block';
        pushActivity('HAWKI RAG', `Query failed: ${error.message}`);
    } finally {
        runBtn.disabled = false;
    }
}

if (form) {
    form.addEventListener('submit', runQuery);
}
