<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Pipeline\Repositories\LegacyDrainRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class LegacyDrainStatusService
{
    public function __construct(private LegacyDrainRepository $legacy) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        $counts = $this->legacy->counts();
        $workKeys = ['nonterminal_pipeline_tasks', 'nonterminal_pipeline_jobs', 'nonterminal_stage_states',
            'active_ingestion_sources', 'scheduled_ingestion_sources', 'open_managed_document_deletions'];
        $drained = true;
        foreach ($workKeys as $key) {
            $drained = $drained && $counts[$key] === 0;
        }

        return [
            'epoch' => '15A',
            'read_only' => true,
            'schema_complete' => ! in_array(null, $counts, true),
            'counts' => $counts,
            'legacy_work_drained' => $drained,
            // No CLI option can waive these gates during 15A.
            'gates' => [
                'history_retention' => 'unresolved',
                'backup_restore_verification' => 'unresolved',
                'rollback_window_closed' => false,
                'epoch15b_drop_approval' => false,
            ],
            'safe_to_drop' => false,
        ];
    }
}
