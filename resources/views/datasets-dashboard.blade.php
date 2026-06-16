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
    @vite(["resources/css/datasets-dashboard.css", "resources/css/dashboard-dark-theme.css", "resources/js/datasets-dashboard.js"])
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
                        <form class="document-search" id="datasets-document-search-form">
                            <input id="datasets-document-search" type="search" placeholder="Search documents" autocomplete="off" />
                            <button type="submit" class="secondary-button">Search</button>
                        </form>
                    </div>
                    <div class="table-wrap" id="datasets-documents"></div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Document info</h2>
                            <p id="datasets-document-updated">No document loaded.</p>
                        </div>
                        <span class="status-pill" id="datasets-document-state">idle</span>
                    </div>
                    <dl class="document-info-grid" id="datasets-document-info"></dl>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Index evidence</h2>
                            <p>Counts come from ingestion metadata when the bridge returns them.</p>
                        </div>
                    </div>
                    <div class="metric-grid document-metric-grid" id="datasets-document-metrics"></div>
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

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Related pipeline jobs</h2>
                            <p id="datasets-document-jobs-count">0 jobs</p>
                        </div>
                    </div>
                    <div class="table-wrap" id="datasets-document-related-jobs"></div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Metadata</h2>
                            <p>Raw document metadata stored with the ingested record.</p>
                        </div>
                    </div>
                    <pre class="metadata-preview" id="datasets-document-metadata"></pre>
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
