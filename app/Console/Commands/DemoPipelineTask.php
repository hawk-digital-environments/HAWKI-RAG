<?php

namespace App\Console\Commands;

use App\Services\Pipeline\Commands\PipelineDemoCommandSupport;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Console\Command;

class DemoPipelineTask extends Command
{
    protected $signature = 'pipeline:demo
        {--dataset=demo : Dataset identifier for the demo run}
        {--limit=5 : Number of demo URLs to queue}
        {--graph=true : Include graph ingestion metadata}
        {--dry-run=false : Print the planned demo without creating jobs}
        {--url=* : Optional demo URL override; can be repeated}
        {--force=false : Allow this development command to run in production}';

    protected $description = 'Start a small development/demo scrape/convert/ingest pipeline task.';

    public function handle(PipelineTaskService $tasks, PipelineDemoCommandSupport $support): int
    {
        $dataset = $this->stringOption('dataset') ?: 'demo';
        $limit = $this->integerOption('limit', 5);
        $graph = $this->booleanOption('graph', true);
        $dryRun = $this->booleanOption('dry-run', false);
        $force = $this->booleanOption('force', false);

        if ($support->productionLocked($force === true)) {
            $this->error('pipeline:demo is disabled in production. Start production tasks through the pipeline task API.');

            return self::FAILURE;
        }

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

        if ($force === null) {
            $this->error('The --force option must be true or false.');

            return self::FAILURE;
        }

        $urls = $support->demoUrls($this->explicitUrls(), $limit);
        if ($urls === []) {
            $this->error('No demo URLs are configured.');

            return self::FAILURE;
        }

        $taskId = $support->taskId();
        $input = [
            'task_id' => $taskId,
            'dataset_id' => $dataset,
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
        $input['metadata']['graph'] = $graph;
        $input['metadata']['rag_ingest_graph'] = $graph;

        $this->line('HAWKI RAG demo pipeline');
        $this->line('Task ID: '.$taskId);
        $this->line('Dataset: '.$dataset);
        $graphLabel = (bool) ($input['metadata']['graph'] ?? false);
        $this->line('URL limit: '.count($urls));
        $this->line('Graph metadata: '.($graphLabel ? 'true' : 'false'));

        if ($dryRun) {
            $this->warn('Dry run only. No task, jobs, or RabbitMQ events were created.');
            $this->printUrls($urls);
            $this->printDashboardUrls($support->dashboardUrls());
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
        $this->line('Task ID: '.$task->task_id);
        $this->line('Status: '.($status['status'] ?? $task->status));
        $this->line("Jobs created: {$jobsTotal}");
        $this->line("Queued scrape jobs: {$queued}");
        $this->line("Skipped scrape jobs: {$skipped}");
        $this->line('RabbitMQ events requested: '.$queued.' scrape.requested event(s).');
        $this->printUrls($urls);
        $this->printDashboardUrls($support->dashboardUrls());
        $this->printWorkerCommands();

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function explicitUrls(): array
    {
        $urls = $this->option('url') ?: [];

        return array_values(array_filter(array_map('strval', (array) $urls)));
    }

    private function printUrls(array $urls): void
    {
        $this->newLine();
        $this->line('Seed URLs:');
        foreach ($urls as $url) {
            $this->line('  - '.$url);
        }
    }

    /**
     * @param list<string> $urls
     */
    private function printDashboardUrls(array $urls): void
    {
        $this->newLine();
        foreach ($urls as $index => $url) {
            $this->line(($index === 0 ? 'Dashboard URL: ' : 'Mounted dashboard URL: ').$url);
        }
    }

    private function printWorkerCommands(): void
    {
        $this->newLine();
        $this->line('If the task stays queued, start the pipeline workers:');
        $this->line('  docker compose --profile pipeline-events up -d hawki-rag-scraper-event-worker hawki-rag-scrape-monitor-event-worker hawki-rag-converter-event-worker hawki-rag-ingestion-event-worker');
        $this->line('');
        $this->line('Direct Artisan worker commands:');
        $this->line('  php artisan pipeline:scraper-event-worker');
        $this->line('  php artisan pipeline:scrape-monitor-event-worker');
        $this->line('  php artisan pipeline:converter-event-worker');
        $this->line('  php artisan pipeline:ingestion-event-worker');
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
}
