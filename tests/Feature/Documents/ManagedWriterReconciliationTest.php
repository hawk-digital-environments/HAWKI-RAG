<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\IngestionSource;
use App\Models\ManagedDocumentDeletion;
use App\Models\ManagedDocumentOutput;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\ManagedDocumentWriterReconciliationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\ManagedWriterFixture;
use Tests\TestCase;

class ManagedWriterReconciliationTest extends TestCase
{
    use DatabaseMigrations, ManagedWriterFixture;

    private string $root;

    private string $manifest;

    private string $status = 'COMPLETED';

    private int $http = 200;

    private ?\Closure $during = null;

    private bool $fakeInstalled = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWriter();
        PipelineTask::query()->update(['status' => 'failed']);
        $this->root = sys_get_temp_dir().'/writer-assessment-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->root);
        $this->manifest = $this->root.'/manifest.json';
        config()->set('temporal.storage.shared_root', $this->root);
        $this->writeManifest();
        $this->temporal();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function writeManifest(array $records = []): void
    {
        $records = $records ?: [['document_id' => $this->target()['doc_id'], 'relative_path' => 'one.md', 'graph' => true]];
        foreach ($records as &$record) {
            if (! isset($record['content_hash']) && ! str_contains($record['relative_path'], '..')) {
                $directory = $this->root.'/sources/source-writer/markdown';
                File::ensureDirectoryExists($directory);
                File::put($directory.'/'.$record['relative_path'], 'owned content');
                $record['content_hash'] = hash('sha256', 'owned content');
                $record['markdown_path'] = $directory.'/'.$record['relative_path'];
            }
        }
        File::put($this->manifest, json_encode($records, JSON_THROW_ON_ERROR));
    }

    private function temporal(string $status = 'COMPLETED', int $http = 200, ?callable $during = null): void
    {
        $this->status = $status;
        $this->http = $http;
        $this->during = $during === null ? null : \Closure::fromCallable($during);
        if ($this->fakeInstalled) {
            return;
        }
        $this->fakeInstalled = true;
        Http::fake(function ($request) {
            $this->assertSame(0, DB::transactionLevel());
            $metadata = IngestionSource::query()->firstOrFail()->metadata;
            if (! isset($metadata['managed_quiescence_reconciliation'])) {
                $this->assertArrayNotHasKey('managed_writer_gate', $metadata);
            }
            if ($this->during) {
                ($this->during)();
            }

            return Http::response(['workflow_id' => $request['workflow_id'], 'run_id' => $request['run_id'], 'status' => $this->status], $this->http);
        });
    }

    private function metadata(array $changes): void
    {
        $source = IngestionSource::query()->firstOrFail();
        $source->update(['metadata' => array_replace($source->metadata, $changes)]);
    }

    private function assess(?string $ref = null): array
    {
        return app(ManagedDocumentWriterReconciliationService::class)->assess('source-writer', $this->manifest, true, $ref);
    }

    private function apply(?string $ref = null): array
    {
        return app(ManagedDocumentWriterReconciliationService::class)->reconcile('source-writer', $this->manifest, true, $ref);
    }

