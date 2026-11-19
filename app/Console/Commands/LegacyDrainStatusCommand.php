<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pipeline\LegacyDrainStatusService;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

class LegacyDrainStatusCommand extends Command
{
    protected $signature = 'rag:legacy-drain-status {--json : Print a machine-readable report}';

    protected $description = 'Read legacy drain counts and unresolved retention gates without changing data.';

    public function handle(LegacyDrainStatusService $status, LoggerInterface $logger): int
    {
        try {
            $report = $status->report();
        } catch (\Throwable $exception) {
            $logger->error('Legacy drain inspection failed.', ['exception' => $exception]);
            $report = ['read_only' => true, 'schema_complete' => false, 'safe_to_drop' => false,
                'error' => 'legacy_drain_inspection_failed'];
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($report['counts'] ?? [] as $name => $count) {
                $this->line($name.'='.($count ?? 'unknown'));
            }
            $this->line('legacy_work_drained='.(($report['legacy_work_drained'] ?? false) ? 'true' : 'false'));
            $this->line('safe_to_drop=false');
            $this->line('History retention, verified backups, rollback closure and Epoch 15B approval remain unresolved.');
        }

        return $report['schema_complete'] ? self::SUCCESS : self::FAILURE;
    }
}
