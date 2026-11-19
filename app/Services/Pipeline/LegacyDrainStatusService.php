<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Pipeline\Repositories\LegacyDrainRepository;
use App\Services\Pipeline\Values\LegacyDrainState;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class LegacyDrainStatusService
{
    public function __construct(private LegacyDrainRepository $legacy) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        $counts = $this->legacy->counts();
        $state = new LegacyDrainState($counts);

        return [
            'epoch' => '15B-1',
            'read_only' => true,
            'schema_complete' => $state->schemaComplete(),
            'counts' => $counts,
            'legacy_work_drained' => $state->workDrained(),
            // Point-in-time SQL counts cannot prove these operational gates.
            'gates' => [
                'temporal_workflows_schedules_reconciled' => 'unresolved',
                'callback_quiet_window' => 'unresolved',
                'no_new_legacy_writes' => 'unresolved',
                'history_retention' => 'unresolved',
                'backup_restore_verification' => 'unresolved',
                'rollback_window_closed' => false,
                'python_production_acceptance' => 'unresolved',
                'epoch15b_drop_approval' => false,
            ],
            'safe_to_drop' => false,
        ];
    }
}
