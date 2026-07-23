<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Dataset;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SelfDatasetQueryGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('config.admin_auth.bypass', false);
        config()->set('config.query_auth.development_bypass', false);
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
    }

    public function test_admin_query_principal_can_idempotently_grant_ready_dataset_to_self(): void
    {
        $user = $this->user('self-grant');
        $dataset = $this->readyDataset('self-grant-ready');
        $this->authenticateWithAbilities($user, ['admin', 'query']);
        Http::fake([
            'http://qdrant.test/*' => Http::response(['result' => ['count' => 3]], 200),
        ]);

        $this->postJson("/api/datasets/{$dataset->dataset_id}/query-grants/self")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('dataset_id', $dataset->dataset_id)
            ->assertJsonPath('query_access.granted', true)
            ->assertJsonPath('query_access.permission', 'query');

        $this->postJson("/api/datasets/{$dataset->dataset_id}/query-grants/self")
            ->assertOk();

        $this->assertDatabaseCount('dataset_grants', 1);
        $this->assertDatabaseHas('dataset_grants', [
            'dataset_id' => $dataset->dataset_id,
            'principal_type' => 'user',
            'principal_id' => (string) $user->getAuthIdentifier(),
            'permission' => 'query',
        ]);

        $this->getJson('/api/query/datasets')
            ->assertOk()
            ->assertJsonPath('datasets.0.dataset_id', $dataset->dataset_id);
    }

    public function test_query_only_token_cannot_create_a_dataset_grant(): void
    {
        $user = $this->user('query-only');
        $dataset = $this->readyDataset('query-only-dataset');
        $this->authenticateWithAbilities($user, ['query']);

        $this->postJson("/api/datasets/{$dataset->dataset_id}/query-grants/self")
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Admin authentication required.');

        $this->assertDatabaseCount('dataset_grants', 0);
    }

    public function test_admin_only_token_cannot_create_a_dataset_grant(): void
    {
        $user = $this->user('admin-only');
        $dataset = $this->readyDataset('admin-only-dataset');
        $this->authenticateWithAbilities($user, ['admin']);

        $this->postJson("/api/datasets/{$dataset->dataset_id}/query-grants/self")
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->assertDatabaseCount('dataset_grants', 0);
    }

    public function test_first_grant_fails_closed_until_ingestion_and_vectors_are_ready(): void
    {
        $user = $this->user('not-ready');
        $dataset = $this->dataset('not-ready-dataset');
        $this->authenticateWithAbilities($user, ['admin', 'query']);
        Http::fake([
            'http://qdrant.test/*' => Http::response(['result' => ['count' => 0]], 200),
        ]);

        $this->postJson("/api/datasets/{$dataset->dataset_id}/query-grants/self")
            ->assertStatus(409)
            ->assertJsonPath('error', 'dataset_not_ready');

        $this->assertDatabaseCount('dataset_grants', 0);
        Http::assertNothingSent();
    }

    public function test_first_grant_requires_physical_qdrant_points(): void
    {
        $user = $this->user('no-vectors');
        $dataset = $this->readyDataset('no-vectors-dataset');
        $this->authenticateWithAbilities($user, ['admin', 'query']);
        Http::fake([
            'http://qdrant.test/*' => Http::response(['result' => ['count' => 0]], 200),
        ]);

        $this->postJson("/api/datasets/{$dataset->dataset_id}/query-grants/self")
            ->assertStatus(409)
            ->assertJsonPath('error', 'dataset_not_ready');

        $this->assertDatabaseCount('dataset_grants', 0);
        Http::assertSentCount(1);
    }

    public function test_missing_dataset_does_not_disclose_or_create_a_grant(): void
    {
        $this->authenticateWithAbilities($this->user('missing'), ['admin', 'query']);

        $this->postJson('/api/datasets/missing-dataset/query-grants/self')
            ->assertNotFound()
            ->assertJsonPath('error', 'dataset_not_found');

        $this->assertDatabaseCount('dataset_grants', 0);
    }

    private function user(string $username): User
    {
        return User::query()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            'ip' => '127.0.0.90',
        ]);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function authenticateWithAbilities(User $user, array $abilities): void
    {
        $token = $user->createToken('self-grant-test', $abilities)->plainTextToken;
        $this->withToken($token);
    }

    private function dataset(string $datasetId): Dataset
    {
        return Dataset::query()->create([
            'dataset_id' => $datasetId,
            'name' => str_replace('-', ' ', ucfirst($datasetId)),
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_'.$datasetId,
            'neo4j_namespace' => 'graph_'.$datasetId,
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
            'created_at' => now(),
        ]);
    }

    private function readyDataset(string $datasetId): Dataset
    {
        $dataset = $this->dataset($datasetId);
        $task = PipelineTask::query()->create([
            'task_id' => 'task-'.$datasetId,
            'dataset_id' => $datasetId,
            'status' => PipelineTask::STATUS_COMPLETED,
            'finished_at' => now(),
            'metadata' => [],
        ]);
        PipelineJob::query()->create([
            'job_id' => 'ingest-'.$datasetId,
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_COMPLETED,
            'finished_at' => now(),
            'metadata' => [],
        ]);

        return $dataset;
    }
}
