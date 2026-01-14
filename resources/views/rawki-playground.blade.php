<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>RAWKI Retrieval Playground</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root { color-scheme: dark; }
        body {
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: radial-gradient(circle at 15% 20%, rgba(20, 184, 166, 0.08), transparent 35%),
                        radial-gradient(circle at 85% 30%, rgba(59, 130, 246, 0.1), transparent 45%),
                        #0f172a;
            color: #e2e8f0;
        }
        .container { max-width: 1040px; margin: 0 auto; padding: 2.25rem 1.75rem 4rem; }
        h1 { margin: 0; font-weight: 700; font-size: 2rem; letter-spacing: -0.01em; }
        h2 { font-size: 1.25rem; margin-top: 0; }
        p { line-height: 1.6; }
        a { color: #38bdf8; }
        .card {
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 1.1rem;
            padding: 1.4rem 1.6rem;
            box-shadow: 0 22px 55px rgba(15, 23, 42, 0.35);
        }
        .grid { display: grid; gap: 1.4rem; }
        .two { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        textarea, input, button { font-family: inherit; }
        textarea {
            width: 100%;
            min-height: 120px;
            border-radius: 1rem;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(15, 23, 42, 0.78);
            color: inherit;
            padding: 1rem;
        }
        textarea:focus { outline: none; border-color: rgba(94, 234, 212, 0.45); }
        input[type="number"] {
            width: 100%;
            border-radius: 0.8rem;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(15, 23, 42, 0.78);
            color: inherit;
            padding: 0.7rem 0.8rem;
        }
        label { font-size: 0.9rem; font-weight: 600; display: block; margin-bottom: 0.35rem; }
        button {
            border: none;
            border-radius: 0.9rem;
            padding: 0.85rem 1.9rem;
            background: linear-gradient(135deg, #14b8a6, #0284c7);
            color: #0b1120;
            font-weight: 600;
            letter-spacing: 0.01em;
            cursor: pointer;
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }
        button:disabled { opacity: 0.6; cursor: wait; }
        button:not(:disabled):hover { transform: translateY(-1px); box-shadow: 0 10px 25px rgba(13, 148, 136, 0.4); }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            background: rgba(94, 234, 212, 0.14);
            border: 1px solid rgba(94, 234, 212, 0.4);
            color: #99f6e4;
        }
        .provenance {
            display: none;
            margin: 0 0 1rem 0;
            padding: 0.85rem 1rem;
            border-radius: 0.9rem;
            background: rgba(22, 78, 99, 0.35);
            border: 1px solid rgba(94, 234, 212, 0.4);
            font-size: 0.9rem;
        }
        .hits-list { display: grid; gap: 1rem; }
        .hit {
            border-radius: 1rem;
            border: 1px solid rgba(59, 130, 246, 0.2);
            background: rgba(30, 64, 175, 0.1);
            padding: 1rem;
        }
        .hit h3 { margin: 0 0 0.45rem; font-size: 1.05rem; }
        .hit a { word-break: break-all; }
        .kg-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .kg-table th, .kg-table td { padding: 0.55rem 0.4rem; border-bottom: 1px solid rgba(71, 85, 105, 0.35); }
        details { background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(71, 85, 105, 0.4); border-radius: 0.9rem; padding: 0.8rem 1rem; }
        summary { cursor: pointer; font-weight: 600; }
        pre { background: rgba(10, 18, 32, 0.85); border-radius: 0.9rem; padding: 1rem; overflow-x: auto; font-size: 0.85rem; }
        ul { margin: 0.4rem 0 0; padding-left: 1.2rem; }
    </style>
</head>
<body>
    <div class="container">
        <header class="grid" style="gap: 1.1rem; margin-bottom: 2rem;">
            <div class="card" style="background: rgba(12, 74, 110, 0.55); border-color: rgba(56, 189, 248, 0.4);">
                <h1>Welcome to RAWKI</h1>
                <p style="margin-top: 0.4rem;">
                    RAWKI is your HAWKI-branded Retrieval-Augmented Generation stack, combining <strong>Qdrant</strong> for vector search,
                    <strong>Neo4j</strong> for graph knowledge, and the RAWKI pipeline for fast knowledge extraction.
                </p>
                <div class="grid two" style="margin-top: 1rem;">
                    <div>
                        <h2>Minimum recommended specs</h2>
                        <ul>
                            <li>6&nbsp;GB RAM (8&nbsp;GB preferred) to run <code>llama3:8b</code> via Ollama</li>
                            <li>4 CPU cores for smoother ingest + retrieval</li>
                            <li>15&nbsp;GB disk for Qdrant/Neo4j data and model caches</li>
                        </ul>
                    </div>
                    <div>
                        <h2>Quick commands</h2>
                        <ul>
                            <li><code>make up-core</code> – start RAWKI core stack (Ollama, Qdrant, app)</li>
                            <li><code>make up-rag</code> – launch RAWKI services (Neo4j, core, reranker, bridge)</li>
                            <li><code>make ingest CRAWLED_ROOT=/path</code> – ingest local crawl via FastAPI bridge</li>
                        </ul>
                    </div>
                </div>
                <p style="margin-top: 1rem; font-size: 0.9rem; color: #bae6fd;">
                    API endpoints: <code>POST /api/rawki/query</code> (this page), <code>POST /api/qdrant-search</code> (streaming),
                    <code>POST /api/qdrant-test</code> (JSON test), and <code>POST /query</code> on the RAWKI FastAPI bridge.
                </p>
            </div>
        </header>

        <section class="card" style="margin-bottom: 2rem;">
            <form id="query-form" class="grid" style="gap: 1.2rem;">
                <div>
                    <label for="question">Ask RAWKI anything</label>
                    <textarea id="question" placeholder="e.g. Wer bekommt ein Werk in der Bibliothek, wenn er nur einen Benutzerausweis vorzeigt?" required></textarea>
                </div>
                <div class="grid two">
                    <div>
                        <label for="topk">Top-K results</label>
                        <input id="topk" type="number" min="1" max="10" value="5" />
                    </div>
                    <div>
                        <label><input type="checkbox" id="generate" checked /> Generate answer</label>
                        <label><input type="checkbox" id="optimized" /> Optimized retrieval</label>
                    </div>
                </div>
                <div>
                    <button type="submit" id="run-btn">Run RAWKI retrieval</button>
                </div>
            </form>
            <p id="status" style="margin-top: 0.9rem; font-size: 0.95rem; color: #bae6fd;"></p>
        </section>

        <section class="card" id="results" style="display: none;">
            <h2>Results</h2>
            <div id="provenance-banner" class="provenance"></div>
            <div id="meta" style="display:flex; flex-wrap:wrap; gap:0.5rem 0.75rem; margin-bottom: 1rem;"></div>
            <div id="answer-block" style="display:none; margin-bottom:1.5rem;">
                <h3 style="margin-top:0;">RAWKI Answer</h3>
                <div id="answer" style="white-space: pre-wrap; line-height:1.6;"></div>
            </div>
            <div id="hits-block" style="display:none;">
                <h3>Top vector hits (Qdrant)</h3>
                <div class="hits-list" id="hits"></div>
            </div>
            <div id="kg-block" style="display:none; margin-top:1.6rem;">
                <h3>Graph knowledge (Neo4j)</h3>
                <table class="kg-table" id="kg-table">
                    <thead>
                        <tr><th>Subject</th><th>Relation</th><th>Object</th></tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <details style="margin-top: 1.6rem;">
                <summary>Raw JSON</summary>
                <pre id="raw-json"></pre>
            </details>
        </section>
    </div>

    <script>
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

        function badge(text) {
            const span = document.createElement('span');
            span.className = 'badge';
            span.textContent = text;
            return span;
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

            statusEl.textContent = 'Running RAWKI retrieval...';
            runBtn.disabled = true;
            results.style.display = 'none';
            answerBlock.style.display = 'none';
            hitsBlock.style.display = 'none';
            kgBlock.style.display = 'none';
            metaEl.innerHTML = '';
            provenanceBanner.style.display = 'none';
            provenanceBanner.textContent = '';

            const payload = {
                query,
                top_k: Number(document.getElementById('topk').value) || 5,
                generate: document.getElementById('generate').checked,
                is_optimized: document.getElementById('optimized').checked,
            };

            try {
                const response = await fetch('/api/rawki/query', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });

                const rawBody = await response.text();
                let data = null;
                if (rawBody) {
                    try {
                        data = JSON.parse(rawBody);
                    } catch (parseErr) {
                        console.error('RAWKI returned non-JSON response', parseErr, rawBody);
                    }
                }

                if (!response.ok) {
                    const message = data && typeof data === 'object'
                        ? (data.message || JSON.stringify(data))
                        : `RAWKI request failed (${response.status})`;
                    if (!data && rawBody) {
                        throw new Error(`${message}. Body excerpt: ${rawBody.slice(0, 200)}`);
                    }
                    throw new Error(message);
                }

                if (!data) {
                    results.style.display = 'block';
                    rawJson.textContent = rawBody || '';
                    throw new Error('RAWKI bridge returned an invalid JSON payload. Check RAWKI service logs.');
                }

                results.style.display = 'block';
                rawJson.textContent = JSON.stringify(data, null, 2);

                const hitCount = typeof data.count === 'number'
                    ? data.count
                    : (Array.isArray(data.hits) ? data.hits.length : 0);
                metaEl.appendChild(badge(`hits: ${hitCount}`));
                if (Array.isArray(data.kg)) metaEl.appendChild(badge(`kg facts: ${data.kg.length}`));
                if (data.summary && data.summary.qdrant && data.summary.qdrant.primary_point_count !== undefined) {
                    metaEl.appendChild(badge(`qdrant points: ${data.summary.qdrant.primary_point_count}`));
                }

                if (payload.generate && data.answer) {
                    answerBlock.style.display = 'block';
                    answerEl.textContent = data.answer;
                }

                const hits = Array.isArray(data.hits) ? data.hits : [];
                if (hits.length) {
                    hitsBlock.style.display = 'block';
                    hitsEl.innerHTML = '';
                    hits.forEach((hit) => {
                        const payload = hit.payload || {};
                        const div = document.createElement('div');
                        div.className = 'hit';
                        const title = payload.title || 'Untitled';
                        const url = payload.page_url || payload.source_url || '';
                        const parentUrl = payload.parent_url || payload.parent_page_url || '';
                        const parentNode = payload.parent_node || payload.parent_id || '';
                        const snippet = (payload.snippet || payload.content || '').slice(0, 400);
                        const score = typeof hit.score === 'number' ? hit.score.toFixed(4) : 'n/a';
                        div.innerHTML = `
                            <h3>${title}</h3>
                            <p style="margin:0 0 0.35rem;font-size:0.85rem;color:#bae6fd;">score: ${score}</p>
                            ${url ? `<p style=\"margin:0 0 0.35rem;font-size:0.9rem;\"><a href=\"${url}\" target=\"_blank\" rel=\"noopener noreferrer\">${url}</a></p>` : ''}
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
                    const host = extractHost(payload.page_url || payload.source_url || payload.parent_url || payload.parent_page_url || '');
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

                statusEl.textContent = 'Done.';
            } catch (error) {
                statusEl.textContent = error.message;
                console.error(error);
                provenanceBanner.textContent = 'No answer available – RAWKI could not retrieve grounded sources.';
                provenanceBanner.style.display = 'block';
            } finally {
                runBtn.disabled = false;
            }
        });
    </script>
</body>
</html>
