<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Models\ManagedDocument;
use App\Models\ManagedDocumentDeletion;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\ManagedDocumentDeletionReceiptVerifier;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class ManagedDocumentDeletionReceiptVerifierTest extends TestCase
{
    #[TestWith(['completed'])]
    #[TestWith(['continued'])]
    public function test_terminal_proof_needs_no_sources_dispatch_or_mutation(string $status): void
    {
        [$operation, $document, $receipt] = $this->fixture($status);
        // A later operation can replace all current source/task markers. The
        // verifier receives only the retained operation and document owner.
        $document->forceFill(['latest_source_id' => 'source-later', 'latest_task_id' => 'task-later', 'status' => 'deleted']);
        $before = [$operation->getAttributes(), $document->getAttributes()];
        $verifier = new ManagedDocumentDeletionReceiptVerifier;
        $verifier->verify($operation, $document, $receipt);
        $verifier->verify($operation, $document, $receipt);
        $this->assertSame($before, [$operation->getAttributes(), $document->getAttributes()]);
        $this->assertFalse($operation->exists);
        $this->assertFalse($document->exists);
    }

    #[TestWith(['operation_id', 'delete_forged'])]
    #[TestWith(['workflow_id', 'wrong-workflow'])]
    #[TestWith(['run_id', 'wrong-run'])]
    #[TestWith(['status', 'failed'])]
    #[TestWith(['schema_version', 2])]
    #[TestWith(['schema_version', null])]
    #[TestWith(['results', []])]
    #[TestWith(['results.0.output_id', 999])]
    #[TestWith(['results.0.qdrant.dataset_id', 'foreign'])]
    #[TestWith(['results.0.qdrant.doc_id', 'foreign'])]
    #[TestWith(['results.0.qdrant.source_id', 'foreign'])]
    #[TestWith(['results.0.qdrant.verified', false])]
    #[TestWith(['results.0.qdrant.remaining_points', 1])]
    #[TestWith(['results.0.qdrant.extra', 'forged'])]
    #[TestWith(['results.0.neo4j.namespace', 'foreign'])]
    public function test_forged_receipt_cannot_exploit_terminal_replay(string $field, mixed $value): void
    {
        [$operation, $document, $receipt] = $this->fixture();
        data_set($receipt, $field, $value);
        $this->expectException(ManagedDeletionException::class);
        (new ManagedDocumentDeletionReceiptVerifier)->verify($operation, $document, $receipt);
    }

    #[TestWith(['operation_id', 'delete_forged'])]
    #[TestWith(['document_id', 'adoc_foreign'])]
    #[TestWith(['dataset_id', 'foreign'])]
    #[TestWith(['workflow_id', 'wrong-workflow'])]
    #[TestWith(['run_id', null])]
    #[TestWith(['run_id', 'wrong-final-run'])]
    #[TestWith(['status', 'pending'])]
    #[TestWith(['status', 'failed'])]
    #[TestWith(['status', 'abandoned'])]
    #[TestWith(['workflow_input.operation_id', 'delete_other'])]
    #[TestWith(['workflow_input.managed_document_id', 'adoc_foreign'])]
    #[TestWith(['workflow_input.dataset_id', 'foreign'])]
    #[TestWith(['attempts.dispatch_sequence', 0])]
    #[TestWith(['attempts.dispatch_sequence', null])]
    #[TestWith(['attempts.dispatch_sequence', true])]
    #[TestWith(['attempts.dispatch_sequence', 'not-dispatched'])]
    #[TestWith(['attempts.dispatch_sequence', 1.5])]
    #[TestWith(['attempts.writer_protocol', null])]
    #[TestWith(['attempts.effective_graph_scope', false])]
    #[TestWith(['attempts.runs.run-original.superseded', true])]
    #[TestWith(['attempts.source_states', null])]
    #[TestWith(['results', null])]
    public function test_retained_ledger_must_prove_immutable_identity(string $field, mixed $value): void
    {
        [$operation, $document, $receipt] = $this->fixture();
        $attributes = $operation->attributesToArray();
        data_set($attributes, $field, $value);
        $operation->forceFill($attributes);
        $this->expectException(ManagedDeletionException::class);
        (new ManagedDocumentDeletionReceiptVerifier)->verify($operation, $document, $receipt);
    }

    #[TestWith(['document_id', 'adoc_foreign'])]
    #[TestWith(['dataset_id', 'foreign'])]
    public function test_retained_document_ownership_is_required(string $field, string $value): void
    {
        [$operation, $document, $receipt] = $this->fixture();
        $document->forceFill([$field => $value]);
        $this->expectException(ManagedDeletionException::class);
        (new ManagedDocumentDeletionReceiptVerifier)->verify($operation, $document, $receipt);
    }

    public function test_completion_before_start_acknowledgement_retains_final_run_proof(): void
    {
        [$operation, $document, $receipt] = $this->fixture();
        $attempts = $operation->attempts;
        unset($attempts['runs']);
        $operation->forceFill(['attempts' => $attempts]);
        (new ManagedDocumentDeletionReceiptVerifier)->verify($operation, $document, $receipt);
        $this->assertSame('run-original', $operation->run_id);
    }

    public function test_version_one_preserves_the_callback_boundary_integer_contract(): void
    {
        [$operation, $document, $receipt] = $this->fixture();
        $receipt['schema_version'] = '1';
        (new ManagedDocumentDeletionReceiptVerifier)->verify($operation, $document, $receipt);
        $this->assertSame('completed', $operation->status);
    }

    public function test_stored_and_incoming_evidence_must_still_match_frozen_sink_scope(): void
    {
        [$operation, $document, $receipt] = $this->fixture();
        $results = $receipt['results'];
        $results[0]['qdrant']['dataset_id'] = 'foreign';
        $operation->forceFill(['results' => $results]);
        $receipt['results'] = $results;
        $this->expectException(ManagedDeletionException::class);
        (new ManagedDocumentDeletionReceiptVerifier)->verify($operation, $document, $receipt);
    }

    private function fixture(string $status = 'completed'): array
    {
        $id = 'delete_'.str_repeat('a', 64);
        $results = [['output_id' => 1,
            'qdrant' => ['verified' => true, 'remaining_points' => 0, 'dataset_id' => 'owned', 'source_id' => 'source-original', 'doc_id' => 'doc-original', 'collection' => 'vectors'],
            'neo4j' => ['verified' => true, 'remaining_contributions' => 0, 'required' => true, 'dataset_id' => 'owned', 'doc_id' => 'doc-original', 'namespace' => 'graph']]];
        $operation = new ManagedDocumentDeletion(['operation_id' => $id, 'document_id' => 'adoc_original', 'dataset_id' => 'owned',
            'workflow_id' => 'managed-'.$id, 'run_id' => 'run-original', 'status' => $status,
            'workflow_input' => ['operation_id' => $id, 'managed_document_id' => 'adoc_original', 'dataset_id' => 'owned',
                'targets' => [['output_id' => 1, 'source_id' => 'source-original', 'doc_id' => 'doc-original', 'collection' => 'vectors', 'neo4j_namespace' => 'graph']]],
            'attempts' => ['dispatch_sequence' => 1, 'writer_protocol' => 1, 'effective_graph_scope' => true,
                'source_states' => ['source-original' => 'ready'], 'runs' => ['run-original' => ['status' => 'pending']]], 'results' => $results]);
        $document = new ManagedDocument(['document_id' => 'adoc_original', 'dataset_id' => 'owned']);
        $receipt = ['schema_version' => 1, 'operation_id' => $id, 'workflow_id' => 'managed-'.$id,
            'run_id' => 'run-original', 'status' => 'completed', 'results' => $results];

        return [$operation, $document, $receipt];
    }
}
