<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="{{ csrf_token() }}" name="csrf-token" />
    <title>HAWKI Pipeline Profiles</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @php
        $apiBasePath = env('DOCKER_PROJECT_PATH', env('VIRTUAL_PATH', parse_url((string) config('app.url'), PHP_URL_PATH) ?: '/'));
        $apiBasePath = '/' . trim((string) $apiBasePath, '/') . '/';
        $apiBasePath = $apiBasePath === '//' ? '/' : $apiBasePath;
    @endphp
    <script>
        window.hawkiPlayground = {
            apiBasePath: @json($apiBasePath),
        };
    </script>
    @vite(["resources/css/pipeline-profiles-dashboard.css", "resources/js/pipeline-profiles-dashboard.js"])
</head>
<body>
    <main class="profiles-dashboard" data-pipeline-profiles-dashboard>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">Pipeline configuration</p>
                <h1>Pipeline Profiles</h1>
                <p class="header-copy">Create reusable scrape, conversion, and graph settings for operators.</p>
            </div>
            <div class="header-actions">
                <a class="secondary-link" href="{{ url('/pipeline-dashboard') }}">Pipeline Dashboard</a>
                <a class="secondary-link" href="{{ url('/datasets') }}">Datasets</a>
                <a class="secondary-link" href="{{ url('/documents') }}">Documents</a>
                <button type="button" class="secondary-button" id="profiles-refresh">Refresh</button>
                <button type="button" class="primary-button" id="profiles-new">New profile</button>
            </div>
        </header>

        <div class="dashboard-grid">
            <aside class="profile-sidebar" aria-label="Pipeline profiles">
                <div class="section-head">
                    <div>
                        <h2>Profiles</h2>
                        <p id="profiles-count">Loading profiles...</p>
                    </div>
                </div>
                <div class="profile-list" id="profiles-list" aria-live="polite"></div>
            </aside>

            <section class="profile-detail">
                <div class="detail-status" id="profiles-status">Select a profile or create one.</div>

                <section class="panel">
                    <div class="section-head">
                        <div>
                            <h2 id="profiles-form-title">Profile editor</h2>
                            <p>Settings are copied into pipeline task metadata when the task starts.</p>
                        </div>
                        <button type="button" class="primary-button" id="profiles-start" disabled>Start task from profile</button>
                    </div>

                    <form class="profile-form" id="profiles-form">
                        <div class="form-grid">
                            <label>
                                <span>Profile ID</span>
                                <input id="profile-id" name="profile_id" type="text" autocomplete="off" />
                            </label>
                            <label>
                                <span>Name</span>
                                <input id="profile-name" name="name" type="text" required />
                            </label>
                            <label class="wide">
                                <span>Description</span>
                                <textarea id="profile-description" name="description" rows="2"></textarea>
                            </label>
                            <label class="wide">
                                <span>Start URLs</span>
                                <textarea id="profile-start-urls" name="start_urls" rows="5" placeholder="https://www.hawk.de/de"></textarea>
                            </label>
                            <label class="wide">
                                <span>Sitemap URL</span>
                                <input id="profile-sitemap-url" name="sitemap_url" type="url" />
                            </label>
                            <label>
                                <span>Max pages</span>
                                <input id="profile-max-pages" name="max_pages" type="number" min="1" value="1" />
                            </label>
                            <label>
                                <span>Allowed file types</span>
                                <input id="profile-file-types" name="allowed_file_types" type="text" placeholder="pdf, doc, docx" />
                            </label>
                            <label>
                                <span>Qdrant collection</span>
                                <input id="profile-qdrant" name="qdrant_collection" type="text" />
                            </label>
                            <label>
                                <span>Neo4j namespace</span>
                                <input id="profile-neo4j" name="neo4j_namespace" type="text" />
                            </label>
                            <label class="toggle-row">
                                <input id="profile-graph" name="graph_enabled" type="checkbox" />
                                <span>Graph enabled</span>
                            </label>
                            <label class="wide">
                                <span>Metadata JSON</span>
                                <textarea id="profile-metadata" name="metadata" rows="5">{}</textarea>
                            </label>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="primary-button" id="profiles-save">Save profile</button>
                            <button type="button" class="secondary-button" id="profiles-reset">Reset form</button>
                        </div>
                    </form>
                </section>
            </section>
        </div>
    </main>
</body>
</html>
