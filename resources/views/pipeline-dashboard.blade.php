<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="{{ csrf_token() }}" name="csrf-token" />
    <title>HAWKI Pipeline Dashboard</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @php
        $apiBasePath = env('DOCKER_PROJECT_PATH', env('VIRTUAL_PATH', parse_url((string) config('app.url'), PHP_URL_PATH) ?: '/'));
        $apiBasePath = '/' . trim((string) $apiBasePath, '/') . '/';
        $apiBasePath = $apiBasePath === '//' ? '/' : $apiBasePath;
    @endphp
    <meta name="hawki-api-base-path" content="{{ $apiBasePath }}" />
    @vite(["resources/css/pipeline-dashboard.css", "resources/js/pipeline-dashboard.js"])
</head>
<body>
    <main class="pipeline-dashboard" data-pipeline-dashboard>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">Laravel-owned pipeline</p>
                <h1>HAWKI Pipeline Dashboard</h1>
                <p class="header-copy">Scrape, convert, and ingest state from Laravel database records.</p>
            </div>
            <div class="header-actions">
                <a class="secondary-link" href="{{ url('/pipeline-health') }}">Health</a>
                <a class="secondary-link" href="{{ url('/datasets') }}">Datasets</a>
                <a class="secondary-link" href="{{ url('/documents') }}">Documents</a>
                <a class="secondary-link" href="{{ url('/failed-jobs') }}">Failed Jobs</a>
                <a class="secondary-link" href="{{ url('/hawki-rag-playground') }}">Playground</a>
                <button type="button" class="secondary-button" id="pipeline-dashboard-refresh">Refresh</button>
            </div>
        </header>

        <div class="dashboard-grid">
            <aside class="task-sidebar" aria-label="Pipeline tasks">
                <div class="section-head">
                    <div>
                        <h2>Tasks</h2>
                        <p id="pipeline-dashboard-task-count">Loading tasks...</p>
                    </div>
                </div>
                <div class="task-list" id="pipeline-dashboard-task-list" aria-live="polite"></div>
            </aside>

            <section class="task-detail" aria-live="polite">
                <div class="detail-status" id="pipeline-dashboard-status">Select a task to inspect its current pipeline state.</div>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Task info</h2>
                            <p id="pipeline-dashboard-updated">No task loaded.</p>
                        </div>
                        <span class="status-pill" id="pipeline-dashboard-task-status">idle</span>
                    </div>
                    <dl class="task-info-grid" id="pipeline-dashboard-task-info"></dl>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Counters</h2>
                            <p>Calculated from pipeline_jobs.</p>
                        </div>
                    </div>
                    <div class="counter-grid" id="pipeline-dashboard-counters"></div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Scrape jobs</h2>
                            <p id="pipeline-dashboard-scrape-count">0 jobs</p>
                        </div>
                    </div>
                    <div class="job-table-wrap" id="pipeline-dashboard-scrape-jobs"></div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Convert jobs</h2>
                            <p id="pipeline-dashboard-convert-count">0 jobs</p>
                        </div>
                    </div>
                    <div class="job-table-wrap" id="pipeline-dashboard-convert-jobs"></div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Ingest jobs</h2>
                            <p id="pipeline-dashboard-ingest-count">0 jobs</p>
                        </div>
                    </div>
                    <div class="job-table-wrap" id="pipeline-dashboard-ingest-jobs"></div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Failed jobs</h2>
                            <p id="pipeline-dashboard-failed-count">0 jobs</p>
                        </div>
                        <button type="button" class="danger-button" id="pipeline-dashboard-retry" disabled>Retry failed jobs</button>
                    </div>
                    <div class="job-table-wrap" id="pipeline-dashboard-failed-jobs"></div>
                </section>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2>Task timeline</h2>
                            <p id="pipeline-dashboard-events-count">0 events</p>
                        </div>
                    </div>
                    <div class="timeline-filters">
                        <label>
                            <span>Event type</span>
                            <select id="pipeline-dashboard-event-type-filter">
                                <option value="">All event types</option>
                            </select>
                        </label>
                        <label>
                            <span>Job</span>
                            <select id="pipeline-dashboard-job-filter">
                                <option value="">All jobs</option>
                            </select>
                        </label>
                        <span class="timeline-refresh-note">Auto refreshes every 5 seconds.</span>
                    </div>
                    <div class="event-list" id="pipeline-dashboard-events"></div>
                </section>
            </section>
        </div>
    </main>
</body>
</html>
