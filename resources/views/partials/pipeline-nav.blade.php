@php
    $active = $active ?? '';
    $refreshId = $refreshId ?? 'pipeline-refresh';
    $navItems = [
        ['key' => 'health', 'label' => 'Health', 'href' => '/pipeline-health'],
        ['key' => 'datasets', 'label' => 'Data Browser', 'href' => '/datasets'],
        ['key' => 'controller', 'label' => 'Controller', 'href' => '/pipeline-controller'],
        ['key' => 'graph', 'label' => 'Neo4j Graph', 'href' => '/neo4j-graph-explorer'],
        ['key' => 'playground', 'label' => 'Playground', 'href' => '/hawki-rag-playground'],
    ];
@endphp

<nav class="pipeline-nav" aria-label="HAWKI RAG pages">
    <button type="button" class="secondary-button pipeline-nav-refresh" id="{{ $refreshId }}">Refresh</button>
    @foreach ($navItems as $item)
        <a class="secondary-link{{ $active === $item['key'] ? ' is-active' : '' }}" href="{{ url($item['href']) }}" @if ($active === $item['key']) aria-current="page" @endif>{{ $item['label'] }}</a>
    @endforeach
</nav>
