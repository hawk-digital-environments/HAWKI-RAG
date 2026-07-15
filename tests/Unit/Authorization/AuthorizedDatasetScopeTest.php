<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Services\Authorization\Values\AuthenticatedPrincipal;
use App\Services\Authorization\Values\AuthorizedDatasetScope;
use PHPUnit\Framework\TestCase;

class AuthorizedDatasetScopeTest extends TestCase
{
    public function test_scope_serializes_only_server_derived_ready_targets_with_graph_enabled(): void
    {
        $scope = AuthorizedDatasetScope::fromStorageTargets(
            datasetId: 'dataset-a',
            qdrantCollection: 'hawki_dataset_a',
            neo4jNamespace: 'graph_dataset_a',
            embeddingModel: 'hawki-ollama-embedding',
        );

        $this->assertSame([
            'dataset_id' => 'dataset-a',
            'qdrant_collection' => 'hawki_dataset_a',
            'neo4j_namespace' => 'graph_dataset_a',
            'embedding_model' => 'hawki-ollama-embedding',
            'graph_enabled' => true,
        ], $scope->toArray());
    }

    public function test_user_principal_requires_a_persisted_identifier(): void
    {
        $principal = AuthenticatedPrincipal::tryFromUserIdentifier(42);

        $this->assertNotNull($principal);
        $this->assertSame('user', $principal->type);
        $this->assertSame('42', $principal->id);
        $this->assertNull(AuthenticatedPrincipal::tryFromUserIdentifier(null));
        $this->assertNull(AuthenticatedPrincipal::tryFromUserIdentifier('  '));
    }
}