    public function test_default_command_and_valid_assessment_perform_zero_writes(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->artisan('documents:reconcile-writer', ['sourceId' => 'source-writer', 'manifest' => $this->manifest, '--confirm-quiescence' => true])->assertExitCode(0);
        $this->assertTrue($this->assess()['eligible']);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|create|alter|drop)\b/i', $query);
        }
        $this->assertDatabaseCount('managed_document_outputs', 0);
    }

    #[TestWith(['RUNNING', 200, 'temporal_active'])]
    #[TestWith(['NOT_FOUND', 404, 'temporal_unavailable'])]
    #[TestWith(['NamespaceNotFoundFailure', 503, 'temporal_unavailable'])]
    #[TestWith(['UNKNOWN', 200, 'temporal_unavailable'])]
    #[TestWith(['COMPLETED', 503, 'temporal_unavailable'])]
    public function test_external_failures_never_mutate_even_with_reference(string $status, int $http, string $reason): void
    {
        $this->temporal($status, $http);
        $before = IngestionSource::query()->firstOrFail()->getAttributes();
        $report = $this->assess('change:approved');
        $this->assertFalse($report['eligible']);
        $this->assertStringContainsString($reason, $report['reason']);
        try {
            $this->apply('change:approved');
            $this->fail('Invalid evidence must refuse apply');
        } catch (ManagedDeletionException $error) {
            $this->assertStringContainsString($reason, $error->getMessage());
        }
        $this->assertSame($before, IngestionSource::query()->firstOrFail()->getAttributes());
    }

    public function test_absent_history_requires_reference_and_records_exact_evidence_atomically(): void
    {
        $this->temporal('NOT_FOUND');
        $this->assertFalse($this->assess()['eligible']);
        $this->assertTrue($this->assess('change:approved')['eligible']);
        $this->assertSame('reconciled', $this->apply('change:approved')['status']);
        $source = IngestionSource::query()->firstOrFail();
        $audit = $source->metadata['managed_quiescence_reconciliation'];
        $this->assertSame('change:approved', $audit['evidence_ref']);
        $this->assertSame('writer-run', $audit['executions']['writer-workflow|writer-run']['run_id']);
        $this->assertSame(hash_file('sha256', $this->manifest), $audit['manifest_sha256']);
        $this->assertSame('closed', $source->metadata['managed_writer_gate']);
        $this->assertDatabaseCount('managed_document_outputs', 1);
        $before = $source->getAttributes();
        $this->assertSame('already_reconciled', $this->apply('change:approved')['status']);
        $this->assertSame($before, $source->fresh()->getAttributes());
        $this->assertDatabaseCount('managed_document_outputs', 1);
    }

    #[TestWith(['active'])]
    #[TestWith(['uncertain'])]
    #[TestWith(['unknown'])]
    public function test_reference_cannot_override_registered_writers(string $status): void
    {
        $this->metadata(['managed_writer_protocol' => 1, 'managed_writers' => ['writer' => ['status' => $status, 'workflow_id' => 'writer-workflow', 'run_id' => 'writer-run']]]);
        $this->assertStringContainsString('active_or_uncertain_writer', $this->assess('change:approved')['reason']);
        Http::assertNothingSent();
    }

    public function test_historical_41_and_48_shapes_require_explicit_graph_evidence(): void
    {
        $this->metadata(['managed_dispatch' => null, 'dataset' => ['qdrant_collection' => 'historic_collection', 'neo4j_namespace' => 'historic_namespace']]);
        $this->writeManifest([['document_id' => $this->target()['doc_id'], 'relative_path' => 'one.md']]);
        $this->assertFalse($this->assess('change:approved')['eligible']);
        $this->writeManifest();
        $this->temporal('NOT_FOUND');
        $this->assertTrue($this->assess('change:approved')['eligible']);
    }

    public function test_missing_artifact_root_82_shape_refuses_without_any_write(): void
    {
        File::delete($this->manifest);
        $this->assertFalse($this->assess('change:approved')['eligible']);
        $this->assertArrayNotHasKey('managed_writer_gate', IngestionSource::query()->firstOrFail()->metadata);
    }

    public function test_large_85_shape_does_not_override_active_attempt(): void
    {
        $records = [];
        for ($i = 0; $i < 1664; $i++) {
            $records[] = ['document_id' => $this->target($i.'.md')['doc_id'], 'relative_path' => $i.'.md', 'graph' => false];
        }
        $this->writeManifest($records);
        $this->metadata(['managed_writer_protocol' => 1, 'managed_target_manifest_complete' => true,
            'managed_writers' => ['previous' => ['status' => 'released'], 'current' => ['status' => 'active']]]);
        $this->assertFalse($this->assess('change:approved')['eligible']);
        $this->assertDatabaseCount('managed_document_outputs', 0);
        $this->metadata(['managed_writers' => []]);
        $this->assertSame(1664, $this->assess()['target_count']);
    }

    #[TestWith(['manifest'])]
    #[TestWith(['writer'])]
    #[TestWith(['new_attempt'])]
    #[TestWith(['source_owner'])]
    #[TestWith(['output_owner'])]
    #[TestWith(['recovery'])]
    #[TestWith(['replacement'])]
    #[TestWith(['run'])]
    public function test_evidence_change_during_lookup_refuses_before_reconciliation_writes(string $change): void
    {
        $this->temporal('COMPLETED', 200, function () use ($change): void {
            match ($change) {
                'manifest' => $this->writeManifest([['document_id' => $this->target('other.md')['doc_id'], 'relative_path' => 'other.md']]),
                'writer', 'new_attempt' => $this->metadata(['managed_writers' => ['new' => ['status' => 'active']]]),
                'source_owner' => $this->metadata(['request' => ['managed_document_id' => 'adoc_other']]),
                'output_owner' => ManagedDocumentOutput::query()->create(['document_id' => 'adoc_writer_test', 'bridge_document_id' => 'other', 'source_id' => 'source-writer', 'qdrant_collection' => 'foreign', 'active' => true]),
                'recovery' => PipelineJob::query()->firstOrFail()->update(['metadata' => ['pending_recovery' => ['state' => 'reserved']]]),
                'replacement' => ManagedDocumentDeletion::query()->create(['operation_id' => 'delete_'.str_repeat('a', 64), 'document_id' => 'adoc_writer_test', 'dataset_id' => 'writer-test', 'purpose' => 'update', 'status' => 'preparing', 'workflow_id' => 'replacement', 'workflow_input' => []]),
                'run' => $this->metadata(['temporal' => ['run_id' => 'changed']]),
            };
        });
        try {
            $this->apply();
            $this->fail('Changed evidence must refuse');
        } catch (ManagedDeletionException $error) {
            $this->assertStringContainsString('evidence_changed_during_assessment', $error->getMessage());
        }
        $this->assertArrayNotHasKey('managed_writer_gate', IngestionSource::query()->firstOrFail()->metadata);
    }

    public function test_invalid_manifest_cannot_be_overridden_and_apply_rolls_back_output_failure(): void
    {
        $this->writeManifest([['document_id' => 'foreign', 'relative_path' => '../escape']]);
        $this->assertFalse($this->assess('change:approved')['eligible']);
        $this->writeManifest();
        ManagedDocumentOutput::creating(static function (): void {
            throw new \RuntimeException('simulated storage failure');
        });
        try {
            $this->apply();
            $this->fail('Expected simulated failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('simulated storage failure', $error->getMessage());
        } finally {
            ManagedDocumentOutput::flushEventListeners();
        }
        $this->assertArrayNotHasKey('managed_writer_gate', IngestionSource::query()->firstOrFail()->metadata);
        $this->assertDatabaseCount('managed_document_outputs', 0);
    }

    public function test_manifest_checksum_and_exact_artifact_path_are_validated_before_apply(): void
    {
        $directory = $this->root.'/sources/source-writer/markdown';
        File::ensureDirectoryExists($directory);
        File::put($directory.'/one.md', 'owned content');
        $record = ['document_id' => $this->target()['doc_id'], 'relative_path' => 'one.md', 'graph' => true,
            'content_hash' => hash('sha256', 'owned content'), 'markdown_path' => $directory.'/one.md'];
        $this->writeManifest([$record]);
        $this->assertTrue($this->assess()['eligible']);
        File::put($directory.'/one.md', 'changed content');
        $this->assertFalse($this->assess('change:approved')['eligible']);
        $this->assertArrayNotHasKey('managed_writer_gate', IngestionSource::query()->firstOrFail()->metadata);
    }

    public function test_empty_manifest_cannot_substitute_for_missing_physical_target_evidence(): void
    {
        File::put($this->manifest, '[]');
        $this->assertFalse($this->assess('change:approved')['eligible']);
        $this->assertArrayNotHasKey('managed_writer_gate', IngestionSource::query()->firstOrFail()->metadata);
    }

    #[TestWith(['content_hash'])]
    #[TestWith(['markdown_path'])]
    #[TestWith(['root'])]
    #[TestWith(['hash'])]
    #[TestWith(['escape'])]
    public function test_required_artifact_evidence_cannot_be_bypassed(string $change): void
    {
        $records = json_decode(File::get($this->manifest), true);
        if ($change === 'root') {
            File::deleteDirectory($this->root.'/sources');
        } elseif ($change === 'hash') {
            $records[0]['content_hash'] = 'malformed';
        } elseif ($change === 'escape') {
            $records[0]['markdown_path'] = __FILE__;
        } else {
            unset($records[0][$change]);
        }
        File::put($this->manifest, json_encode($records));
        $this->assertFalse($this->assess('change:approved')['eligible']);
        $this->assertArrayNotHasKey('managed_writer_gate', IngestionSource::query()->firstOrFail()->metadata);
    }

    public function test_normalized_content_and_raw_checksum_are_separate(): void
    {
        $records = json_decode(File::get($this->manifest), true);
        $path = $records[0]['markdown_path'];
        $raw = "| chunk | file |\n| --- | --- |\n\n# Owned content\n";
        File::put($path, $raw);
        $records[0]['content_hash'] = hash('sha256', '# Owned content');
        File::put($this->manifest, json_encode($records));
        $this->assertTrue($this->assess()['eligible']);
        File::put($path, "\n".$raw);
        $this->assertTrue($this->assess()['eligible']);
        $records[0]['sha256'] = hash('sha256', $raw);
        File::put($this->manifest, json_encode($records));
        $this->assertFalse($this->assess()['eligible']);
        File::put($path, $raw);
        $this->assertTrue($this->assess()['eligible']);
    }
}
