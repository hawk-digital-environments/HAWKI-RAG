<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;

class PipelineArchitectureCommand extends Command
{
    protected $signature = 'pipeline:architecture';

    protected $description = 'Print the Temporal RAG ingestion workflow, task queues, and persistence responsibilities.';

    public function handle(ConfigRepository $config): int
    {
        $queues = (array) $config->get('temporal.task_queues', []);

        $this->line('Temporal RAG ingestion architecture');
        $this->newLine();
        $this->line('Laravel creates source/task/job metadata, starts workflows, manages schedules, and displays status.');
        $this->line('Temporal owns durable workflow history, retries, cancellation, timers, and daily/weekly/monthly schedules.');
        $this->line('Python workers execute deterministic workflow code and side-effecting activities.');
        $this->newLine();

        $this->line('Workflow phases');
        $this->table(
            ['Order', 'Workflow/activity', 'Task queue', 'Worker/container'],
            [
                ['1', 'IngestSourceWorkflow', (string) ($queues['workflow'] ?? 'rag-workflow-task-queue'), 'hawki-rag-temporal-workflow-worker'],
                ['2', 'inspect_and_convert_files', (string) ($queues['converter'] ?? 'rag-converter-task-queue'), 'hawki-rag-temporal-converter-worker'],
                ['3', 'ingest_markdown_files', (string) ($queues['ingestion'] ?? 'rag-ingestion-task-queue'), 'hawki-rag-temporal-ingestion-worker'],
                ['4', 'mark_source_ready', (string) ($queues['ingestion'] ?? 'rag-ingestion-task-queue'), 'hawki-rag-temporal-ingestion-worker'],
            ],
        );

        $this->newLine();
        $this->line('Persistence map');
        $this->table(
            ['Store', 'Owner', 'Purpose'],
            [
                ['PostgreSQL Laravel tables', 'Laravel/RAG activities', 'sources, documents, workflow IDs, schedule IDs, freshness metadata, status'],
                ['PostgreSQL Temporal tables', 'Temporal server only', 'workflow history, state, retries, timers, schedules'],
                ['Qdrant', 'ingest_markdown_files activity via RAG bridge', 'chunk embeddings and vector payload metadata'],
                ['Neo4j', 'ingest_markdown_files activity via RAG bridge', 'entities, relationships, document graph, URL/source links'],
                ['Shared/object storage', 'converter and ingestion workers', 'uploaded source files, Markdown files, ingest manifest'],
            ],
        );

        $this->newLine();
        $this->line('Scheduling');
        $this->table(
            ['Cadence', 'Cron'],
            [
                ['daily', (string) $config->get('temporal.refresh_cadences.daily', '0 2 * * *')],
                ['weekly', (string) $config->get('temporal.refresh_cadences.weekly', '0 2 * * 0')],
                ['monthly', (string) $config->get('temporal.refresh_cadences.monthly', '0 2 1 * *')],
            ],
        );

        $this->newLine();
        $this->line('Temporal UI: http://localhost:'.(string) $config->get('temporal.ui_port', 8081));

        return self::SUCCESS;
    }
}
