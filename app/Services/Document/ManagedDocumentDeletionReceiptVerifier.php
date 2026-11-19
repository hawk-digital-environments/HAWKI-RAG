<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\ManagedDocument;
use App\Models\ManagedDocumentDeletion;
use App\Services\Document\Exceptions\ManagedDeletionException;
use Illuminate\Container\Attributes\Singleton;

/** Immutable receipt proof only: no repositories, sources, dispatch or writes. */
#[Singleton]
readonly class ManagedDocumentDeletionReceiptVerifier
{
    /** Completed receipts use frozen proof, never source markers changed by later deletions. */
    public function verify(ManagedDocumentDeletion $operation, ManagedDocument $document, array $receipt): void
    {
        if (! in_array($operation->status, ['completed', 'continued'], true)
            || ($operation->attempts['writer_protocol'] ?? null) !== 1
            || ($operation->attempts['effective_graph_scope'] ?? null) !== true
            || (array_key_exists('schema_version', $receipt) && filter_var($receipt['schema_version'], FILTER_VALIDATE_INT) !== 1)) {
            throw ManagedDeletionException::conflict('Deletion replay has no supported terminal protocol evidence.');
        }
        $this->assertOperationReceipt($operation, $document, $receipt);
        // Completion can precede the start acknowledgement. Its stored final
        // run and sink results are durable proof even without a pending-run entry.
        if (($receipt['status'] ?? null) !== 'completed'
            || ! is_array($operation->attempts['source_states'] ?? null)
            || ! is_array($operation->workflow_input['targets'] ?? null)
            || ! is_array($operation->results) || ! is_array($receipt['results'] ?? null)
            || $this->canonicalJson($operation->results) !== $this->canonicalJson($receipt['results'])) {
            throw ManagedDeletionException::conflict('Deletion replay does not match the stored terminal receipt.');
        }
        $this->assertSinkEvidence($operation, $operation->results);
    }

    /** The legacy SQL ledger proves ownership; a callback cannot establish a new run. */
    public function assertOperationReceipt(ManagedDocumentDeletion $operation, ManagedDocument $document, array $receipt): void
    {
        $input = $operation->workflow_input;
        $run = $operation->attempts['runs'][$operation->run_id] ?? null;
        if (($receipt['operation_id'] ?? null) !== $operation->operation_id
            || $document->document_id !== $operation->document_id
            || $document->dataset_id !== $operation->dataset_id
            || ($input['operation_id'] ?? null) !== $operation->operation_id
            || ($input['managed_document_id'] ?? null) !== $document->document_id
            || ($input['dataset_id'] ?? null) !== $document->dataset_id
            || $operation->workflow_id !== 'managed-'.$operation->operation_id
            || ($receipt['workflow_id'] ?? null) !== $operation->workflow_id
            || ! $operation->run_id || ($receipt['run_id'] ?? null) !== $operation->run_id
            || ($run !== null && (! is_array($run) || ($run['superseded'] ?? false)
                || ! in_array($run['status'] ?? null, ['pending', 'failed'], true)))
            || ! is_int($operation->attempts['dispatch_sequence'] ?? null)
            || $operation->attempts['dispatch_sequence'] < 1
            || ! in_array($operation->status, ['pending', 'failed', 'completed', 'continued'], true)) {
            throw ManagedDeletionException::conflict('Deletion drain receipt has no matching durable legacy operation and run.');
        }
    }

    public function assertSinkEvidence(ManagedDocumentDeletion $operation, array $results): void
    {
        $targets = $operation->workflow_input['targets'];
        if (count($results) !== count($targets)) {
            throw ManagedDeletionException::conflict('Deletion callback has incomplete sink evidence.');
        }
        $byId = [];
        foreach ($results as $result) {
            $id = is_array($result) ? ($result['output_id'] ?? null) : null;
            if (! is_int($id) || $id < 1 || isset($byId[$id])) {
                throw ManagedDeletionException::conflict('Deletion callback has duplicate output evidence.');
            }
            $byId[$id] = $result;
        }
        foreach ($targets as $target) {
            $proof = $byId[$target['output_id']] ?? [];
            $vector = $proof['qdrant'] ?? [];
            $graph = $proof['neo4j'] ?? [];
            if (($vector['verified'] ?? null) !== true || ($vector['remaining_points'] ?? null) !== 0
                || ($vector['dataset_id'] ?? null) !== $operation->dataset_id || ($vector['source_id'] ?? null) !== $target['source_id']
                || ($vector['doc_id'] ?? null) !== $target['doc_id'] || ($vector['collection'] ?? null) !== $target['collection']
                || ($graph['verified'] ?? null) !== true || ($graph['remaining_contributions'] ?? null) !== 0
                || ($graph['required'] ?? null) !== ($target['neo4j_namespace'] !== null)
                || ($target['neo4j_namespace'] !== null && (($graph['dataset_id'] ?? null) !== $operation->dataset_id || ($graph['namespace'] ?? null) !== $target['neo4j_namespace']
                    || ($graph['doc_id'] ?? null) !== $target['doc_id']))) {
                throw ManagedDeletionException::conflict('Deletion callback has no verified scoped sink evidence.');
            }
        }
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }
            $value = array_map(fn ($item) => json_decode($this->canonicalJson($item), true, flags: JSON_THROW_ON_ERROR), $value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
