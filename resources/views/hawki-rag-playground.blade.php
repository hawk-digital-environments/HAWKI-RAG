<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="{{csrf_token()}}" name="csrf-token"/>
    <title>HAWKI RAG Retrieval Playground</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    @php
        $apiBasePath = env('DOCKER_PROJECT_PATH', env('VIRTUAL_PATH', parse_url((string) config('app.url'), PHP_URL_PATH) ?: '/'));
        $apiBasePath = '/' . trim((string) $apiBasePath, '/') . '/';
        $apiBasePath = $apiBasePath === '//' ? '/' : $apiBasePath;
    @endphp
    <meta name="hawki-api-base-path" content="{{ $apiBasePath }}" />
    @vite("resources/css/app.css")
    @vite("resources/js/app.js")

</head>
<body>
    <div class="container playground-dashboard" data-hawki-rag-playground>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">HAWKI RAG playground</p>
                <h1>HAWKI RAG Retrieval Playground</h1>
                <p class="header-copy">Query and inspect live RAG state.</p>
            </div>
            <div class="header-actions">
                @include('partials.pipeline-nav', ['active' => 'playground', 'refreshId' => 'playground-refresh'])
            </div>
        </header>

        <div class="layout">
            <section class="card panel">
                <h2>Logs</h2>
                <div class="panel-body">
                    <div class="subsection rag-monitor-panel">
                        <h3 style="margin-top:0;">RAG-Anything Monitor</h3>
                        <p style="margin: 0 0 0.6rem; font-size: 0.9rem; color: #bae6fd;">
                            Bridge health, graph extraction limits, and latest ingest graph summary.
                        </p>
                        <div id="rag-monitor-status" class="badge">Loading RAG-Anything status...</div>
                        <div id="rag-details" style="margin-top: 0.8rem; display: grid; gap: 0.5rem;"></div>
                    </div>

                    <div class="subsection">
                        <h3 style="margin-top:0;">Vector + Graph Stats</h3>
                        <p style="margin: 0 0 0.6rem; font-size: 0.9rem; color: #bae6fd;">
                            Qdrant collections and Neo4j triplets (live counts).
                        </p>
                        <div id="rag-stats" style="display:grid; gap:0.6rem;"></div>
                        <div style="margin-top: 0.8rem;">
                            <button type="button" id="neo4j-clear-btn" style="background: linear-gradient(135deg, #f43f5e, #be123c);">
                                Clear Neo4j graph (danger)
                            </button>
                            <span id="neo4j-clear-note" class="ingest-action-note"></span>
                        </div>
                    </div>

                    <!-- MCP Monitor removed -->
                </div>
            </section>

            <section class="card panel">
                <h2>Query</h2>
                <div class="panel-body">
                    <div class="subsection">
                        <form id="query-form" class="grid" style="gap: 1.2rem;">
                            <div>
                                <label for="question">Ask HAWKI RAG anything</label>
                                <textarea id="question" placeholder="e.g. Wer bekommt ein Werk in der Bibliothek, wenn er nur einen Benutzerausweis vorzeigt?" required></textarea>
                            </div>
                            <div class="grid two">
                                <div>
                                    <label for="topk">Top-K results</label>
                                    <input id="topk" type="number" min="1" value="5" />
                                </div>
                                <div>
                                    <label><input type="checkbox" id="fast-mode" /> Fast mode (skip rewrite + graph)</label>
                                </div>
                            </div>
                            <div>
                                <button type="submit" id="run-btn">Run HAWKI RAG retrieval</button>
                            </div>
                        </form>
                        <p id="status" style="margin-top: 0.9rem; font-size: 0.95rem; color: #bae6fd;"></p>
                    </div>

                    <div class="subsection query-results" id="results" style="display: none;">
                        <h3 style="margin-top:0;">Results</h3>
                        <div id="provenance-banner" class="provenance"></div>
                        <div id="meta" style="display:flex; flex-wrap:wrap; gap:0.5rem 0.75rem; margin-bottom: 1rem;"></div>
                        <div id="answer-block" style="display:none; margin-bottom:1.5rem;">
                            <h4 style="margin-top:0;">HAWKI RAG Answer</h4>
                            <div id="answer" style="white-space: pre-wrap; line-height:1.6;"></div>
                        </div>
                        <div id="hits-block" style="display:none;">
                            <h4>Top vector hits (Qdrant)</h4>
                            <div class="hits-list" id="hits"></div>
                        </div>
                        <div id="kg-block" style="display:none; margin-top:1.6rem;">
                            <h4>Graph knowledge (Neo4j)</h4>
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
                    </div>
                </div>
            </section>
        </div>

    </div>

</body>
</html>
