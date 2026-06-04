<?php

namespace App\Console\Commands;

use App\Services\Pipeline\PipelineProfileService;
use App\Services\Pipeline\PipelineTaskService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DemoPipelineTask extends Command
{
    protected $signature = 'pipeline:demo
        {--dataset=demo : Dataset identifier for the demo run}
        {--profile= : Pipeline profile ID for the demo run}
        {--limit=5 : Number of demo URLs to queue}
        {--graph=true : Include graph ingestion metadata}
        {--dry-run=false : Print the planned demo without creating jobs}
        {--url=* : Optional demo URL override; can be repeated}';

    protected $description = 'Start a small demo scrape/convert/ingest pipeline task for the dashboard.';

    private const DEFAULT_URLS = [
        'https://www.hawk.de/de',
        'https://www.hawk.de/de/studium',
        'https://www.hawk.de/de/hochschule',
        'https://www.hawk.de/de/forschung',
        'https://www.hawk.de/de/weiterbildung',
    ];

    public function handle(PipelineTaskService $tasks, PipelineProfileService $profiles): int
    {
        $dataset = $this->stringOption('dataset') ?: 'demo';
        $profileId = $this->stringOption('profile');
        $limit = $this->integerOption('limit', 5);
        $graph = $this->booleanOption('graph', true);
        $dryRun = $this->booleanOption('dry-run', false);
        $profile = null;

        if ($limit === null || $limit < 1) {
            $this->error('The --limit option must be an integer greater than zero.');

            return self::FAILURE;
        }

        if ($graph === null) {
            $this->error('The --graph option must be true or false.');

            return self::FAILURE;
        }

        if ($dryRun === null) {
            $this->error('The --dry-run option must be true or false.');

            return self::FAILURE;
        }

        if ($profileId !== null) {
            $profile = $profiles->show($profileId);
            if (!$profile) {
                $this->error("Pipeline profile {$profileId} was not found.");

                return self::FAILURE;
            }
        }

        $urls = $this->demoUrls($profile, $limit);
        if ($urls === [] && !$profile) {
            $this->error('No demo URLs are configured.');

            return self::FAILURE;
        }

        $taskId = 'demo_' . now()->format('Ymd_His') . '_' . Str::lower(Str::random(6));
        $input = [
            'task_id' => $taskId,
            'dataset_id' => $dataset,
            'pipeline_profile_id' => $profileId,
            'profile_id' => 'hawki-demo',
            'urls' => $urls,
            'metadata' => [
                'source' => 'pipeline-demo-command',
                'catalog_task_label' => 'Demo pipeline task',
                'label' => 'hawki-demo',
                'max_pages' => 1,
                'max_concurrency' => 1,
                'max_rpm' => 30,
                'skip_images' => true,
                'discovery_mode' => false,
            ],
        ];
        if (!$profile || $this->optionWasProvided('graph')) {
            $input['metadata']['graph'] = $graph;
            $input['metadata']['rag_ingest_graph'] = $graph;
        }

        $this->line('HAWKI RAG demo pipeline');
        $this->line('Task ID: ' . $taskId);
        $this->line('Dataset: ' . $dataset);
        if ($profileId !== null) {
            $this->line('Pipeline profile: ' . $profileId);
        }
        $graphLabel = (bool) ($input['metadata']['graph'] ?? ($profile['graphEnabled'] ?? false));
        $this->line('URL limit: ' . count($urls));
        $this->line('Graph metadata: ' . ($graphLabel ? 'true' : 'false'));

        if ($dryRun) {
            $this->warn('Dry run only. No task, jobs, or RabbitMQ events were created.');
            $this->printUrls($urls);
            $this->printDashboardUrls();
            $this->printWorkerCommands();

            return self::SUCCESS;
        }

        $task = $tasks->start($input);
        $status = $tasks->show($task->task_id);
        $jobsTotal = (int) ($status['counters']['jobs_total'] ?? count($urls));
        $queued = (int) ($status['counters']['queued'] ?? 0);
        $skipped = (int) ($status['counters']['skipped'] ?? 0);

        $this->newLine();
        $this->info('Created demo pipeline task.');
        $this->line('Task ID: ' . $task->task_id);
        $this->line('Status: ' . ($status['status'] ?? $task->status));
        $this->line("Jobs created: {$jobsTotal}");
        $this->line("Queued scrape jobs: {$queued}");
        $this->line("Skipped scrape jobs: {$skipped}");
        $this->line('RabbitMQ events requested: ' . $queued . ' scrape.requested event(s).');
        $this->printUrls($urls);
        $this->printDashboardUrls();
        $this->printWorkerCommands();

        return self::SUCCESS;
    }

    private function demoUrls(?array $profile, int $limit): array
    {
        $explicit = $this->option('url') ?: [];
        $urls = $explicit ?: ($profile['startUrls'] ?? $this->configuredDemoUrls());

        if ($urls === [] && $profile === null) {
            $urls = self::DEFAULT_URLS;
        }

        return array_slice(array_values(array_unique(array_filter(array_map(
            fn (mixed $url): ?string => is_scalar($url) && trim((string) $url) !== '' ? trim((string) $url) : null,
            $urls,
        )))), 0, $limit);
    }

    private function configuredDemoUrls(): array
    {
        $configured = env('PIPELINE_DEMO_URLS');
        if (!is_string($configured) || trim($configured) === '') {
            return [];
        }

        return preg_split('/[\r\n,]+/', $configured) ?: [];
    }

    private function printUrls(array $urls): void
    {
        $this->newLine();
        $this->line('Seed URLs:');
        foreach ($urls as $url) {
            $this->line('  - ' . $url);
        }
    }

    private function printDashboardUrls(): void
    {
        $this->newLine();
        $this->line('Dashboard URL: ' . url('/pipeline-dashboard'));

        $mountedUrl = $this->mountedDashboardUrl();
        if ($mountedUrl !== null && $mountedUrl !== url('/pipeline-dashboard')) {
            $this->line('Mounted dashboard URL: ' . $mountedUrl);
        }
    }

    private function printWorkerCommands(): void
    {
        $this->newLine();
        $this->line('If the task stays queued, start the pipeline workers:');
        $this->line('  docker compose --profile pipeline-events up -d hawki-rag-scrape-monitor-worker hawki-rag-scraper-event-worker hawki-rag-converter-event-worker hawki-rag-ingestion-event-worker');
        $this->line('');
        $this->line('Direct Artisan worker commands:');
        $this->line('  php artisan queue:work database --queue=default --sleep=2 --tries=3 --timeout=180');
        $this->line('  php artisan pipeline:scraper-event-worker');
        $this->line('  php artisan pipeline:converter-event-worker');
        $this->line('  php artisan pipeline:ingestion-event-worker');
    }

    private function mountedDashboardUrl(): ?string
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl === '') {
            return null;
        }

        $parts = parse_url($appUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($path === '') {
            $mount = env('DOCKER_PROJECT_PATH', env('VIRTUAL_PATH', ''));
            $path = trim((string) $mount, '/');
        }

        if ($path === '') {
            return null;
        }

        return $origin . '/' . trim($path . '/pipeline-dashboard', '/');
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function integerOption(string $name, int $default): ?int
    {
        $value = $this->stringOption($name);
        if ($value === null) {
            return $default;
        }

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }

    private function booleanOption(string $name, bool $default): ?bool
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return is_bool($parsed) ? $parsed : null;
    }

    private function optionWasProvided(string $name): bool
    {
        foreach ($_SERVER['argv'] ?? [] as $argument) {
            if ($argument === "--{$name}" || str_starts_with((string) $argument, "--{$name}=")) {
                return true;
            }
        }

        return false;
    }
}
