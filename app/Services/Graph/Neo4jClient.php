<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Services\Graph\Exceptions\Neo4jClientException;
use Psr\Log\LoggerInterface;

class Neo4jClient
{
    public function __construct(
        private readonly Neo4jQueryClient $queries,
        private readonly GraphResultNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function run(string $statement, array $parameters = [], bool $includeGraph = true): array
    {
        $payload = $this->queries->postStatements([[
            'statement' => $statement,
            'parameters' => $parameters,
            'resultDataContents' => $includeGraph ? ['row', 'graph'] : ['row'],
        ]]);

        return $this->normalizer->records($payload);
    }

    public function clearDataset(string $datasetId, string $namespace): array
    {
        abort_if($datasetId === '' || $namespace === '', 409, 'Dataset graph scope is required.');
        $scope = ['dataset_id' => $datasetId, 'neo4j_namespace' => $namespace];
        $payload = $this->queries->postStatements([
            ['statement' => 'MATCH (s)-[r]->(o) WHERE s.dataset_id=$dataset_id AND s.neo4j_namespace=$neo4j_namespace AND o.dataset_id=$dataset_id AND o.neo4j_namespace=$neo4j_namespace AND r.dataset_id=$dataset_id AND r.neo4j_namespace=$neo4j_namespace DELETE r RETURN count(r)', 'parameters' => $scope],
            ['statement' => 'MATCH (n) WHERE n.dataset_id=$dataset_id AND n.neo4j_namespace=$neo4j_namespace DELETE n RETURN count(n)', 'parameters' => $scope],
        ], requireValidEnvelope: true);

        if (($payload['errors'] ?? null) !== []
            || ! is_array($payload['results'] ?? null)
            || ! array_is_list($payload['results']) || count($payload['results']) !== 2) {
            throw Neo4jClientException::invalidClearAcknowledgement();
        }
        $counts = [];
        foreach (['count(r)', 'count(n)'] as $index => $column) {
            $result = $payload['results'][$index];
            $count = $result['data'][0]['row'][0] ?? null;
            if (($result['columns'] ?? null) !== [$column]
                || ! is_array($result['data'] ?? null) || ! array_is_list($result['data'])
                || count($result['data']) !== 1
                || ($result['data'][0]['row'] ?? null) !== [$count]
                || ! is_int($count) || $count < 0) {
                throw Neo4jClientException::invalidClearAcknowledgement();
            }
            $counts[] = $count;
        }

        return ['ok' => true, 'deleted_relationships' => $counts[0], 'deleted_nodes' => $counts[1]];
    }
}
