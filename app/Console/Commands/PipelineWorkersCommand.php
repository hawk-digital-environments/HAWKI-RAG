<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class PipelineWorkersCommand extends Command
{
    protected $signature = 'pipeline:workers';

    protected $description = 'Print Temporal RAG worker startup commands and task queues.';

    public function handle(ConfigRepository $config): int
    {
        $queues = (array) $config->get('temporal.task_queues', []);

        $this->line('HAWKI RAG Temporal ingestion workers');
        $this->line('Laravel starts/cancels/schedules workflows. Temporal coordinates durable phase transitions.');
        $this->newLine();

        $this->line('Start the production stack (including all Temporal workers):');
        $this->line('  make up-core');
        $this->line('Start the source-mounted development stack:');
        $this->line('  make up-core-local');
        $this->newLine();

        $this->table(['Worker', 'Container', 'Task queue', 'Registers'], [
            [
                'workflow',
                'hawki-rag-temporal-workflow-worker',
                (string) ($queues['workflow'] ?? 'rag-workflow-task-queue'),
                'IngestSourceWorkflow',
            ],
            [
                'scraper adapter',
                'hawki-rag-temporal-scraper-worker',
                (string) ($queues['scraper'] ?? 'rag-scraper-task-queue'),
                'scrape_source',
            ],
            [
                'converter adapter',
                'hawki-rag-temporal-converter-worker',
                (string) ($queues['converter'] ?? 'rag-converter-task-queue'),
                'inspect_and_convert_files',
            ],
            [
                'indexer',
                'hawki-rag-indexer-worker',
                (string) ($queues['indexer'] ?? $queues['ingestion'] ?? 'rag-ingestion-task-queue'),
                'ingest_markdown_files, mark_source_ready',
            ],
        ]);

        return self::SUCCESS;
    }
}
