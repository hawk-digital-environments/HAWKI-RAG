<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="{{ csrf_token() }}" name="csrf-token" />
    <title>HAWKI Datasets</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @php
        $apiBasePath = env('DOCKER_PROJECT_PATH', env('VIRTUAL_PATH', parse_url((string) config('app.url'), PHP_URL_PATH) ?: '/'));
        $apiBasePath = '/' . trim((string) $apiBasePath, '/') . '/';
        $apiBasePath = $apiBasePath === '//' ? '/' : $apiBasePath;
    @endphp
    <meta name="hawki-api-base-path" content="{{ $apiBasePath }}" />
    @vite(["resources/css/datasets-dashboard.css", "resources/js/datasets-dashboard.js"])
</head>
<body>
    <main class="datasets-dashboard" data-datasets-dashboard>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">HAWKI RAG datasets</p>
                <h1>Datasets</h1>
                <p class="header-copy">Dataset-scoped tasks, documents, ingestion, and graph storage.</p>
            </div>
            <div class="header-actions">
                <a class="secondary-link" href="{{ url('/pipeline-dashboard') }}">Pipeline Dashboard</a>
                <a class="secondary-link" href="{{ url('/pipeline-health') }}">Health</a>
                <a class="secondary-link" href="{{ url('/documents') }}">Documents</a>
                <a class="secondary-link" href="{{ url('/failed-jobs') }}">Failed Jobs</a>
                <a class="secondary-link" href="{{ url('/hawki-rag-playground') }}">Playground</a>
                <button type="button" class="secondary-button" id="datasets-refresh">Refresh</button>
            </div>
        </header>

        <div class="dashboard-grid">
            <aside class="dataset-sidebar" aria-label="Datasets">
                <div class="section-head">
                    <div>
                        <h2>Dataset list</h2>
                        <p id="datasets-count">Loading datasets...</p>
                    </div>
                </div>
                <div class="dataset-list" id="datasets-list" aria-live="polite"></div>
            </aside>

            <section class="dataset-detail" aria-live="polite">
                <div class="detail-status" id="datasets-status">Loading datasets...</div>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Dataset info</h2>
                            <p id="datasets-updated">No dataset loaded.</p>
                        </div>
                        <span class="status-pill" id="datasets-state">idle</span>
                    </div>
                    <dl class="dataset-info-grid" id="datasets-info"></dl>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Storage and graph</h2>
                            <p>One Qdrant collection and one Neo4j namespace per dataset.</p>
                        </div>
                    </div>
                    <div class="metric-grid" id="datasets-metrics"></div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Tasks</h2>
                            <p id="datasets-task-count">0 tasks</p>
                        </div>
                    </div>
                    <div class="table-wrap" id="datasets-tasks"></div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Documents</h2>
                            <p id="datasets-document-count">0 documents</p>
                        </div>
                    </div>
                    <div class="table-wrap" id="datasets-documents"></div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Ingestion history</h2>
                            <p id="datasets-ingestion-count">0 ingestion jobs</p>
                        </div>
                    </div>
                    <div class="table-wrap" id="datasets-ingestion-history"></div>
                </section>
            </section>
        </div>
    </main>
</body>
</html>
