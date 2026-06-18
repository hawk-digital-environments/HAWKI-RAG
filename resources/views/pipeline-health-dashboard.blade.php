<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="{{ csrf_token() }}" name="csrf-token" />
    <title>HAWKI Pipeline Health</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @php
        $apiBasePath = env('DOCKER_PROJECT_PATH', env('VIRTUAL_PATH', parse_url((string) config('app.url'), PHP_URL_PATH) ?: '/'));
        $apiBasePath = '/' . trim((string) $apiBasePath, '/') . '/';
        $apiBasePath = $apiBasePath === '//' ? '/' : $apiBasePath;
    @endphp
    <meta name="hawki-api-base-path" content="{{ $apiBasePath }}" />
    @vite(["resources/css/pipeline-health-dashboard.css", "resources/css/dashboard-dark-theme.css", "resources/js/pipeline-health-dashboard.js"])
</head>
<body>
    <main class="pipeline-health-dashboard" data-pipeline-health-dashboard>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">HAWKI RAG health</p>
                <h1>Health Repair</h1>
                <p class="header-copy">Check Temporal, PostgreSQL, adapters, shared storage, Qdrant, and Neo4j from Laravel.</p>
            </div>
            <div class="header-actions">
                @include('partials.pipeline-nav', ['active' => 'health', 'refreshId' => 'pipeline-health-refresh'])
            </div>
        </header>

        <section class="health-status" id="pipeline-health-status">Loading ingestion health...</section>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Service status</h2>
                    <p id="pipeline-health-updated">No health status loaded.</p>
                </div>
                <span class="status-pill" id="pipeline-health-state">loading</span>
            </div>
            <div class="metric-grid" id="pipeline-health-metrics"></div>
            <div class="warning-list" id="pipeline-health-warnings"></div>
        </section>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Temporal and data services</h2>
                    <p id="pipeline-health-queue-count">0 checks</p>
                </div>
            </div>
            <div class="table-wrap" id="pipeline-health-queues"></div>
        </section>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Operator notes</h2>
                    <p id="pipeline-health-retry-count">0 notes</p>
                </div>
            </div>
            <div class="table-wrap" id="pipeline-health-retry-queues"></div>
        </section>
    </main>
</body>
</html>
