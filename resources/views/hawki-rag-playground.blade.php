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
                            <input id="ingest-chunk-chars" type="number" min="200" value="600" style="width:100%; border-radius:0.8rem; border:1px solid rgba(148,163,184,0.22); background:rgba(15,23,42,0.78); color:inherit; padding:0.7rem 0.8rem;" />
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

                    <div class="subsection">
                        <h3 style="margin-top:0;">RAG Anything Monitor</h3>
                        <p style="margin: 0 0 0.6rem; font-size: 0.9rem; color: #bae6fd;">
                            Live status from the RAG Anything health endpoint.
                        </p>
                        <div id="rag-status" class="badge">Checking…</div>
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
                </div>
            </section>

            <section class="card panel">
                <h2>Logs</h2>
                <div class="panel-body">
                    <div class="subsection">
                        <h3 style="margin-top:0;">Ingest Monitor</h3>
                        <p style="margin: 0 0 0.6rem; font-size: 0.9rem; color: #bae6fd;">
                            Live ingest progress (Qdrant/Neo4j) from <code>ingest_status.json</code>.
                        </p>
                        <div id="ingest-status" class="badge">No ingest activity yet.</div>
                        <div id="ingest-progress" style="margin-top: 0.8rem; display: grid; gap: 0.5rem;"></div>
                        <div style="margin-top: 0.8rem;">
                            <button type="button" id="ingest-clear-btn" style="background: linear-gradient(135deg, #f97316, #ef4444);">Clear ingest logs</button>
                        </div>
                    </div>

                    <div class="subsection">
                        <h3 style="margin-top:0;">Live Ingestions</h3>
                        <p style="margin: 0 0 0.6rem; font-size: 0.9rem; color: #bae6fd;">
                            Active ingest processes (PID + folder).
                        </p>
                        <div id="ingest-live-status" class="badge">No running ingest process.</div>
                        <div id="ingest-live-list" style="margin-top: 0.8rem; display: grid; gap: 0.5rem;"></div>
                    </div>

                    <div class="subsection">
                        <h3 style="margin-top:0;">Live Activity</h3>
                        <p style="margin: 0 0 0.6rem; font-size: 0.9rem; color: #bae6fd;">
                            Rolling feed of the latest updates from ingest and RAG stats.
                        </p>
                        <div id="activity-feed" class="activity-feed"></div>
                        <div style="margin-top: 0.8rem;">
                            <button type="button" id="activity-clear-btn" style="background: linear-gradient(135deg, #f97316, #ef4444);">Clear activity</button>
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

                    <div class="subsection" id="results" style="display: none;">
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
