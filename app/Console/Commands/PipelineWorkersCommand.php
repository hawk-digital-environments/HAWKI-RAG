<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;

class PipelineWorkersCommand extends Command
{
    protected $signature = 'pipeline:workers';

    protected $description = 'Print Temporal RAG ingestion worker startup commands and task queues.';

    public function handle(ConfigRepository $config): int
    {
        $queues = (array) $config->get('temporal.task_queues', []);

        $this->line('HAWKI RAG Temporal ingestion workers');
        $this->line('Laravel starts/cancels/schedules workflows. Temporal coordinates durable phase transitions.');
        $this->newLine();

        $this->line('Start the Temporal stack:');
        $this->line('  docker compose up -d postgres temporal hawki_rag_app hawki_rag_bridge qdrant hawki_rag_neo4j');
        $this->line('Optional local/dev diagnostics:');
        $this->line('  docker compose --profile devtools up -d temporal-ui');
        $this->newLine();

        $this->line('Start all Temporal workers:');
        $this->line('  docker compose up -d hawki-rag-temporal-workflow-worker hawki-rag-temporal-scraper-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker');
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
                'ingestion adapter',
                'hawki-rag-temporal-ingestion-worker',
                (string) ($queues['ingestion'] ?? 'rag-ingestion-task-queue'),
                'ingest_markdown_files, mark_source_ready',
            ],
        ]);

        return self::SUCCESS;
    }
}
