<?php

declare(strict_types=1);

namespace Tests\Feature\Graph;

use App\Services\Graph\Neo4jClient;
use App\Services\Graph\Neo4jQueryClient;
use Tests\TestCase;

class Neo4jScopedClearIntegrationTest extends TestCase
{
    public function test_real_scoped_clear_acknowledgement_counts_and_idempotent_retry(): void
    {
        $endpoint = getenv('HAWKI_EPOCH14_NEO4J_HTTP_URL');
        if (! $endpoint) {
            $this->markTestSkipped('Set HAWKI_EPOCH14_NEO4J_HTTP_URL to a disposable Neo4j service.');
        }
        $this->assertSame('127.0.0.1', parse_url($endpoint, PHP_URL_HOST));
        config()->set(['config.neo4j_http_url' => $endpoint, 'config.neo4j_database' => 'neo4j',
            'config.neo4j_user' => 'neo4j', 'config.neo4j_password' => getenv('HAWKI_EPOCH14_NEO4J_PASSWORD')]);
        $namespace = 'epoch14-fix-'.bin2hex(random_bytes(12));
        $queries = app(Neo4jQueryClient::class);
        $client = app(Neo4jClient::class);
        $scope = ['dataset_id' => 'owned-'.$namespace, 'neo4j_namespace' => $namespace];
        try {
            $queries->postStatements([['statement' => 'CREATE (a {dataset_id:$dataset_id, neo4j_namespace:$neo4j_namespace}), (b {dataset_id:$dataset_id, neo4j_namespace:$neo4j_namespace}), (a)-[:REL {dataset_id:$dataset_id, neo4j_namespace:$neo4j_namespace}]->(b)', 'parameters' => $scope],
                ['statement' => 'CREATE (n {dataset_id:$dataset_id, neo4j_namespace:$neo4j_namespace})',
                    'parameters' => ['dataset_id' => 'foreign-'.$namespace, 'neo4j_namespace' => $namespace]]]);
            $this->assertSame(['ok' => true, 'deleted_relationships' => 1, 'deleted_nodes' => 2],
                $client->clearDataset($scope['dataset_id'], $namespace));
            $this->assertSame(['ok' => true, 'deleted_relationships' => 0, 'deleted_nodes' => 0],
                $client->clearDataset($scope['dataset_id'], $namespace));
            $remaining = $queries->postStatements([['statement' => 'MATCH (n {neo4j_namespace:$neo4j_namespace}) RETURN count(n)', 'parameters' => $scope]]);
            $this->assertSame(1, $remaining['results'][0]['data'][0]['row'][0]);
        } finally {
            $queries->postStatements([['statement' => 'MATCH (n {neo4j_namespace:$neo4j_namespace}) DETACH DELETE n', 'parameters' => $scope]]);
        }
    }
}
