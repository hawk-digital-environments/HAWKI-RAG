<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="{{ csrf_token() }}" name="csrf-token" />
    <title>HAWKI Task Manager</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @php
        $apiBasePath = env('DOCKER_PROJECT_PATH', env('VIRTUAL_PATH', parse_url((string) config('app.url'), PHP_URL_PATH) ?: '/'));
        $apiBasePath = '/' . trim((string) $apiBasePath, '/') . '/';
        $apiBasePath = $apiBasePath === '//' ? '/' : $apiBasePath;
    @endphp
    <meta name="hawki-api-base-path" content="{{ $apiBasePath }}" />
    <meta name="hawki-selected-task-id" content="{{ $taskId ?? '' }}" />
    @vite(["resources/css/task-manager.css", "resources/js/task-manager.js"])
</head>
<body>
    <main class="task-manager" data-task-manager>
        <header class="task-manager-header">
            <div>
                <p class="eyebrow">Pipeline operations</p>
                <h1>Task Manager</h1>
                <p class="header-copy">Tasks, worker jobs, documents, failures, and events.</p>
            </div>
            <div class="header-actions">
                @include('partials.pipeline-nav', ['active' => 'tasks', 'refreshId' => 'task-manager-refresh'])
            </div>
        </header>

        <section class="summary-strip" aria-label="Pipeline summary">
            <div class="summary-item">
                <span>Total</span>
                <strong id="task-manager-total">0</strong>
            </div>
            <div class="summary-item">
                <span>Queued</span>
                <strong id="task-manager-queued">0</strong>
            </div>
            <div class="summary-item">
                <span>Processing</span>
                <strong id="task-manager-processing">0</strong>
            </div>
            <div class="summary-item is-failed">
                <span>Failed</span>
                <strong id="task-manager-failed">0</strong>
            </div>
            <div class="summary-item">
                <span>Completed</span>
                <strong id="task-manager-completed">0</strong>
            </div>
        </section>

        <div class="manager-grid">
            <aside class="task-rail" aria-label="Pipeline task list">
                <div class="rail-head">
                    <div>
                        <h2>Tasks</h2>
                        <p id="task-manager-task-count">Loading tasks...</p>
                    </div>
                </div>
                <div class="filter-row">
                    <input id="task-manager-search" type="search" placeholder="Search task, dataset, source" autocomplete="off" />
                    <select id="task-manager-status-filter" aria-label="Task status">
                        <option value="">All statuses</option>
                        <option value="queued">Queued</option>
                        <option value="running">Processing</option>
                        <option value="failed">Failed</option>
                        <option value="completed">Completed</option>
                        <option value="skipped">Skipped</option>
                    </select>
                </div>
                <div class="task-list" id="task-manager-task-list" aria-live="polite"></div>
            </aside>

            <section class="manager-detail" aria-live="polite">
                <div class="status-banner" id="task-manager-status">Loading pipeline tasks...</div>

                <section class="detail-panel task-overview-panel">
                    <div class="section-head">
                        <div>
                            <h2 id="task-manager-title">No task selected</h2>
                            <p id="task-manager-updated">Waiting for task data.</p>
                        </div>
                        <span class="status-pill" id="task-manager-task-status">idle</span>
                    </div>
                    <dl class="info-grid" id="task-manager-info"></dl>
                    <div class="stage-grid" id="task-manager-stages"></div>
                </section>

                <section class="detail-panel">
                    <div class="tabbar" role="tablist" aria-label="Task details">
                        <button type="button" class="tab-button is-active" data-task-tab="overview">Overview</button>
                        <button type="button" class="tab-button" data-task-tab="jobs">Jobs</button>
                        <button type="button" class="tab-button" data-task-tab="documents">Documents</button>
                        <button type="button" class="tab-button" data-task-tab="failed">Failed</button>
                        <button type="button" class="tab-button" data-task-tab="events">Events</button>
                    </div>

                    <div class="tab-panel is-active" data-task-panel="overview">
                        <div class="counter-grid" id="task-manager-counters"></div>
                    </div>

                    <div class="tab-panel" data-task-panel="jobs">
                        <div class="section-head compact">
                            <div>
                                <h3>Jobs</h3>
                                <p id="task-manager-jobs-count">0 jobs</p>
                            </div>
                            <select id="task-manager-job-type-filter" aria-label="Job type">
                                <option value="">All job types</option>
                                <option value="scrape">Scrape</option>
                                <option value="convert">Convert</option>
                                <option value="ingest">Ingest</option>
                                <option value="graph">Graph</option>
                            </select>
                        </div>
                        <div class="table-wrap" id="task-manager-jobs"></div>
                    </div>

                    <div class="tab-panel" data-task-panel="documents">
                        <div class="section-head compact">
                            <div>
                                <h3>Documents</h3>
                                <p id="task-manager-documents-count">0 documents</p>
                            </div>
                            <a class="secondary-link" id="task-manager-documents-link" href="{{ url('/documents') }}">Open documents</a>
                        </div>
                        <div class="table-wrap" id="task-manager-documents"></div>
                    </div>

                    <div class="tab-panel" data-task-panel="failed">
                        <div class="section-head compact">
                            <div>
                                <h3>Failed jobs</h3>
                                <p id="task-manager-failed-count">0 failed jobs</p>
                            </div>
                            <div class="action-row">
                                <button type="button" class="secondary-button" id="task-manager-retry-selected" disabled>Retry selected</button>
                                <button type="button" class="danger-button" id="task-manager-retry-task" disabled>Retry task</button>
                            </div>
                        </div>
                        <div class="table-wrap" id="task-manager-failed-jobs"></div>
                    </div>

                    <div class="tab-panel" data-task-panel="events">
                        <div class="section-head compact">
                            <div>
                                <h3>Events</h3>
                                <p id="task-manager-events-count">0 events</p>
                            </div>
                            <div class="event-filters">
                                <select id="task-manager-event-type-filter" aria-label="Event type">
                                    <option value="">All event types</option>
                                </select>
                                <select id="task-manager-event-job-filter" aria-label="Event job">
                                    <option value="">All jobs</option>
                                </select>
                            </div>
                        </div>
                        <div class="event-list" id="task-manager-events"></div>
                    </div>
                </section>
            </section>
        </div>
    </main>
</body>
</html>
