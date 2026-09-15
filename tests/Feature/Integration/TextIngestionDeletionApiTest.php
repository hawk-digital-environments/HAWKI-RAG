<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Models\User;
use App\Services\TextIngestion\TextIngestionIdentifierFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class TextIngestionDeletionApiTest extends TestCase
{
    use RefreshDatabase;

    private string $sharedRoot;

    private string $token;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sharedRoot = storage_path('framework/testing/text-ingestion-deletion');
        File::deleteDirectory($this->sharedRoot);
        config()->set([
            'temporal.storage.shared_root' => $this->sharedRoot,
            'temporal.enabled' => true,
            'config.qdrant_http_url' => 'http://qdrant.test:6333',
        ]);
        $this->user = User::query()->create([
            'username' => 'text-deletion-api',
            'email' => 'text-deletion-api@example.test',
            'ip' => '127.0.0.61',
        ]);
        $this->token = $this->user
            ->createToken('text-deletion-api', ['rag:text-ingest'])
            ->plainTextToken;
        $dataset = Dataset::query()->create([
            'dataset_id' => 'delete_text_e2e',
            'name' => 'Delete text E2E',
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_delete_text_e2e',
            'neo4j_namespace' => 'hawki_delete_text_e2e',
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
            'created_at' => now(),
        ]);
        DatasetGrant::query()->create([
            'dataset_id' => $dataset->dataset_id,
            'principal_type' => DatasetGrant::PRINCIPAL_USER,
            'principal_id' => (string) $this->user->getAuthIdentifier(),
            'permission' => DatasetGrant::PERMISSION_INGEST,
        ]);
        $this->fakeDownstreamServices();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sharedRoot);

        parent::tearDown();
    }

    public function test_ready_direct_text_is_deleted_and_replay_is_idempotent(): void
    {
        $ingestion = $this->ingest();
        $sourceId = (string) $ingestion->json('source_id');
        $artifactPath = (string) PipelineJob::query()->firstOrFail()->local_path;
        $source = IngestionSource::query()->where('source_id', $sourceId)->firstOrFail();
        $source->forceFill([
            'index_status' => IngestionSource::STATUS_READY,
            'ready_at' => now(),
        ])->save();

        $this->deleteRequest($sourceId)
            ->assertOk()
            ->assertExactJson([
                'source_id' => $sourceId,
                'dataset_id' => 'delete_text_e2e',
                'status' => 'deleted',
                'deleted' => true,
                'replayed' => false,
            ]);

        $source->refresh();
        $this->assertSame(IngestionSource::STATUS_DELETED, $source->index_status);
        $this->assertNull($source->ready_at);
        $this->assertNull($source->markdown_storage_path);
        $this->assertFileDoesNotExist($artifactPath);
        $this->assertDatabaseCount('pipeline_tasks', 1);
        $this->assertDatabaseCount('pipeline_jobs', 1);
        $this->assertDatabaseCount('ingestion_sources', 1);
        $this->assertSame(PipelineJob::STATUS_RUNNING, PipelineJob::query()->firstOrFail()->status);
        $this->assertSame(PipelineTask::STATUS_RUNNING, PipelineTask::query()->firstOrFail()->status);

        Http::assertSent(function ($request) use ($sourceId): bool {
            $expectedDocumentId = app(TextIngestionIdentifierFactory::class)->documentId($sourceId);

            return $request->url() === 'http://qdrant.test:6333/collections/hawki_delete_text_e2e/points/delete?wait=true'
                && data_get($request->data(), 'filter.must.0.match.value') === $expectedDocumentId
                && data_get($request->data(), 'filter.must.1.match.value') === $sourceId
                && data_get($request->data(), 'filter.must.2.match.value') === 'delete_text_e2e';
        });
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'cancel-and-wait'));

        $sentBeforeReplay = count(Http::recorded());
        $this->deleteRequest($sourceId)
            ->assertOk()
            ->assertJsonPath('replayed', true);
        $this->assertCount($sentBeforeReplay, Http::recorded());
    }

    public function test_running_ingestion_is_stopped_before_vectors_and_artifacts_are_deleted(): void
    {
        $ingestion = $this->ingest();
        $sourceId = (string) $ingestion->json('source_id');

        $this->deleteRequest($sourceId)->assertOk();

        $urls = Http::recorded()
            ->map(static fn (array $record): string => $record[0]->url())
            ->all();
        $cancelIndex = array_search(
            'http://hawki_rag_bridge/temporal/workflows/cancel-and-wait',
            $urls,
            true,
        );
        $deleteIndex = array_search(
            'http://qdrant.test:6333/collections/hawki_delete_text_e2e/points/delete?wait=true',
            $urls,
            true,
        );
        $this->assertIsInt($cancelIndex);
        $this->assertIsInt($deleteIndex);
        $this->assertLessThan($deleteIndex, $cancelIndex);
        $this->assertSame(IngestionSource::STATUS_DELETED, IngestionSource::query()->firstOrFail()->index_status);
    }

    public function test_deletion_waits_when_temporal_startup_is_still_unconfirmed(): void
    {
        $ingestion = $this->ingest();
        $sourceId = (string) $ingestion->json('source_id');
        PipelineJob::query()->firstOrFail()->forceFill([
            'current_stage' => 'temporal.workflow_starting',
            'temporal_run_id' => null,
        ])->save();
        $sentBeforeDelete = count(Http::recorded());

        $this->deleteRequest($sourceId)
            ->assertConflict()
            ->assertJsonPath('error', 'text_ingestion_source_busy');

        $this->assertCount($sentBeforeDelete, Http::recorded());
        $this->assertSame(IngestionSource::STATUS_RUNNING, IngestionSource::query()->firstOrFail()->index_status);
    }

    public function test_deletion_requires_a_token_and_the_source_dataset_grant(): void
    {
        $sourceId = 'source_'.str_repeat('b', 32);
        IngestionSource::query()->create([
            'source_id' => $sourceId,
            'source_url' => 'external://protected-document',
            'task_id' => null,
            'dataset_id' => 'delete_text_e2e',
            'index_status' => IngestionSource::STATUS_READY,
            'metadata' => ['ingestion_mode' => 'direct_text'],
        ]);
        $other = User::query()->create([
            'username' => 'no-delete-grant',
            'email' => 'no-delete-grant@example.test',
            'ip' => '127.0.0.62',
        ]);

        $this->deleteRequest($sourceId, token: null)->assertUnauthorized();
        $this->deleteRequest(
            $sourceId,
            token: $other->createToken('no-grant', ['rag:text-ingest'])->plainTextToken,
        )
            ->assertNotFound()
            ->assertJsonPath('error', 'dataset_not_found');

        $this->assertNotSame(
            IngestionSource::STATUS_DELETED,
            IngestionSource::query()->firstOrFail()->index_status,
        );
    }

    public function test_deletion_rejects_a_token_without_the_exact_ability(): void
    {
        $sourceId = 'source_'.str_repeat('d', 32);
        IngestionSource::query()->create([
            'source_id' => $sourceId,
            'source_url' => 'external://wrong-ability',
            'task_id' => null,
            'dataset_id' => 'delete_text_e2e',
            'index_status' => IngestionSource::STATUS_READY,
            'metadata' => ['ingestion_mode' => 'direct_text'],
        ]);

        $this->deleteRequest(
            $sourceId,
            token: $this->user->createToken('wrong-ability', ['rag:query'])->plainTextToken,
        )->assertForbidden();

        $this->assertSame(IngestionSource::STATUS_READY, IngestionSource::query()->firstOrFail()->index_status);
        Http::assertNothingSent();
    }

    public function test_non_direct_source_is_not_exposed_through_the_integration_route(): void
    {
        IngestionSource::query()->create([
            'source_id' => 'source_'.str_repeat('a', 32),
            'source_url' => 'https://example.test/file.pdf',
            'task_id' => null,
            'dataset_id' => 'delete_text_e2e',
            'index_status' => IngestionSource::STATUS_READY,
            'metadata' => [],
        ]);

        $this->deleteRequest('source_'.str_repeat('a', 32))
            ->assertNotFound()
            ->assertJsonPath('error', 'text_ingestion_not_found');
    }

    public function test_old_ingestion_replay_does_not_resurrect_a_deleted_source(): void
    {
        $ingestion = $this->ingest();
        $sourceId = (string) $ingestion->json('source_id');
        IngestionSource::query()->where('source_id', $sourceId)->update([
            'index_status' => IngestionSource::STATUS_READY,
            'ready_at' => now(),
        ]);
        $this->deleteRequest($sourceId)->assertOk();
        $sentBeforeReplay = count(Http::recorded());

        $this->ingest()
            ->assertOk()
            ->assertJsonPath('status', IngestionSource::STATUS_DELETED)
            ->assertJsonPath('replayed', true);

        $this->assertCount($sentBeforeReplay, Http::recorded());
        $this->assertSame(IngestionSource::STATUS_DELETED, IngestionSource::query()->firstOrFail()->index_status);
    }

    public function test_qdrant_failure_keeps_artifacts_and_same_key_retry_completes_deletion(): void
    {
        Http::swap(new HttpFactory);
        Http::fake([
            '*temporal/workflows/ingest-text' => static fn ($request) => Http::response([
                'workflow_id' => $request->data()['workflow_id'],
                'run_id' => 'run-delete-text',
            ], 202),
            'http://qdrant.test:6333/*' => Http::sequence()
                ->push(['status' => 'error'], 503)
                ->push(['result' => ['status' => 'completed'], 'status' => 'ok']),
        ]);
        $ingestion = $this->ingest();
        $sourceId = (string) $ingestion->json('source_id');
        $artifactPath = (string) PipelineJob::query()->firstOrFail()->local_path;
        IngestionSource::query()->where('source_id', $sourceId)->update([
            'index_status' => IngestionSource::STATUS_READY,
            'ready_at' => now(),
        ]);
        $this->deleteRequest($sourceId)
            ->assertStatus(502)
            ->assertJsonPath('error', 'text_ingestion_deletion_failed');

        $source = IngestionSource::query()->where('source_id', $sourceId)->firstOrFail();
        $this->assertSame(IngestionSource::STATUS_DELETING, $source->index_status);
        $this->assertTrue($source->metadata['text_deletion']['workflow_closed']);
        $this->assertFileExists($artifactPath);

        $this->deleteRequest($sourceId)
            ->assertOk()
            ->assertJsonPath('status', IngestionSource::STATUS_DELETED);

        $this->assertFileDoesNotExist($artifactPath);
        $this->assertSame(IngestionSource::STATUS_DELETED, $source->refresh()->index_status);
    }

    public function test_deletion_requires_an_idempotency_key(): void
    {
        $sourceId = 'source_'.str_repeat('c', 32);
        IngestionSource::query()->create([
            'source_id' => $sourceId,
            'source_url' => 'external://missing-key',
            'task_id' => null,
            'dataset_id' => 'delete_text_e2e',
            'index_status' => IngestionSource::STATUS_READY,
            'metadata' => ['ingestion_mode' => 'direct_text'],
        ]);

        $this->withToken($this->token)
            ->deleteJson('/api/integrations/text-ingestions/'.$sourceId)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(IngestionSource::STATUS_READY, IngestionSource::query()->firstOrFail()->index_status);
        Http::assertNothingSent();
    }

    private function fakeDownstreamServices(): void
    {
        Http::fake([
            '*temporal/workflows/ingest-text' => static fn ($request) => Http::response([
                'workflow_id' => $request->data()['workflow_id'],
                'run_id' => 'run-delete-text',
            ], 202),
            '*temporal/workflows/cancel-and-wait' => Http::response(['ok' => true]),
            'http://qdrant.test:6333/*' => Http::response([
                'result' => ['status' => 'completed'],
                'status' => 'ok',
            ]),
        ]);
    }

    private function ingest(): TestResponse
    {
        return $this->withToken($this->token)->postJson(
            '/api/integrations/text-ingestions',
            [
                'external_document_id' => 'delete-document-1',
                'dataset_id' => 'delete_text_e2e',
                'text' => "# Delete me\n\nThis content must disappear.",
                'content_format' => 'markdown',
                'metadata' => ['test' => 'deletion'],
            ],
            ['Idempotency-Key' => 'create-delete-document-1'],
        );
    }

    private function deleteRequest(
        string $sourceId,
        ?string $token = 'default',
    ): TestResponse {
        $request = $this;
        if ($token !== null) {
            $request = $this->withToken($token === 'default' ? $this->token : $token);
        } else {
            $request = $this->withoutHeader('Authorization');
        }

        return $request->deleteJson(
            '/api/integrations/text-ingestions/'.$sourceId,
            [],
            ['Idempotency-Key' => 'delete-document-1'],
        );
    }
}
