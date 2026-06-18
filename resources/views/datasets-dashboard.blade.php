<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="{{ csrf_token() }}" name="csrf-token" />
    <title>HAWKI Data Browser</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @php
        $apiBasePath = env('DOCKER_PROJECT_PATH', env('VIRTUAL_PATH', parse_url((string) config('app.url'), PHP_URL_PATH) ?: '/'));
        $apiBasePath = '/' . trim((string) $apiBasePath, '/') . '/';
        $apiBasePath = $apiBasePath === '//' ? '/' : $apiBasePath;
    @endphp
    <meta name="hawki-api-base-path" content="{{ $apiBasePath }}" />
    @vite(["resources/css/datasets-dashboard.css", "resources/css/dashboard-dark-theme.css", "resources/css/hawki-rag-theme.css", "resources/js/datasets-dashboard.js"])
</head>
<body>
    <main class="datasets-dashboard" data-datasets-dashboard>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">HAWKI RAG data</p>
                <h1>Data Browser</h1>
                <p class="header-copy">Dataset-scoped tasks, documents, ingestion, preview, and graph storage.</p>
            </div>
            <div class="header-actions">
                @include('partials.pipeline-nav', ['active' => 'datasets', 'refreshId' => 'datasets-refresh'])
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

                <section class="panel overview-panel">
                    <div class="section-head">
                        <div>
                            <h2>Selected data pool</h2>
                            <p id="datasets-updated">No dataset loaded.</p>
                        </div>
                        <span class="status-pill" id="datasets-state">idle</span>
                    </div>
                    <div class="overview-grid">
                        <div class="overview-block">
                            <h3>Dataset</h3>
                            <dl class="dataset-info-grid compact-info-grid" id="datasets-info"></dl>
                        </div>
                        <div class="overview-block">
                            <h3>Retrieval evidence</h3>
                            <div class="metric-grid compact-metric-grid" id="datasets-metrics"></div>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Documents</h2>
                            <p id="datasets-document-count">0 documents</p>
                        </div>
                        <form class="document-search" id="datasets-document-search-form">
                            <input id="datasets-document-search" type="search" placeholder="Search documents" autocomplete="off" />
                            <button type="submit" class="secondary-button">Search</button>
                        </form>
                    </div>
                    <div class="table-wrap" id="datasets-documents"></div>
                </section>

                <section class="panel document-context-panel">
                    <div class="section-head">
                        <div>
                            <h2>Selected document</h2>
                            <p id="datasets-document-updated">No document loaded.</p>
                        </div>
                        <span class="status-pill" id="datasets-document-state">idle</span>
                    </div>
                    <div class="document-context-grid">
                        <dl class="document-info-grid compact-info-grid" id="datasets-document-info"></dl>
                        <div class="metric-grid document-metric-grid compact-metric-grid" id="datasets-document-metrics"></div>
                    </div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Extracted Markdown preview</h2>
                            <p id="datasets-document-preview-note">Preview reads the recorded local path.</p>
                        </div>
                    </div>
                    <pre class="markdown-preview" id="datasets-document-markdown-preview"></pre>
                </section>

                <details class="technical-panel">
                    <summary>
                        <span>Document pipeline details</span>
                        <small id="datasets-document-jobs-count">0 jobs</small>
                    </summary>
                    <div class="table-wrap" id="datasets-document-related-jobs"></div>
                </details>

                <details class="technical-panel">
                    <summary>
                        <span>Dataset pipeline details</span>
                        <small><span id="datasets-task-count">0 tasks</span> · <span id="datasets-ingestion-count">0 ingestion jobs</span></small>
                    </summary>
                    <div class="technical-grid">
                        <section>
                            <h3>Tasks</h3>
                            <div class="table-wrap" id="datasets-tasks"></div>
                        </section>
                        <section>
                            <h3>Ingestion history</h3>
                            <div class="table-wrap" id="datasets-ingestion-history"></div>
                        </section>
                    </div>
                </details>

                <details class="technical-panel">
                    <summary>
                        <span>Raw document metadata</span>
                        <small>developer detail</small>
                    </summary>
                    <pre class="metadata-preview" id="datasets-document-metadata"></pre>
                </details>
            </section>
        </div>
    </main>
</body>
</html>
