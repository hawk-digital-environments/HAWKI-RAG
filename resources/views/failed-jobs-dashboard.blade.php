<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="{{ csrf_token() }}" name="csrf-token" />
    <title>HAWKI Failed Jobs</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @php
        $apiBasePath = env('DOCKER_PROJECT_PATH', env('VIRTUAL_PATH', parse_url((string) config('app.url'), PHP_URL_PATH) ?: '/'));
        $apiBasePath = '/' . trim((string) $apiBasePath, '/') . '/';
        $apiBasePath = $apiBasePath === '//' ? '/' : $apiBasePath;
    @endphp
    <meta name="hawki-api-base-path" content="{{ $apiBasePath }}" />
    @vite(["resources/css/failed-jobs-dashboard.css", "resources/css/dashboard-dark-theme.css", "resources/js/failed-jobs-dashboard.js"])
</head>
<body>
    <main class="failed-jobs-dashboard" data-failed-jobs-dashboard>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">Pipeline recovery</p>
                <h1>Failed Jobs</h1>
                <p class="header-copy">Recover failed scrape, convert, and ingest jobs by starting fresh Temporal workflow runs.</p>
            </div>
            <div class="header-actions">
                @include('partials.pipeline-nav', ['active' => 'failed-jobs', 'refreshId' => 'failed-jobs-refresh'])
            </div>
        </header>

        <section class="recovery-status" id="failed-jobs-status">Loading failed jobs...</section>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Recovery controls</h2>
                    <p id="failed-jobs-count">0 failed jobs</p>
                </div>
            </div>
            <div class="controls-grid">
                <label>
                    <span>Dataset</span>
                    <select id="failed-jobs-dataset-filter">
                        <option value="">All datasets</option>
                    </select>
                </label>
                <label>
                    <span>Task</span>
                    <select id="failed-jobs-task-filter">
                        <option value="">All tasks</option>
                    </select>
                </label>
                <button type="button" class="secondary-button" id="failed-jobs-clear-filters">Clear filters</button>
            </div>
            <div class="action-row">
                <button type="button" class="primary-button" id="failed-jobs-retry-selected" disabled>Retry selected job</button>
                <button type="button" class="secondary-button" id="failed-jobs-retry-task" disabled>Retry failed jobs for task</button>
                <button type="button" class="secondary-button" id="failed-jobs-retry-dataset" disabled>Retry failed jobs for dataset</button>
                <button type="button" class="danger-button" id="failed-jobs-retry-all" disabled>Retry all failed jobs</button>
            </div>
        </section>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Failed jobs</h2>
                    <p>Retries reuse existing job IDs, content hashes, Qdrant point IDs, and Neo4j namespace metadata.</p>
                </div>
            </div>
            <div class="table-wrap" id="failed-jobs-table"></div>
        </section>
    </main>
</body>
</html>
