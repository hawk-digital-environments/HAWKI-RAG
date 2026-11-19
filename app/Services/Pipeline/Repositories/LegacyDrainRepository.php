<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

#[Singleton]
readonly class LegacyDrainRepository
{
    public const TASK_TERMINAL = ['completed', 'failed'];

    public const JOB_TERMINAL = ['completed', 'failed', 'skipped'];

    public function __construct(private DatabaseManager $database) {}

    /** @return array<string, int|null> Missing schema is unknown, never zero. */
    public function counts(): array
    {
        return [
            'nonterminal_pipeline_tasks' => $this->count('pipeline_tasks', 'status', self::TASK_TERMINAL),
            'nonterminal_pipeline_jobs' => $this->count('pipeline_jobs', 'status', self::JOB_TERMINAL),
            'nonterminal_stage_states' => $this->count('pipeline_stage_states', 'status', self::JOB_TERMINAL),
            'active_ingestion_sources' => $this->count('ingestion_sources', 'index_status', ['ready', 'failed', 'cancelled', 'deleted']),
            // A ready/failed source may still have a live recurring schedule.
            'scheduled_ingestion_sources' => $this->count('ingestion_sources', 'temporal_schedule_id', [''], includeNull: false),
            'open_managed_document_deletions' => $this->count('managed_document_deletions', 'status', ['completed', 'continued', 'abandoned']),
            'active_managed_outputs' => $this->count('managed_document_outputs', 'active', [false]),
            'residual_legacy_datasets' => $this->count('datasets'),
            'grants' => $this->count('dataset_grants'),
            'grant_tokens' => $this->count('dataset_grant_tokens'),
            'scrape_jobs' => $this->count('scrape_jobs'),
            'scrape_statistics' => $this->count('scrape_statistics'),
            'scraped_elements' => $this->count('scraped_elements'),
            'ingested_pages' => $this->count('ingested_pages'),
            'rag_ingestion_artifacts' => $this->count('rag_ingestion_artifacts'),
            'rag_graph_failures' => $this->count('rag_graph_failures'),
            'pipeline_worker_events' => $this->count('pipeline_worker_events'),
            'pipeline_tasks_history' => $this->count('pipeline_tasks'),
            'pipeline_jobs_history' => $this->count('pipeline_jobs'),
            'pipeline_stage_states_history' => $this->count('pipeline_stage_states'),
            'ingestion_sources_history' => $this->count('ingestion_sources'),
            'managed_documents_history' => $this->count('managed_documents'),
            'managed_document_outputs_history' => $this->count('managed_document_outputs'),
            'managed_document_deletions_history' => $this->count('managed_document_deletions'),
        ];
    }

    /** @param list<string|bool> $excluded */
    private function count(string $table, ?string $column = null, array $excluded = [], bool $includeNull = true): ?int
    {
        $connection = $this->database->connection();
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable($table) || ($column !== null && ! $schema->hasColumn($table, $column))) {
            return null;
        }
        $query = $connection->table($table);
        if ($column !== null) {
            $query->where(function (Builder $query) use ($column, $excluded, $includeNull): void {
                $query->whereNotIn($column, $excluded);
                if ($includeNull) {
                    $query->orWhereNull($column);
                }
            });
        }

        return $query->count();
    }
}
