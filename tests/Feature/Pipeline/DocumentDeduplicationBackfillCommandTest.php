<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Models\DocumentDeduplicationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentDeduplicationBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $qdrantStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => $this->qdrantStatus === 200
            ? Http::response(['result' => ['count' => 1]], 200)
            : Http::response(['status' => ['error' => 'collection missing']], $this->qdrantStatus));
    }

    public function test_default_run_reports_verified_candidates_without_writing(): void
    {
        $this->insertVerifiedManagedDocument('managed-dry-run', 'collection-dry-run', str_repeat('a', 64));

        $exitCode = Artisan::call('pipeline:deduplication-backfill');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dry-run only; no deduplication state will be written.', $output);
        $this->assertStringContainsString('Would seed (dry-run)', $output);
        $this->assertStringContainsString('Dry-run passed.', $output);
        $this->assertDatabaseCount('document_deduplication_states', 0);
    }

    public function test_apply_seeds_raw_source_hash_and_is_idempotent_across_chunks(): void
    {
        $firstHash = str_repeat('a', 64);
        $secondHash = str_repeat('c', 64);
        $this->insertVerifiedManagedDocument('managed-first', 'collection-first', $firstHash);
        $this->insertVerifiedManagedDocument('managed-second', 'collection-second', $secondHash);

        $this->assertSame(0, Artisan::call('pipeline:deduplication-backfill', [
            '--apply' => true,
            '--chunk' => 1,
        ]));

        $this->assertDatabaseCount('document_deduplication_states', 2);
        $state = DocumentDeduplicationState::query()
            ->where('scope_key', 'collection-first')
            ->where('document_id', 'managed-first')
            ->firstOrFail();
        $this->assertSame($firstHash, $state->completed_content_hash);
        $this->assertSame(DocumentDeduplicationState::STATUS_COMPLETED, $state->status);
        $this->assertNull($state->decision);
        $this->assertNull($state->pending_content_hash);
        $this->assertNull($state->claim_token);
        $this->assertSame('validated_managed_upload', $state->metadata['backfill']['strategy']);
        $this->assertSame('managed_documents.source_checksum_sha256', $state->metadata['backfill']['source_hash_field']);
        $this->assertSame(['pipeline_jobs.content_hash'], $state->metadata['backfill']['server_source_hash_evidence']);

        $this->assertSame(0, Artisan::call('pipeline:deduplication-backfill', ['--apply' => true]));
        $rerunOutput = Artisan::output();

        $this->assertDatabaseCount('document_deduplication_states', 2);
        $this->assertStringContainsString('Already seeded', $rerunOutput);
    }

    public function test_existing_conflicting_state_is_preserved_exactly(): void
    {
        $this->insertVerifiedManagedDocument('managed-conflict', 'collection-conflict', str_repeat('a', 64));
        $state = DocumentDeduplicationState::query()->create([
            'scope_key' => 'collection-conflict',
            'document_id' => 'managed-conflict',
            'completed_content_hash' => str_repeat('f', 64),
            'pending_content_hash' => str_repeat('e', 64),
            'status' => DocumentDeduplicationState::STATUS_PROCESSING,
            'decision' => DocumentDeduplicationState::DECISION_UPDATED,
            'claim_token' => 'existing-claim',
            'metadata' => ['owner' => 'existing-workflow'],
        ]);
        $before = $state->fresh()->getRawOriginal();

        $this->assertSame(0, Artisan::call('pipeline:deduplication-backfill', ['--apply' => true]));
        $output = Artisan::output();

        $this->assertSame($before, $state->fresh()->getRawOriginal());
        $this->assertStringContainsString('Existing non-matching states were preserved.', $output);
        $this->assertStringContainsString('existing_state_preserved', $output);
    }

    public function test_unverified_managed_and_registry_records_are_deferred(): void
    {
        $this->insertVerifiedManagedDocument(
            documentId: 'managed-mismatch',
            collection: 'collection-mismatch',
            sourceHash: str_repeat('a', 64),
            outputOverrides: ['source_id' => 'stale-source'],
        );
        DB::table('ingested_pages')->insert([
            'collection' => 'legacy-collection',
            'source_identity_hash' => str_repeat('1', 64),
            'source_identity' => 'doc:legacy-page',
            'doc_id' => 'legacy-page',
            'content_hash' => str_repeat('2', 64),
            'status' => 'completed',
            'chunks_count' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('pipeline:deduplication-backfill', ['--apply' => true]));
        $output = Artisan::output();

        $this->assertDatabaseCount('document_deduplication_states', 0);
        $this->assertStringContainsString('output_source_mismatch', $output);
        $this->assertStringContainsString('registry_source_bytes_unavailable', $output);
        $this->assertStringContainsString('will establish byte-level state on a future pipeline run', $output);
    }

    public function test_missing_qdrant_collection_prevents_an_otherwise_valid_seed(): void
    {
        $this->qdrantStatus = 404;
        $this->insertVerifiedManagedDocument(
            'managed-missing-vectors',
            'collection-missing-vectors',
            str_repeat('a', 64),
        );

        $this->assertSame(0, Artisan::call('pipeline:deduplication-backfill', ['--apply' => true]));
        $output = Artisan::output();

        $this->assertDatabaseCount('document_deduplication_states', 0);
        $this->assertStringContainsString('qdrant_collection_missing', $output);
    }

    public function test_missing_server_owned_source_hash_prevents_an_otherwise_valid_seed(): void
    {
        $this->insertVerifiedManagedDocument(
            documentId: 'managed-missing-source-evidence',
            collection: 'collection-missing-source-evidence',
            sourceHash: str_repeat('a', 64),
            includeServerChecksum: false,
        );

        $this->assertSame(0, Artisan::call('pipeline:deduplication-backfill', ['--apply' => true]));
        $output = Artisan::output();

        $this->assertDatabaseCount('document_deduplication_states', 0);
        $this->assertStringContainsString('server_source_checksum_unavailable', $output);
    }

    public function test_invalid_chunk_size_fails_before_any_write(): void
    {
        $this->insertVerifiedManagedDocument('managed-invalid-chunk', 'collection-invalid-chunk', str_repeat('a', 64));

        $exitCode = Artisan::call('pipeline:deduplication-backfill', [
            '--apply' => true,
            '--chunk' => 0,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('must be between 1 and 1000', Artisan::output());
        $this->assertDatabaseCount('document_deduplication_states', 0);
    }

    /**
     * @param  array<string, mixed>  $documentOverrides
     * @param  array<string, mixed>  $outputOverrides
     */
    private function insertVerifiedManagedDocument(
        string $documentId,
        string $collection,
        string $sourceHash,
        array $documentOverrides = [],
        array $outputOverrides = [],
        bool $includeServerChecksum = true,
    ): void {
        $sourceId = 'source-'.$documentId;
        $taskId = 'task-'.$documentId;
        $jobId = 'job-'.$documentId;
        $timestamp = now()->subMinute();

        DB::table('datasets')->insert([
            'dataset_id' => 'dataset-'.$documentId,
            'name' => $documentId,
            'qdrant_collection' => $collection,
            'neo4j_namespace' => 'neo4j-'.$documentId,
            'created_at' => $timestamp,
        ]);

        DB::table('managed_documents')->insert(array_merge([
            'document_id' => $documentId,
            'dataset_id' => 'dataset-'.$documentId,
            'display_name' => $documentId.'.pdf',
            'source_type' => 'upload',
            'source_checksum_sha256' => $sourceHash,
            'status' => 'indexed',
            'latest_source_id' => $sourceId,
            'latest_task_id' => $taskId,
            'latest_job_id' => $jobId,
            'indexed_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], $documentOverrides));

        if ($includeServerChecksum) {
            DB::table('pipeline_jobs')->insert([
                'job_id' => $jobId,
                'task_id' => $taskId,
                'source_id' => $sourceId,
                'job_type' => 'ingest',
                'status' => 'completed',
                'content_hash' => $sourceHash,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        DB::table('managed_document_outputs')->insert(array_merge([
            'document_id' => $documentId,
            'bridge_document_id' => 'bridge-'.$documentId,
            'qdrant_collection' => $collection,
            'source_id' => $sourceId,
            'task_id' => $taskId,
            'job_id' => $jobId,
            // Converted output hashes are validation evidence, never the source deduplication key.
            'content_hash' => str_repeat('b', 64),
            'chunk_count' => 2,
            'status' => 'indexed',
            'active' => true,
            'indexed_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], $outputOverrides));
    }
}
