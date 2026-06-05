<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="{{ csrf_token() }}" name="csrf-token" />
    <title>HAWKI Documents</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @php
        $apiBasePath = env('DOCKER_PROJECT_PATH', env('VIRTUAL_PATH', parse_url((string) config('app.url'), PHP_URL_PATH) ?: '/'));
        $apiBasePath = '/' . trim((string) $apiBasePath, '/') . '/';
        $apiBasePath = $apiBasePath === '//' ? '/' : $apiBasePath;
    @endphp
    <meta name="hawki-api-base-path" content="{{ $apiBasePath }}" />
    @vite(["resources/css/documents-dashboard.css", "resources/css/dashboard-dark-theme.css", "resources/js/documents-dashboard.js"])
</head>
<body>
    <main class="documents-dashboard" data-documents-dashboard>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">HAWKI RAG documents</p>
                <h1>Document Browser</h1>
                <p class="header-copy">Inspect ingested Markdown, indexing state, and the pipeline jobs that created it.</p>
            </div>
            <div class="header-actions">
                @include('partials.pipeline-nav', ['active' => 'documents', 'refreshId' => 'documents-refresh'])
            </div>
        </header>

        <div class="dashboard-grid">
            <aside class="document-sidebar" aria-label="Documents">
                <form class="document-filters" id="documents-filters">
                    <label>
                        <span>Dataset ID</span>
                        <input id="documents-dataset-filter" type="text" placeholder="all datasets" autocomplete="off" />
                    </label>
                    <label>
                        <span>Search</span>
                        <input id="documents-search-filter" type="search" placeholder="URL, path, title, hash" autocomplete="off" />
                    </label>
                    <button type="submit" class="primary-button">Apply filters</button>
                </form>

                <div class="section-head">
                    <div>
                        <h2>Documents</h2>
                        <p id="documents-count">Loading documents...</p>
                    </div>
                </div>
                <div class="document-list" id="documents-list" aria-live="polite"></div>
            </aside>

            <section class="document-detail" aria-live="polite">
                <div class="detail-status" id="documents-status">Loading documents...</div>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Document info</h2>
                            <p id="documents-updated">No document loaded.</p>
                        </div>
                        <span class="status-pill" id="documents-state">idle</span>
                    </div>
                    <dl class="document-info-grid" id="documents-info"></dl>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Index evidence</h2>
                            <p>Counts come from ingestion metadata when the bridge returns them.</p>
                        </div>
                    </div>
                    <div class="metric-grid" id="documents-metrics"></div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Extracted Markdown preview</h2>
                            <p id="documents-preview-note">Preview reads the recorded local path.</p>
                        </div>
                    </div>
                    <pre class="markdown-preview" id="documents-markdown-preview"></pre>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Related pipeline jobs</h2>
                            <p id="documents-jobs-count">0 jobs</p>
                        </div>
                    </div>
                    <div class="table-wrap" id="documents-related-jobs"></div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Metadata</h2>
                            <p>Raw document metadata stored with the ingested record.</p>
                        </div>
                    </div>
                    <pre class="metadata-preview" id="documents-metadata"></pre>
                </section>
            </section>
        </div>
    </main>
</body>
</html>
