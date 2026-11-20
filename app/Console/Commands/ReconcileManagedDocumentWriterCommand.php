<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\ManagedDocumentWriterReconciliationService;
use Illuminate\Console\Command;

final class ReconcileManagedDocumentWriterCommand extends Command
{
    protected $signature = 'documents:reconcile-writer {sourceId} {manifest} {--apply : Apply an eligible assessment; default is read-only} {--json : Print machine-readable assessment} {--evidence-ref= : Approved exact execution retention/quiescence record} {--confirm-quiescence : All relevant worker processes stopped, workflows closed, schedules disabled and sink requests drained} {--retire-operation= : Retire a legacy deletion only after every owned source is reconciled and its deletion run is closed}';

    protected $description = 'Reconcile historical managed targets after independently established physical writer quiescence.';

    public function handle(ManagedDocumentWriterReconciliationService $reconciliation): int
    {
        try {
            $arguments = [$this->argument('sourceId'), $this->argument('manifest'), (bool) $this->option('confirm-quiescence'), $this->option('evidence-ref')];
            if ($operationId = $this->option('retire-operation')) {
                $reconciliation->validateRetirement($operationId, $this->argument('sourceId'));
            }
            if (! $this->option('apply')) {
                $report = $reconciliation->assess(...$arguments);
                $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

                return $report['eligible'] ? self::SUCCESS : self::FAILURE;
            }
            $report = $reconciliation->reconcile(...$arguments);
            if ($operationId = $this->option('retire-operation')) {
                $reconciliation->retireLegacyOperation($operationId, $this->argument('sourceId'));
            }
        } catch (ManagedDeletionException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('Writer reconciliation failed; inspect the read-only assessment before retrying.');

            return self::FAILURE;
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
