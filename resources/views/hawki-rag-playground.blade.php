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
    <div class="container">
        <div class="layout">
            <section class="card panel">
                <h2>Ingestion</h2>
                <div class="panel-body">
                    <div class="subsection">
                        @php
                            $embedModels = config('config.embedding_models', ['bge-m3']);
                            $embedDefault = config('config.embedding_default', $embedModels[0] ?? null);
                            $graphModels = config('config.graph_models', ['llama3.2:3b']);
                            $graphDefault = config('config.graph_default', $graphModels[0] ?? null);
                            $graphChunkCharsDefault = max(200, (int) env('GRAPH_DOC_MAX_CHARS', 800));
                        @endphp
                        <h3 style="margin-top:0;">Ingest Data</h3>
                        <p style="margin: 0 0 0.8rem; font-size: 0.9rem; color: #bae6fd;">
                            Select a folder under the shared crawl volume and start ingestion.
                        </p>
                        <div class="grid two">
                            <div>
                                <label for="ingest-folder">Crawl folder</label>
                                <select id="ingest-folder" style="width:100%; border-radius:0.8rem; border:1px solid rgba(148,163,184,0.22); background:rgba(15,23,42,0.78); color:inherit; padding:0.7rem 0.8rem;">
                                    <option value="">Loading…</option>
                                </select>
                            </div>
                            <div>
                            <label>Graph extraction</label>
                            <div class="muted">Use "Start graph ingest" for Neo4j triplets.</div>
                            </div>
                        </div>
                        <div style="margin-top: 1rem;">
                            <label for="ingest-collection">Qdrant collection name</label>
                            <input id="ingest-collection" type="text" placeholder="Defaults to folder name" style="width:100%; border-radius:0.8rem; border:1px solid rgba(148,163,184,0.22); background:rgba(15,23,42,0.78); color:inherit; padding:0.7rem 0.8rem;" />
                        </div>
                        <div style="margin-top: 1rem;">
                            <label>Graph collection</label>
                            <div class="muted">Fixed internal graph collection name: <code>graphCol</code> (Neo4j default DB is used).</div>
                        </div>
                        <div style="margin-top: 1rem;">
                            <label for="ingest-embedding-model">Embedding model</label>
                            <select id="ingest-embedding-model" style="width:100%; border-radius:0.8rem; border:1px solid rgba(148,163,184,0.22); background:rgba(15,23,42,0.78); color:inherit; padding:0.7rem 0.8rem;">
                                @foreach ($embedModels as $model)
                                    <option value="{{ $model }}" {{ $model === $embedDefault ? 'selected' : '' }}>{{ $model }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div style="margin-top: 1rem;">
                            <label for="ingest-graph-model">Graph LLM model</label>
                            <select id="ingest-graph-model" style="width:100%; border-radius:0.8rem; border:1px solid rgba(148,163,184,0.22); background:rgba(15,23,42,0.78); color:inherit; padding:0.7rem 0.8rem;">
                                @foreach ($graphModels as $model)
                                    <option value="{{ $model }}" {{ $model === $graphDefault ? 'selected' : '' }}>{{ $model }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div style="margin-top: 1rem;">
                            <label for="ingest-batch-size">Batch size (docs per request)</label>
                            <input id="ingest-batch-size" type="number" min="1" value="16" style="width:100%; border-radius:0.8rem; border:1px solid rgba(148,163,184,0.22); background:rgba(15,23,42,0.78); color:inherit; padding:0.7rem 0.8rem;" />
                        </div>
                        <div style="margin-top: 1rem;">
                            <label for="ingest-chunk-chars">Chunk size (chars) for graph extraction</label>
                            <input id="ingest-chunk-chars" type="number" min="200" value="{{ $graphChunkCharsDefault }}" style="width:100%; border-radius:0.8rem; border:1px solid rgba(148,163,184,0.22); background:rgba(15,23,42,0.78); color:inherit; padding:0.7rem 0.8rem;" />
                        </div>
                        <div style="margin-top: 1rem;">
                            <label for="ingest-resume-mode">Resume mode</label>
                            <select id="ingest-resume-mode" style="width:100%; border-radius:0.8rem; border:1px solid rgba(148,163,184,0.22); background:rgba(15,23,42,0.78); color:inherit; padding:0.7rem 0.8rem;">
                                <option value="resume" selected>Resume (skip ingested)</option>
                                <option value="start">Start fresh</option>
                            </select>
                        </div>
                        <div style="margin-top: 1rem;">
                            <div class="ingest-actions">
                                <button type="button" id="ingest-btn">Start Qdrant Ingestion</button>
                                <button type="button" id="ingest-graph-only-btn" style="background: linear-gradient(135deg, #22c55e, #16a34a);">Start Neo4j Ingestion</button>
                                <button type="button" id="ingest-stop-btn" style="background: linear-gradient(135deg, #f97316, #ef4444);">Stop Current Ingest</button>
                                <button type="button" id="ingest-delete-btn" style="background: linear-gradient(135deg, #f43f5e, #be123c);">Delete scraped folder</button>
                            </div>
                            <span id="ingest-action" class="ingest-action-note"></span>
                        </div>
                    </div>

                </div>
            </section>

            <section class="card panel">
                <h2>Logs</h2>
                <div class="panel-body">
                    <div class="subsection">
                        <h3 style="margin-top:0;">Live Ingestions</h3>
                        <p style="margin: 0 0 0.6rem; font-size: 0.9rem; color: #bae6fd;">
                            Active ingest processes (PID + folder).
                        </p>
                        <div id="ingest-live-status" class="badge">No running ingest process.</div>
                        <div id="ingest-live-list" style="margin-top: 0.8rem; display: grid; gap: 0.5rem;"></div>
                    </div>

                    <div class="subsection">
                        <h3 style="margin-top:0;">RAG Anything Monitor</h3>
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

        <section class="pipeline-operations-section">
            <div class="pipeline-hero">
                <div class="pipeline-heading">
                    <span class="pipeline-kicker">Scraper Pipeline</span>
                    <h2>Pipeline Control</h2>
                </div>
                <div class="pipeline-current-wrap">
                    <span id="pipeline-current" class="badge">No pipeline selected.</span>
                    <span id="pipeline-job-id" class="pipeline-job-id">Job ID: none</span>
                </div>
            </div>
            <div class="pipeline-workspace">
                <aside class="pipeline-task-panel">
                    <div class="pipeline-panel-head">
                        <h3>Scraper Tasks</h3>
                        <button type="button" id="pipeline-task-refresh-btn" class="pipeline-secondary-btn">Refresh</button>
                    </div>
                    <label for="pipeline-task-select">Available task</label>
                    <select id="pipeline-task-select">
                        <option value="">Loading scraper tasks...</option>
                    </select>
                    <div class="pipeline-task-summary">
                        <span><strong id="pipeline-task-count">0</strong> tasks</span>
                        <span id="pipeline-task-source">Source: none</span>
                    </div>
                    <div id="pipeline-task-detail" class="pipeline-task-detail" hidden></div>
                    <div id="pipeline-task-note" class="pipeline-task-note"></div>
                    <button type="button" id="pipeline-task-start-btn">Start Pipeline Task</button>

                    <div class="pipeline-run-list-block">
                        <div class="pipeline-panel-head">
                            <h3>Pipeline Tasks</h3>
                            <button type="button" id="pipeline-run-refresh-btn" class="pipeline-secondary-btn">Refresh</button>
                        </div>
                        <div id="pipeline-run-list" class="pipeline-run-list">
                            <button type="button" disabled>Loading pipeline tasks...</button>
                        </div>
                    </div>
                </aside>

                <main class="pipeline-stage-panel">
                    <div class="pipeline-stage-header">
                        <div>
                            <h3>Stage State</h3>
                            <p id="pipeline-dataset-path">Dataset path: none</p>
                        </div>
                        <div id="pipeline-updated-at" class="pipeline-updated-at"></div>
                    </div>
                    <div id="pipeline-task-run" class="pipeline-task-run" hidden></div>
                    <div id="pipeline-stages" class="pipeline-stages pipeline-stages-expanded"></div>
                </main>
            </div>
        </section>

        <section class="graph-visualization-section">
            <div class="graph-visualization-header">
                <div>
                    <h2>Neo4j Graph Explorer</h2>
                    <p>Search, expand, group, and save interactive graph scenes.</p>
                </div>
                <div id="neo4j-graph-meta" class="badge">Loading graph...</div>
            </div>

            <div class="graph-explorer-shell">
                <aside class="graph-toolbar" aria-label="Graph controls">
                    <div class="graph-control-group">
                        <label for="graph-search-input">Entity search</label>
                        <input id="graph-search-input" type="search" placeholder="Search entities..." autocomplete="off" />
                        <div id="graph-search-results" class="graph-results"></div>
                    </div>

                    <div class="graph-control-group">
                        <label for="graph-semantic-input">Semantic search</label>
                        <input id="graph-semantic-input" type="search" placeholder="Ask for a concept..." autocomplete="off" />
                        <div id="graph-semantic-results" class="graph-results"></div>
                    </div>

                    <div class="graph-control-grid">
                        <div>
                            <label for="graph-layout-select">Layout</label>
                            <select id="graph-layout-select">
                                <option value="elk" selected>ELK layered</option>
                                <option value="cose-bilkent">CoSE Bilkent</option>
                            </select>
                        </div>
                        <div>
                            <label for="graph-grouping-select">Grouping</label>
                            <select id="graph-grouping-select">
                                <option value="none" selected>None</option>
                                <option value="type">Entity type</option>
                                <option value="source">Source document</option>
                                <option value="community">Community</option>
                            </select>
                        </div>
                        <div>
                            <label for="graph-depth-select">Depth</label>
                            <select id="graph-depth-select">
                                <option value="1" selected>1 hop</option>
                                <option value="2">2 hops</option>
                                <option value="3">3 hops</option>
                            </select>
                        </div>
                    </div>

                    <div class="graph-actions">
                        <button type="button" id="graph-overview-btn">Overview</button>
                        <button type="button" id="graph-relayout-btn">Layout</button>
                        <button type="button" id="graph-clear-view-btn">Clear view</button>
                    </div>

                    <div class="graph-control-group">
                        <label for="graph-snapshot-load">Snapshots</label>
                        <div class="graph-snapshot-row">
                            <select id="graph-snapshot-load">
                                <option value="">Load snapshot...</option>
                            </select>
                            <button type="button" id="graph-snapshot-save-btn">Save</button>
                            <button type="button" id="graph-snapshot-delete-btn">Delete</button>
                        </div>
                    </div>

                    <div id="graph-status" class="graph-status" role="status"></div>
                </aside>

                <main class="graph-stage">
                    <div id="neo4j-graph-empty" class="graph-empty">Search for an entity or load the limited overview.</div>
                    <div id="neo4j-graph-canvas" class="graph-canvas" role="img" aria-label="Neo4j graph visualization"></div>
                </main>

                <aside class="graph-detail" aria-label="Selected entity details">
                    <h3>Entity Details</h3>
                    <div id="graph-detail-panel">
                        <p class="muted">Select a node to inspect entity metadata and expand neighbors.</p>
                    </div>
                </aside>
            </div>
        </section>
    </div>

</body>
</html>
