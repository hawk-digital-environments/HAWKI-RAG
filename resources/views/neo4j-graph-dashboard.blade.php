<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="{{ csrf_token() }}" name="csrf-token" />
    <title>Neo4j Graph Explorer</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    @php
        $apiBasePath = env('DOCKER_PROJECT_PATH', env('VIRTUAL_PATH', parse_url((string) config('app.url'), PHP_URL_PATH) ?: '/'));
        $apiBasePath = '/' . trim((string) $apiBasePath, '/') . '/';
        $apiBasePath = $apiBasePath === '//' ? '/' : $apiBasePath;
    @endphp
    <meta name="hawki-api-base-path" content="{{ $apiBasePath }}" />
    @vite(["resources/css/app.css", "resources/js/neo4j-graph-dashboard.js"])
</head>
<body>
    <div class="container graph-dashboard" data-neo4j-graph-dashboard>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">HAWKI RAG graph</p>
                <h1>Neo4j Graph Explorer</h1>
                <p class="header-copy">Inspect graph entities, relationships, snapshots, and semantic graph search.</p>
            </div>
            <div class="header-actions">
                @include('partials.pipeline-nav', ['active' => 'graph', 'refreshId' => 'neo4j-graph-refresh'])
            </div>
        </header>

        @include('partials.neo4j-graph-explorer')
    </div>
</body>
</html>
