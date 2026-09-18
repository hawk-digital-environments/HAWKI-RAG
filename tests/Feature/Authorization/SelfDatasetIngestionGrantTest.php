<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Dataset;
use App\Models\DatasetGrantToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SelfDatasetIngestionGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_dataset_issues_a_one_time_grant_token(): void
    {
        $response = $this->postJson('/api/datasets', ['dataset_id' => 'token-issue-dataset']);

        $response->assertCreated()
            ->assertJsonStructure(['success', 'dataset_id', 'dataset', 'grant_token']);

        $token = $response->json('grant_token');
        $this->assertMatchesRegularExpression('/^dgt_[0-9a-f]{64}$/', $token);

        // Only the hash is persisted; the plaintext never is.
        $this->assertDatabaseHas('dataset_grant_tokens', [
            'dataset_id' => 'token-issue-dataset',
            'token_hash' => hash('sha256', $token),
            'consumed_at' => null,
        ]);

        // A compatible replay never re-issues the creator's token.
        $this->postJson('/api/datasets', ['dataset_id' => 'token-issue-dataset'])
            ->assertOk()
            ->assertJsonMissing(['grant_token']);
    }

    public function test_failed_token_issuance_rolls_back_dataset_creation_and_retry_succeeds(): void
    {
        // The token service is final, so issuance is failed for real: the
        // underlying table is renamed away to make its INSERT blow up.
        Schema::rename('dataset_grant_tokens', 'dataset_grant_tokens_broken');

        $this->postJson('/api/datasets', ['dataset_id' => 'rollback-dataset'])
            ->assertStatus(500);

        $this->assertDatabaseMissing('datasets', ['dataset_id' => 'rollback-dataset']);
        $this->assertDatabaseCount('dataset_grant_tokens_broken', 0);

        Schema::rename('dataset_grant_tokens_broken', 'dataset_grant_tokens');

        $response = $this->postJson('/api/datasets', ['dataset_id' => 'rollback-dataset']);

        $response->assertCreated()
            ->assertJsonStructure(['success', 'dataset_id', 'dataset', 'grant_token']);

        $token = $response->json('grant_token');
        $this->assertMatchesRegularExpression('/^dgt_[0-9a-f]{64}$/', $token);

        $this->assertDatabaseHas('dataset_grant_tokens', [
            'dataset_id' => 'rollback-dataset',
            'token_hash' => hash('sha256', $token),
            'consumed_at' => null,
        ]);
    }

    public function test_text_ingestion_token_can_redeem_the_grant_token(): void
    {
        $user = $this->user('self-ingest-grant');
        $token = $this->createDatasetViaHttp('self-ingest-grant-dataset');
        $this->authenticateWithAbilities($user, ['rag:text-ingest']);

        $this->postJson('/api/datasets/self-ingest-grant-dataset/ingest-grants/self', ['grant_token' => $token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('dataset_id', 'self-ingest-grant-dataset')
            ->assertJsonPath('ingest_access.granted', true)
            ->assertJsonPath('ingest_access.permission', 'ingest')
            ->assertJsonPath('replayed', false);

        $this->assertDatabaseHas('dataset_grants', [
            'dataset_id' => 'self-ingest-grant-dataset',
            'principal_type' => 'user',
            'principal_id' => (string) $user->getAuthIdentifier(),
            'permission' => 'ingest',
        ]);
        $this->assertDatabaseHas('dataset_grant_tokens', [
            'dataset_id' => 'self-ingest-grant-dataset',
            'token_hash' => hash('sha256', $token),
        ]);
        $this->assertNotNull(
            DatasetGrantToken::query()->where('token_hash', hash('sha256', $token))->value('consumed_at'),
        );

        // Already-held grants replay idempotently without any token.
        $this->postJson('/api/datasets/self-ingest-grant-dataset/ingest-grants/self')
            ->assertOk()
            ->assertJsonPath('replayed', true);

        $this->assertDatabaseCount('dataset_grants', 1);
    }

    public function test_self_grant_without_token_is_rejected_for_ungranted_callers(): void
    {
        $dataset = $this->dataset('strict-dataset');
        $this->authenticateWithAbilities($this->user('strict-user'), ['rag:text-ingest']);

        $this->postJson("/api/datasets/{$dataset->dataset_id}/ingest-grants/self")
            ->assertForbidden()
            ->assertJsonPath('error', 'grant_token_required');

        $this->assertDatabaseCount('dataset_grants', 0);
    }

    public function test_redeeming_a_consumed_token_for_another_user_is_rejected(): void
    {
        $token = $this->createDatasetViaHttp('consumed-token-dataset');
        $this->authenticateWithAbilities($this->user('token-winner'), ['rag:text-ingest']);

        $this->postJson('/api/datasets/consumed-token-dataset/ingest-grants/self', ['grant_token' => $token])
            ->assertOk();

        $this->authenticateWithAbilities($this->user('token-loser'), ['rag:text-ingest']);

        $this->postJson('/api/datasets/consumed-token-dataset/ingest-grants/self', ['grant_token' => $token])
            ->assertForbidden()
            ->assertJsonPath('error', 'grant_token_invalid');

        $this->assertDatabaseCount('dataset_grants', 1);
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = $this->createDatasetViaHttp('expired-token-dataset');
        DatasetGrantToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->update(['expires_at' => now()->modify('-1 hour')]);
        $this->authenticateWithAbilities($this->user('late-redeemer'), ['rag:text-ingest']);

        $this->postJson('/api/datasets/expired-token-dataset/ingest-grants/self', ['grant_token' => $token])
            ->assertForbidden()
            ->assertJsonPath('error', 'grant_token_invalid');

        $this->assertDatabaseCount('dataset_grants', 0);
    }

    public function test_token_for_another_dataset_is_rejected(): void
    {
        $foreignToken = $this->createDatasetViaHttp('foreign-token-dataset');
        $dataset = $this->dataset('target-dataset');
        $this->authenticateWithAbilities($this->user('cross-redeemer'), ['rag:text-ingest']);

        $this->postJson("/api/datasets/{$dataset->dataset_id}/ingest-grants/self", ['grant_token' => $foreignToken])
            ->assertForbidden()
            ->assertJsonPath('error', 'grant_token_invalid');

        $this->assertDatabaseCount('dataset_grants', 0);
    }

    public function test_token_without_text_ingestion_ability_cannot_self_grant_ingest(): void
    {
        $dataset = $this->dataset('no-ingest-ability-dataset');
        $this->authenticateWithAbilities($this->user('no-ingest-ability'), ['query']);

        $this->postJson("/api/datasets/{$dataset->dataset_id}/ingest-grants/self", ['grant_token' => 'dgt_'.str_repeat('a', 64)])
            ->assertForbidden()
            ->assertJsonPath('message', 'This token cannot ingest text.');

        $this->assertDatabaseCount('dataset_grants', 0);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $dataset = $this->dataset('unauthenticated-dataset');

        $this->postJson("/api/datasets/{$dataset->dataset_id}/ingest-grants/self", ['grant_token' => 'dgt_'.str_repeat('b', 64)])
            ->assertUnauthorized();

        $this->assertDatabaseCount('dataset_grants', 0);
    }

    public function test_missing_dataset_does_not_disclose_or_create_a_grant(): void
    {
        $this->authenticateWithAbilities($this->user('missing-ingest'), ['rag:text-ingest']);

        $this->postJson('/api/datasets/missing-dataset/ingest-grants/self', ['grant_token' => 'dgt_'.str_repeat('c', 64)])
            ->assertNotFound()
            ->assertJsonPath('error', 'dataset_not_found');

        $this->assertDatabaseCount('dataset_grants', 0);
    }

    public function test_inactive_dataset_is_not_grantable(): void
    {
        $dataset = $this->dataset('inactive-dataset');
        $dataset->update(['status' => Dataset::STATUS_ARCHIVED]);
        $this->authenticateWithAbilities($this->user('inactive-grant'), ['rag:text-ingest']);

        $this->postJson("/api/datasets/{$dataset->dataset_id}/ingest-grants/self", ['grant_token' => 'dgt_'.str_repeat('d', 64)])
            ->assertNotFound()
            ->assertJsonPath('error', 'dataset_not_found');

        $this->assertDatabaseCount('dataset_grants', 0);
    }

    private function user(string $username): User
    {
        return User::query()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            // The users.ip column is unique; derive a distinct one per user.
            'ip' => '127.0.'.random_int(0, 255).'.'.random_int(1, 254),
        ]);
    }

    /**
     * Creates a dataset through the anonymous HTTP endpoint and returns the
     * one-time grant token issued to the creator.
     */
    private function createDatasetViaHttp(string $datasetId): string
    {
        $response = $this->postJson('/api/datasets', ['dataset_id' => $datasetId]);

        $this->assertSame(201, $response->status(), 'Dataset creation did not return 201: '.$response->getContent());

        return (string) $response->json('grant_token');
    }

    /**
     * @param  list<string>  $abilities
     */
    private function authenticateWithAbilities(User $user, array $abilities): void
    {
        $token = $user->createToken('self-ingest-grant-test', $abilities)->plainTextToken;
        // The sanctum guard caches its resolved user across requests within
        // one test; forget it so the next request re-resolves the new token.
        $this->app->make(\Illuminate\Auth\AuthManager::class)->forgetGuards();
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
}
