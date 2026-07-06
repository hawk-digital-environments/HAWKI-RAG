<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Commands;

use App\Models\PipelineTask;
use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineDemoCommandRenderer
{
    /**
     * @param list<string> $urls
     * @param array<string, mixed> $input
     */
    public function planned(Command $command, string $taskId, string $dataset, array $urls, array $input): void
    {
        $command->line('HAWKI RAG demo pipeline');
        $command->line('Task ID: '.$taskId);
        $command->line('Dataset: '.$dataset);
        $command->line('URL limit: '.count($urls));
        $command->line('Graph metadata: '.((bool) ($input['metadata']['graph'] ?? false) ? 'true' : 'false'));
    }

    /**
     * @param list<string> $urls
     * @param list<string> $swaggerUrls
     */
    public function dryRun(Command $command, array $urls, array $swaggerUrls): void
    {
        $command->warn('Dry run only. No task, jobs, or Temporal workflows were created.');
        $this->printUrls($command, $urls);
        $this->printSwaggerUrls($command, $swaggerUrls);
        $this->printWorkerCommands($command);
    }

    /**
     * @param array<string, mixed> $status
     * @param list<string> $urls
     * @param list<string> $swaggerUrls
     */
    public function created(
        Command $command,
        PipelineTask $task,
        array $status,
        array $urls,
        string $taskStatusUrl,
        array $swaggerUrls,
    ): void {
        $jobsTotal = (int) ($status['counters']['jobs_total'] ?? count($urls));
        $queued = (int) ($status['counters']['queued'] ?? 0);
        $skipped = (int) ($status['counters']['skipped'] ?? 0);

        $command->newLine();
        $command->info('Created demo pipeline task.');
        $command->line('Task ID: '.$task->task_id);
        $command->line('Status: '.($status['status'] ?? $task->status));
        $command->line("Jobs created: {$jobsTotal}");
        $command->line("Temporal ingest workflows requested: {$queued}");
        $command->line("Skipped jobs: {$skipped}");
        $this->printUrls($command, $urls);
        $this->printTaskStatusUrl($command, $taskStatusUrl);
        $this->printSwaggerUrls($command, $swaggerUrls);
        $this->printWorkerCommands($command);
    }

    /**
     * @param list<string> $urls
     */
    private function printUrls(Command $command, array $urls): void
    {
        $command->newLine();
        $command->line('Seed URLs:');
        foreach ($urls as $url) {
            $command->line('  - '.$url);
        }
    }

    private function printTaskStatusUrl(Command $command, string $url): void
    {
        $command->newLine();
        $command->line('Task status URL: '.$url);
    }

    /**
     * @param list<string> $urls
     */
    private function printSwaggerUrls(Command $command, array $urls): void
    {
        $command->newLine();
        foreach ($urls as $index => $url) {
            $command->line(($index === 0 ? 'Swagger URL: ' : 'Mounted Swagger URL: ').$url);
        }
    }

    private function printWorkerCommands(Command $command): void
    {
        $command->newLine();
        $command->line('If the task stays queued, start Temporal and the ingestion workers:');
        $command->line('  make up-core');
        $command->line('');
        $command->line('Worker containers:');
        $command->line('  docker compose up -d hawki-rag-temporal-workflow-worker hawki-rag-temporal-scraper-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker');
    }
}
