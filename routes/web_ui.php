<?php

use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Route;

$hawkiRagExperienceConfig = static function (string $section = 'operator'): array {
    return [
        'activeSection' => $section,
        'operatorRoutes' => [
            [
                'key' => 'pipeline',
                'label' => 'Pipeline',
                'title' => 'Pipeline Controller',
                'href' => '/admin/pipeline',
                'summary' => 'Run ingestion, uploads, conversion, retries, and live stage logs.',
                'service' => 'Pipeline',
                'state' => 'live',
            ],
            [
                'key' => 'heaps',
                'label' => 'Heaps',
                'title' => 'Heap Browser',
                'href' => '/admin/heaps',
                'summary' => 'Browse heaps, documents, sources, and recovery context.',
                'service' => 'Heap browser',
                'state' => 'ready',
            ],
            [
                'key' => 'graph',
                'label' => 'Graph',
                'title' => 'Graph Explorer',
                'href' => '/admin/graph',
                'summary' => 'Inspect entities, relations, neighborhoods, and graph paths.',
                'service' => 'Graph retrieval',
                'state' => 'live',
            ],
            [
                'key' => 'search',
                'label' => 'Search',
                'title' => 'Search Console',
                'href' => '/admin/search',
                'summary' => 'Ask questions, compare vector and graph answers, and inspect evidence.',
                'service' => 'Search console',
                'state' => 'live',
            ],
        ],
    ];
};

$pipelineControllerConfig = static function (): array {
    $normalizeExtensions = static fn (array $extensions): array => collect($extensions)
        ->map(fn ($extension) => ltrim(strtolower(trim((string) $extension)), '.'))
        ->filter()
        ->unique()
        ->values()
        ->all();
    $customConverter = app(SettingsService::class)->customConverterUploadDefaults();

    return [
        'nativeExtensions' => $normalizeExtensions(config('file_converter.raganything_supported_extensions', [])),
        'customExtensions' => $normalizeExtensions(config('file_converter.supported_extensions', [])),
        'customConverter' => [
            'enabled' => (bool) ($customConverter['enabled'] ?? false),
            'configured' => (bool) ($customConverter['configured'] ?? false),
            'supported_extensions' => $normalizeExtensions($customConverter['supported_extensions'] ?? []),
        ],
    ];
};

Route::middleware('throttle:hawki-ui')->group(function () use ($hawkiRagExperienceConfig, $pipelineControllerConfig): void {
    require __DIR__.'/web_ui/entrypoints.php';
    require __DIR__.'/web_ui/search_console.php';
    require __DIR__.'/web_ui/graph.php';
    require __DIR__.'/web_ui/pipeline.php';
    require __DIR__.'/web_ui/heaps.php';
});
