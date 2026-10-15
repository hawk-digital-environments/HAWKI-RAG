<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\ManagedDocumentWriterReconciliationService;
use Illuminate\Console\Command;

final class ReconcileManagedDocumentWriterCommand extends Command
{
    protected $signature = 'documents:reconcile-writer {sourceId} {manifest} {--confirm-quiescence : All relevant worker processes stopped, workflows closed, schedules disabled and sink requests drained} {--retire-operation= : Retire a legacy deletion only after every owned source is reconciled and its deletion run is closed}';

    protected $description = 'Reconcile historical managed targets after independently established physical writer quiescence.';

    public function handle(ManagedDocumentWriterReconciliationService $reconciliation): int
    {
        try {
            $reconciliation->reconcile($this->argument('sourceId'), $this->argument('manifest'), (bool) $this->option('confirm-quiescence'));
            if ($operationId = $this->option('retire-operation')) {
                $reconciliation->retireLegacyOperation($operationId, $this->argument('sourceId'));
            }
        } catch (ManagedDeletionException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info('Writer gate remains closed. Repeat the authorized managed-document operation to verify sink cleanup.');

        return self::SUCCESS;
    }
}
