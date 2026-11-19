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

    public const INDEX_TERMINAL = ['ready', 'failed', 'cancelled', 'deleted'];

    public function __construct(private DatabaseManager $database) {}

    /** @return array<string, int|null> Missing schema is unknown, never zero. */
    public function counts(): array
    {
        $writers = $this->writerCounts();

        return [
            'nonterminal_pipeline_tasks' => $this->count('pipeline_tasks', 'status', self::TASK_TERMINAL),
            'nonterminal_pipeline_jobs' => $this->count('pipeline_jobs', 'status', self::JOB_TERMINAL),
            'nonterminal_job_index_states' => $this->count('pipeline_jobs', 'index_status', self::INDEX_TERMINAL),
            'nonterminal_stage_states' => $this->count('pipeline_stage_states', 'status', self::JOB_TERMINAL),
            'active_ingestion_sources' => $this->count('ingestion_sources', 'index_status', ['ready', 'failed', 'cancelled', 'deleted']),
            'deleting_ingestion_sources' => $this->hasColumns('ingestion_sources', ['index_status'])
                ? $this->database->table('ingestion_sources')->where('index_status', 'deleting')->count() : null,
            // A ready/failed source may still have a live recurring schedule.
            'scheduled_ingestion_sources' => $this->count('ingestion_sources', 'temporal_schedule_id', [''], includeNull: false),
            'open_managed_document_deletions' => $this->count('managed_document_deletions', 'status', ['completed', 'continued', 'abandoned']),
            'active_managed_outputs' => $this->count('managed_document_outputs', 'active', [false]),
            ...$writers,
            'unresolved_recovery_jobs' => $this->unresolvedRecoveryJobs(),
            'unresolved_managed_replacements' => $this->unresolvedReplacements(),
            'unprocessed_worker_events' => $this->hasColumns('pipeline_worker_events', ['processed_at'])
                ? $this->database->table('pipeline_worker_events')->whereNull('processed_at')->count() : null,
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
            'documents_history' => $this->count('documents'),
        ];
    }

    /** Missing writer evidence is distinct from a known empty writer registry. */
    private function writerCounts(): array
    {
        if (! $this->hasColumns('ingestion_sources', ['metadata'])) {
            return ['unreleased_managed_writers' => null, 'unknown_managed_writer_sources' => null];
        }
        $unreleased = $unknown = 0;
        foreach ($this->database->table('ingestion_sources')->select('metadata')->cursor() as $source) {
            $metadata = $this->jsonObject($source->metadata);
            if ($metadata === null) {
                $unknown++;

                continue;
            }
            $writers = array_key_exists('managed_writers', $metadata) ? $metadata['managed_writers'] : [];
            if (array_key_exists('managed_writers', $metadata)
                && (! is_array($writers) || ($writers !== [] && array_is_list($writers)))) {
                $unknown++;

                continue;
            }
            foreach ($writers as $writer) {
                if (! is_array($writer) || ($writer['status'] ?? null) !== 'released') {
                    $unreleased++;
                }
            }
            // A never-dispatched reservation has no external writer. Otherwise
            // old/unproven protocols cannot establish that a registry is complete.
            if (($metadata['managed_dispatch']['attempted'] ?? null) !== false
                && (($metadata['managed_writer_protocol'] ?? null) !== 1
                    || ($metadata['managed_target_manifest_complete'] ?? null) !== true)) {
                $unknown++;
            }
        }

        return ['unreleased_managed_writers' => $unreleased, 'unknown_managed_writer_sources' => $unknown];
    }

    private function unresolvedRecoveryJobs(): ?int
    {
        if (! $this->hasColumns('pipeline_jobs', ['metadata'])) {
            return null;
        }
        $count = 0;
        foreach ($this->database->table('pipeline_jobs')->select('metadata')->cursor() as $job) {
            // SQL NULL is the original no-metadata/no-reservation representation.
            $metadata = $job->metadata === null ? [] : $this->jsonObject($job->metadata);
            if ($metadata === null || (array_key_exists('pending_recovery', $metadata)
                && (! is_array($metadata['pending_recovery']) || ($metadata['pending_recovery']['state'] ?? null) !== 'confirmed'))) {
                $count++;
            }
        }

        return $count;
    }

    private function unresolvedReplacements(): ?int
    {
        if (! $this->hasColumns('managed_document_deletions', ['purpose', 'status'])) {
            return null;
        }

        return $this->database->table('managed_document_deletions')->where(function (Builder $query): void {
            $query->whereNull('purpose')->orWhereNotIn('purpose', ['delete', 'update'])
                ->orWhere(fn (Builder $updates) => $updates->where('purpose', 'update')
                    ->where(fn (Builder $status) => $status->whereNull('status')->orWhereNotIn('status', ['continued', 'abandoned'])));
        })->count();
    }

    private function jsonObject(mixed $value): ?array
    {
        if (! is_string($value) || ! str_starts_with(ltrim($value), '{')) {
            return null;
        }
        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function hasColumns(string $table, array $columns = []): bool
    {
        $schema = $this->database->connection()->getSchemaBuilder();

        return $schema->hasTable($table) && ($columns === [] || $schema->hasColumns($table, $columns));
    }

    /** @param list<string|bool> $excluded */
    private function count(string $table, ?string $column = null, array $excluded = [], bool $includeNull = true): ?int
    {
        $connection = $this->database->connection();
        if (! $this->hasColumns($table, $column === null ? [] : [$column])) {
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
