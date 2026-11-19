<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pipeline\TerminalProjectionReconciliationService;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

class ReconcileTerminalProjectionsCommand extends Command
{
    protected $signature = 'pipeline:reconcile-terminal-projections
        {--job-id= : Restrict inspection to one exact job ID (required with --apply)}
        {--apply : Apply eligible stage repairs; default is read-only}
        {--evidence-ref= : Approved retention-expiry/quiescence evidence reference; recorded durably}
        {--json : Print a machine-readable audit report}';

    protected $description = 'Reconcile running stages of terminal pipeline operations using explicit Temporal evidence.';

    public function handle(TerminalProjectionReconciliationService $service, LoggerInterface $logger): int
    {
        $jobId = $this->option('job-id');
        $reference = $this->option('evidence-ref');
        $apply = (bool) $this->option('apply');
        if (($apply && (! is_string($jobId) || trim($jobId) === ''))
            || ($reference !== null && (! is_string($reference) || ! preg_match('/\A[\x21-\x7E]{1,512}\z/', $reference)))) {
            $report = ['status' => 'refused', 'error' => 'Apply requires --job-id; evidence references must be 1–512 printable non-space ASCII characters.', 'reconciled' => 0];
        } else {
            try {
                $report = $service->reconcile($apply, $jobId, $reference);
            } catch (\Throwable $exception) {
                $logger->error('Terminal stage reconciliation inspection failed.', ['exception_class' => $exception::class]);
                $report = ['status' => 'refused', 'error' => 'inspection_failed_review_prior_rows_before_retry', 'dry_run' => ! $apply];
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($report['candidates'] ?? [] as $row) {
                $this->line(json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            }
            $this->line(json_encode(array_diff_key($report, ['candidates' => true]), JSON_THROW_ON_ERROR));
        }

        return in_array($report['status'], ['candidate', 'reconciled', 'no_candidate'], true) ? self::SUCCESS : self::FAILURE;
    }
}
