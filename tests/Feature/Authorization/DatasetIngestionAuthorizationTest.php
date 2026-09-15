<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DatasetIngestionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_idempotently_grants_ingestion_access_to_one_dataset(): void
    {
        $user = $this->user('grant-ingestion');
        $dataset = $this->dataset('assistant_granted');

        $arguments = [
            'dataset_id' => $dataset->dataset_id,
            'user_id' => (string) $user->getAuthIdentifier(),
        ];
        $this->artisan('dataset:grant-ingest', $arguments)->assertSuccessful();
        $this->artisan('dataset:grant-ingest', $arguments)->assertSuccessful();

        $this->assertDatabaseCount('dataset_grants', 1);
        $this->assertDatabaseHas('dataset_grants', [
            'dataset_id' => $dataset->dataset_id,
            'principal_type' => DatasetGrant::PRINCIPAL_USER,
            'principal_id' => (string) $user->getAuthIdentifier(),
            'permission' => DatasetGrant::PERMISSION_INGEST,
        ]);
    }

    public function test_query_grant_does_not_authorize_ingestion(): void
    {
        $user = $this->user('query-only');
        $dataset = $this->dataset('assistant_query_only');
        DatasetGrant::query()->create([
            'dataset_id' => $dataset->dataset_id,
            'principal_type' => DatasetGrant::PRINCIPAL_USER,
            'principal_id' => (string) $user->getAuthIdentifier(),
            'permission' => DatasetGrant::PERMISSION_QUERY,
        ]);

        $this->withToken($user->createToken('ingest', ['rag:text-ingest'])->plainTextToken)
            ->postJson('/api/integrations/text-ingestions', [
                'external_document_id' => 'query-grant-document',
                'dataset_id' => $dataset->dataset_id,
                'text' => 'Query access must not imply write access.',
                'content_format' => 'plain_text',
            ], ['Idempotency-Key' => 'query-grant-document-v1'])
            ->assertNotFound()
            ->assertJsonPath('error', 'dataset_not_found');
    }

    private function user(string $username): User
    {
        return User::query()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            'ip' => '127.0.0.70',
        ]);
    }

    private function dataset(string $datasetId): Dataset
    {
        return Dataset::query()->create([
            'dataset_id' => $datasetId,
            'name' => $datasetId,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_'.$datasetId,
            'neo4j_namespace' => 'hawki_'.$datasetId,
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
            'created_at' => now(),
        ]);
    }
}
