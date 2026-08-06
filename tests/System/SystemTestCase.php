<?php

declare(strict_types=1);

namespace Tests\System;

use App\Models\Dataset;
use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use Tests\TestCase;

abstract class SystemTestCase extends TestCase
{
    protected function createSystemUser(string $username): User
    {
        return User::query()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            'ip' => '127.0.0.200',
        ]);
    }

    protected function createReadyDataset(
        string $datasetId,
        string $name,
        ?string $qdrantCollection = null,
        ?string $neo4jNamespace = null,
    ): Dataset {
        return Dataset::query()->create([
            'dataset_id' => $datasetId,
            'name' => $name,
            'description' => null,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => $qdrantCollection ?? 'hawki_'.$datasetId,
            'neo4j_namespace' => $neo4jNamespace ?? 'graph_'.$datasetId,
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
            'created_at' => now(),
        ]);
    }

    protected function grantQueryAccess(User $user, Dataset $dataset): void
    {
        app(DatasetQueryAuthorizationService::class)->grantQueryAccess($user, $dataset);
    }
}
