<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="{{ csrf_token() }}" name="csrf-token" />
    <title>HAWKI Pipeline Controller</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    @php
        $apiBasePath = env('DOCKER_PROJECT_PATH', env('VIRTUAL_PATH', parse_url((string) config('app.url'), PHP_URL_PATH) ?: '/'));
        $apiBasePath = '/' . trim((string) $apiBasePath, '/') . '/';
        $apiBasePath = $apiBasePath === '//' ? '/' : $apiBasePath;
    @endphp
    <meta name="hawki-api-base-path" content="{{ $apiBasePath }}" />
    @vite(["resources/css/app.css", "resources/css/hawki-rag-theme.css", "resources/js/pipeline-controller.js"])
</head>
<body>
    <div class="container pipeline-controller-dashboard" data-pipeline-controller-dashboard>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">HAWKI RAG controller</p>
                <h1>Pipeline Controller</h1>
                <p class="header-copy">Start crawler tasks, upload documents, and follow scrape to convert to ingest chaining.</p>
            </div>
            <div class="header-actions">
                @include('partials.pipeline-nav', ['active' => 'controller', 'refreshId' => 'pipeline-controller-refresh'])
            </div>
        </header>

        @include('partials.hawki-rag-page-signal', [
            'variant' => 'pipeline',
            'kicker' => 'Search. Retrieve. Explore.',
            'title' => 'Ingestion Control',
            'copy' => 'Turn files and crawler runs into searchable Qdrant vectors and Neo4j graph signals.',
            'signals' => [
                ['state' => 'Input', 'label' => 'Upload or crawl', 'copy' => 'Start controlled ingestion from files or scraper tasks.', 'tone' => 'active'],
                ['state' => 'Flow', 'label' => 'Pipeline stages', 'copy' => 'Follow scrape, convert, ingest, retry, cancel, and cleanup.', 'tone' => 'active'],
                ['state' => 'Graph', 'label' => 'Retrieval assets', 'copy' => 'Prepare data for vector search and graph exploration.', 'tone' => 'next'],
            ],
        ])

        @include('partials.pipeline-controller-workspace')
    </div>
</body>
</html>
