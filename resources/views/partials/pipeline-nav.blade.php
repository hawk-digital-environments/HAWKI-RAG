@php
    $active = $active ?? '';
    $refreshId = $refreshId ?? 'pipeline-refresh';
    $navItems = [
        ['key' => 'dashboard', 'label' => 'Pipeline Dashboard', 'href' => '/pipeline-dashboard'],
        ['key' => 'health', 'label' => 'Health', 'href' => '/pipeline-health'],
        ['key' => 'datasets', 'label' => 'Datasets', 'href' => '/datasets'],
        ['key' => 'documents', 'label' => 'Documents', 'href' => '/documents'],
        ['key' => 'controller', 'label' => 'Controller', 'href' => '/pipeline-controller'],
        ['key' => 'graph', 'label' => 'Neo4j Graph', 'href' => '/neo4j-graph-explorer'],
        ['key' => 'failed-jobs', 'label' => 'Failed Jobs', 'href' => '/failed-jobs'],
        ['key' => 'playground', 'label' => 'Playground', 'href' => '/hawki-rag-playground'],
    ];
    $dashboardItem = $navItems[0];
    $menuItems = array_slice($navItems, 1);
@endphp

<nav class="pipeline-nav" aria-label="HAWKI RAG pages">
    <a class="secondary-link pipeline-nav-dashboard{{ $active === $dashboardItem['key'] ? ' is-active' : '' }}" href="{{ url($dashboardItem['href']) }}" @if ($active === $dashboardItem['key']) aria-current="page" @endif>{{ $dashboardItem['label'] }}</a>
    <details class="pipeline-nav-menu{{ $active !== $dashboardItem['key'] ? ' is-active' : '' }}">
        <summary class="secondary-button pipeline-nav-menu-trigger">Pages</summary>
        <div class="pipeline-nav-menu-list">
            @foreach ($menuItems as $item)
                <a class="secondary-link{{ $active === $item['key'] ? ' is-active' : '' }}" href="{{ url($item['href']) }}" @if ($active === $item['key']) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </div>
    </details>
    <button type="button" class="secondary-button pipeline-nav-refresh" id="{{ $refreshId }}">Refresh</button>
</nav>
