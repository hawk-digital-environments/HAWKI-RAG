<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\IngestionSource;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\Values\ManagedDocumentId;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ManagedDocumentRecoveryGuard
{
    public function __construct(private PythonTemporalBridgeClient $bridge) {}

    public function verify(IngestionSource $source): void
    {
        $request = $source->metadata['request'] ?? [];
        if (! ManagedDocumentId::fromRequestMetadata($request['metadata'] ?? $request)) {
            return;
        }
        if (($source->metadata['managed_writer_protocol'] ?? null) !== 1 || isset($source->metadata['managed_deletion'])
            || ($source->metadata['managed_writer_gate'] ?? null) === 'closed') {
            throw ManagedDeletionException::conflict('Managed recovery requires reconciled writer evidence and an unfenced source.');
        }
        foreach ($source->metadata['managed_writers'] ?? [] as $writer) {
            if ($writer['status'] !== 'released') {
                throw ManagedDeletionException::conflict('Original managed writer remains active or uncertain; recovery cannot start another writer.');
            }
        }
        $history = $source->metadata['managed_writer_history'] ?? [];
        if ($source->temporal_workflow_id) {
            $history[] = ['workflow_id' => $source->temporal_workflow_id, 'run_id' => $source->metadata['temporal']['run_id'] ?? $source->metadata['worker_event']['run_id'] ?? null];
        }
        foreach (array_unique($history, SORT_REGULAR) as $writer) {
            if (empty($writer['run_id'])) {
                throw ManagedDeletionException::conflict('Reconcile the original managed workflow start before recovery.');
            }
            $status = $this->bridge->workflowStatus($writer['workflow_id'], $writer['run_id']);
            if (! in_array($status['status'] ?? null, ['COMPLETED', 'FAILED', 'CANCELED', 'TERMINATED', 'TIMED_OUT'], true)) {
                throw ManagedDeletionException::conflict('Original managed workflow is still retrying; recovery must wait for closure and released writers.');
            }
        }
    }
}
