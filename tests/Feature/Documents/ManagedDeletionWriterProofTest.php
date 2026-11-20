<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\IngestionSource;
use App\Models\ManagedDocumentDeletion;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\ManagedWriterFixture;
use Tests\TestCase;

class ManagedDeletionWriterProofTest extends TestCase
{
    use ManagedWriterFixture, RefreshDatabase;

    private array $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWriter();
        PipelineTask::query()->update(['status' => 'failed']);
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['managed_writer_gate'] = 'closed';
        $metadata['managed_writer_protocol'] = 1;
        $metadata['managed_target_manifest_complete'] = true;
        $metadata['managed_writers'] = ['writer' => ['workflow_id' => 'writer-workflow', 'run_id' => 'writer-run', 'status' => 'released', 'job_id' => 'writer-job', 'activity_id' => '3', 'attempt' => 1]];
        $metadata['managed_deletion'] = ['operation_id' => 'delete_'.str_repeat('a', 64)];
        $source->update(['metadata' => $metadata]);
        ManagedDocumentDeletion::query()->create(['operation_id' => $metadata['managed_deletion']['operation_id'], 'document_id' => 'adoc_writer_test', 'dataset_id' => 'writer-test',
            'purpose' => 'delete', 'status' => 'pending', 'workflow_id' => 'deletion-workflow', 'run_id' => 'deletion-run',
            'attempts' => ['writer_protocol' => 1, 'effective_graph_scope' => true, 'dispatch_sequence' => 3, 'source_states' => ['source-writer' => 'ready']],
            'workflow_input' => ['targets' => [], 'writers' => [['workflow_id' => 'writer-workflow', 'run_id' => 'writer-run']]]]);
        $this->request = ['operation_id' => $metadata['managed_deletion']['operation_id'], 'document_id' => 'adoc_writer_test', 'dataset_id' => 'writer-test',
            'deletion_workflow_id' => 'deletion-workflow', 'deletion_run_id' => 'deletion-run', 'writer_workflow_id' => 'writer-workflow', 'writer_run_id' => 'writer-run', 'targets_sha256' => hash('sha256', '[]')];
    }

    private function send(bool $sign = true)
    {
        $body = json_encode($this->request, JSON_THROW_ON_ERROR);
        $stamp = (string) time();

        return $this->call('POST', '/api/internal/pipeline/managed-deletion-writer-proof', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HAWKI_TIMESTAMP' => $stamp, 'HTTP_X_HAWKI_SIGNATURE' => $sign ? 'v1='.hash_hmac('sha256', $stamp.'.'.$body, 'writer-test-secret') : 'invalid'], $body);
    }

    public function test_released_writer_returns_signed_bound_proof_without_writes(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $response = $this->send()->assertOk()->json();
        $this->assertSame(hash_hmac('sha256', "hawki-deletion-writer-proof-v1\n".$response['proof'], 'writer-test-secret'), $response['signature']);
        $proof = json_decode($response['proof'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($proof['verified']);
        $this->assertSame(3, $proof['dispatch_sequence']);
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $sql);
        }
    }

    #[TestWith(['dataset_id'])]
    #[TestWith(['document_id'])]
    #[TestWith(['writer_run_id'])]
    #[TestWith(['deletion_run_id'])]
    #[TestWith(['targets_sha256'])]
    public function test_scope_mismatch_refuses(string $field): void
    {
        $this->request[$field] = 'wrong';
        $this->send()->assertStatus(409);
    }

    #[TestWith(['active'])]
    #[TestWith(['uncertain'])]
    public function test_registered_live_writer_refuses_even_with_audit(string $status): void
    {
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['managed_writers']['writer']['status'] = $status;
        $metadata['managed_quiescence_reconciliation'] = ['evidence_ref' => 'change:approved'];
        $source->update(['metadata' => $metadata]);
        $this->send()->assertStatus(409);
    }

    public function test_unregistered_history_requires_exact_approved_durable_audit(): void
    {
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['managed_writers'] = [];
        $source->update(['metadata' => $metadata]);
        $this->send()->assertStatus(409);
        $metadata['managed_quiescence_reconciliation'] = ['source_id' => 'source-writer', 'dataset_id' => 'writer-test', 'document_id' => 'adoc_writer_test',
            'manifest_sha256' => str_repeat('a', 64), 'evidence_ref' => 'change:approved', 'executions' => ['writer-workflow|writer-run' => ['workflow_id' => 'writer-workflow', 'run_id' => 'writer-run', 'classification' => 'not_found_requires_retention_approval']]];
        $source->update(['metadata' => $metadata]);
        $this->send()->assertOk();
    }

    public function test_authentication_recovery_and_replacement_fail_closed(): void
    {
        $this->send(false)->assertUnauthorized();
        PipelineJob::query()->update(['metadata' => ['pending_recovery' => ['state' => 'reserved']]]);
        $this->send()->assertStatus(409);
        PipelineJob::query()->update(['metadata' => null]);
        ManagedDocumentDeletion::query()->create(['operation_id' => 'delete_'.str_repeat('b', 64), 'document_id' => 'adoc_writer_test', 'dataset_id' => 'writer-test',
            'purpose' => 'update', 'status' => 'preparing', 'workflow_id' => 'replacement', 'workflow_input' => []]);
        $this->send()->assertStatus(409);
    }
}
