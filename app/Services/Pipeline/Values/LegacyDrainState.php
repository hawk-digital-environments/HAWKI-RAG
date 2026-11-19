<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

/** One conservative interpretation shared by the CLI and pipeline health. */
readonly class LegacyDrainState
{
    public const WORK_KEYS = [
        'nonterminal_pipeline_tasks', 'nonterminal_pipeline_jobs', 'nonterminal_stage_states',
        'nonterminal_job_index_states',
        'active_ingestion_sources', 'scheduled_ingestion_sources', 'open_managed_document_deletions',
        'active_managed_outputs', 'unreleased_managed_writers', 'unknown_managed_writer_sources',
        'unresolved_recovery_jobs', 'unresolved_managed_replacements', 'unprocessed_worker_events',
    ];

    /** @param array<string, int|null> $counts */
    public function __construct(private array $counts) {}

    public function schemaComplete(): bool
    {
        return $this->counts !== [] && ! in_array(null, $this->counts, true);
    }

    public function workDrained(): bool
    {
        if (! $this->schemaComplete()) {
            return false;
        }
        foreach (self::WORK_KEYS as $key) {
            if (($this->counts[$key] ?? null) !== 0) {
                return false;
            }
        }

        return true;
    }
}
