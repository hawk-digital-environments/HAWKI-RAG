<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class DatasetAuthorizationGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_dataset_gate_allows_an_active_dataset_with_an_explicit_grant(): void
    {
        $user = $this->createUser('granted');
        $dataset = $this->createDataset('granted');
        $this->grant($user, $dataset);

        $this->assertTrue(Gate::forUser($user)->allows('query-dataset', $dataset->dataset_id));
    }

    public function test_query_dataset_gate_denies_an_active_dataset_without_an_explicit_grant(): void
    {
        $user = $this->createUser('ungranted');
        $dataset = $this->createDataset('ungranted');

        $this->assertFalse(Gate::forUser($user)->allows('query-dataset', $dataset->dataset_id));
    }

    public function test_all_datasets_are_authorized_by_default_when_enabled(): void
    {
        config()->set('config.query_auth.all_datasets_by_default', true);
        $user = $this->createUser('default-all-datasets');

        $existingDataset = $this->createDataset('default-existing');
        $this->assertTrue(Gate::forUser($user)->allows('query-dataset', $existingDataset->dataset_id));

        $futureDataset = $this->createDataset('default-future');
        $this->assertTrue(Gate::forUser($user)->allows('query-dataset', $futureDataset->dataset_id));
        $this->assertDatabaseCount('dataset_grants', 0);
    }

    public function test_default_all_datasets_access_cannot_query_inactive_datasets(): void
    {
        config()->set('config.query_auth.all_datasets_by_default', true);
        $user = $this->createUser('all-inactive');
        $dataset = $this->createDataset('all-inactive', Dataset::STATUS_ARCHIVED);

        $this->assertFalse(Gate::forUser($user)->allows('query-dataset', $dataset->dataset_id));
    }

    public function test_query_dataset_gate_denies_an_inactive_dataset_even_with_an_explicit_grant(): void
    {
        $user = $this->createUser('inactive-dataset');
        $dataset = $this->createDataset('inactive-dataset', Dataset::STATUS_ARCHIVED);
        $this->grant($user, $dataset);

        $this->assertFalse(Gate::forUser($user)->allows('query-dataset', $dataset->dataset_id));
    }

    public function test_query_dataset_gate_denies_a_removed_user_even_with_an_explicit_grant(): void
    {
        $user = $this->createUser('removed-user');
        $dataset = $this->createDataset('removed-user');
        $this->grant($user, $dataset);
        $user->update(['isRemoved' => true]);

        $this->assertFalse(Gate::forUser($user)->allows('query-dataset', $dataset->dataset_id));
    }

    public function test_query_dataset_gate_does_not_treat_storage_readiness_as_authorization(): void
    {
        $user = $this->createUser('not-ready');
        $dataset = $this->createDataset('not-ready', qdrantCollection: '');
        $this->grant($user, $dataset);

        $this->assertTrue(Gate::forUser($user)->allows('query-dataset', $dataset->dataset_id));
    }

    private function createUser(string $suffix): User
    {
        return User::query()->create([
            'username' => 'gate-'.$suffix,
            'email' => 'gate-'.$suffix.'@example.test',
            'ip' => 'gate-'.$suffix,
        ]);
    }

    private function createDataset(
        string $datasetId,
        string $status = Dataset::STATUS_ACTIVE,
        ?string $qdrantCollection = null,
    ): Dataset {
        return Dataset::query()->create([
            'dataset_id' => $datasetId,
            'name' => 'Dataset '.$datasetId,
            'description' => null,
            'status' => $status,
            'qdrant_collection' => $qdrantCollection ?? 'qdrant_'.$datasetId,
            'neo4j_namespace' => 'neo4j_'.$datasetId,
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
            'created_at' => now(),
        ]);
    }

    private function grant(User $user, Dataset $dataset): void
    {
        DatasetGrant::query()->create([
            'dataset_id' => $dataset->dataset_id,
            'principal_type' => DatasetGrant::PRINCIPAL_USER,
            'principal_id' => (string) $user->getAuthIdentifier(),
            'permission' => DatasetGrant::PERMISSION_QUERY,
        ]);
    }
}
