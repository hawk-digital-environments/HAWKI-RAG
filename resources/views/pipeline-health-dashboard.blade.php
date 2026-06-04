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
    @vite(["resources/css/pipeline-health-dashboard.css", "resources/js/pipeline-health-dashboard.js"])
</head>
<body>
    <main class="pipeline-health-dashboard" data-pipeline-health-dashboard>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">Pipeline health</p>
                <h1>RabbitMQ Queue Monitor</h1>
                <p class="header-copy">Pipeline queue depth, consumers, retry queues, and failed events from Laravel.</p>
            </div>
            <div class="header-actions">
                <a class="secondary-link" href="{{ url('/pipeline-dashboard') }}">Pipeline Dashboard</a>
                <a class="secondary-link" href="{{ url('/datasets') }}">Datasets</a>
                <a class="secondary-link" href="{{ url('/documents') }}">Documents</a>
                <a class="secondary-link" href="{{ url('/failed-jobs') }}">Failed Jobs</a>
                <button type="button" class="secondary-button" id="pipeline-health-refresh">Refresh</button>
            </div>
        </header>

        <section class="health-status" id="pipeline-health-status">Loading queue status...</section>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Queue status card</h2>
                    <p id="pipeline-health-updated">No queue status loaded.</p>
                </div>
                <span class="status-pill" id="pipeline-health-state">loading</span>
            </div>
            <div class="metric-grid" id="pipeline-health-metrics"></div>
            <div class="warning-list" id="pipeline-health-warnings"></div>
        </section>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Pipeline queues</h2>
                    <p id="pipeline-health-queue-count">0 queues</p>
                </div>
            </div>
            <div class="table-wrap" id="pipeline-health-queues"></div>
        </section>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Retry and failed queues</h2>
                    <p id="pipeline-health-retry-count">0 retry messages</p>
                </div>
            </div>
            <div class="table-wrap" id="pipeline-health-retry-queues"></div>
        </section>
    </main>
</body>
</html>
