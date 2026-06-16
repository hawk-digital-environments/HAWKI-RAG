<?php

declare(strict_types=1);

namespace App\Services\Rag;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class RagStatsService
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(): array
    {
        $qdrantUrl = rtrim((string) $this->config->get('config.qdrant_http_url', 'http://qdrant:6333'), '/');
        $neo4jUrl = rtrim((string) $this->config->get('config.neo4j_http_url', 'http://hawki_rag_neo4j:7474'), '/');
        $neo4jUser = (string) $this->config->get('config.neo4j_user', 'neo4j');
        $neo4jPassword = (string) $this->config->get('config.neo4j_password', '');
        $neo4jDatabase = trim((string) $this->config->get('config.neo4j_database', 'neo4j')) ?: 'neo4j';

        return [
            'ok' => true,
            'qdrant' => $this->fetchQdrantStats($qdrantUrl),
            'neo4j' => $this->fetchNeo4jStats($neo4jUrl, $neo4jDatabase, $neo4jUser, $neo4jPassword),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteQdrantCollection(string $collection): array
    {
        $name = trim($collection);
        if ($name === '') {
            return [
                'ok' => false,
                'message' => 'Collection name is required.',
            ];
        }

        $qdrantUrl = rtrim((string) $this->config->get('config.qdrant_http_url', 'http://qdrant:6333'), '/');

        try {
            $response = $this->http->timeout(10)->delete($qdrantUrl.'/collections/'.rawurlencode($name));
            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'collection' => $name,
                    'message' => "Qdrant returned HTTP {$response->status()} while deleting {$name}.",
                    'error' => $response->body(),
                ];
            }

            return [
                'ok' => true,
                'collection' => $name,
                'message' => "Deleted Qdrant collection {$name}.",
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'collection' => $name,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchQdrantStats(string $baseUrl): array
    {
        try {
            $collectionsResp = $this->http->timeout(3)->get($baseUrl.'/collections');
            if (! $collectionsResp->successful()) {
                return ['ok' => false, 'error' => $collectionsResp->body()];
            }

            $names = [];
            foreach (($collectionsResp->json('result.collections') ?? []) as $collection) {
                if (isset($collection['name'])) {
                    $names[] = $collection['name'];
                }
            }

            $counts = [];
            foreach ($names as $name) {
                $countResp = $this->http->timeout(3)->post($baseUrl.'/collections/'.$name.'/points/count', [
                    'exact' => true,
                ]);
                $counts[] = [
                    'name' => $name,
                    'count' => $countResp->successful() ? ($countResp->json('result.count') ?? null) : null,
                ];
            }

            return [
                'ok' => true,
                'collections' => $counts,
            ];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchNeo4jStats(string $baseUrl, string $database, string $user, string $password): array
    {
        $endpoint = $baseUrl.'/db/'.rawurlencode($database).'/tx/commit';
        $query = function (string $cypher) use ($endpoint, $user, $password) {
            return $this->http->timeout(4)
                ->withBasicAuth($user, $password)
                ->post($endpoint, [
                    'statements' => [
                        ['statement' => $cypher],
                    ],
                ]);
        };

        try {
            $entitiesResp = $query('MATCH (n:Entity) RETURN count(n) AS c');
            $tripletsResp = $query('MATCH (:Entity)-[r:REL]->(:Entity) RETURN count(r) AS c');
            $entityLabelResp = $query('MATCH (n:Entity) RETURN count(n) AS c');
            $relTypeResp = $query('MATCH (:Entity)-[r:REL]->(:Entity) RETURN count(r) AS c');
            $relsResp = $query('MATCH (:Entity)-[r:REL]->(:Entity) RETURN type(r) AS rel_type, count(r) AS count');
            $labelsResp = $query('MATCH (n:Entity) RETURN labels(n) AS labels, count(*) AS count');

            if (! $entitiesResp->successful() || ! $tripletsResp->successful()) {
                return ['ok' => false, 'error' => 'Neo4j query failed'];
            }

            return [
                'ok' => true,
                'entities' => (int) ($entitiesResp->json('results.0.data.0.row.0') ?? 0),
                'triplets' => (int) ($tripletsResp->json('results.0.data.0.row.0') ?? 0),
                'entity_label_count' => $entityLabelResp->successful()
                    ? (int) ($entityLabelResp->json('results.0.data.0.row.0') ?? 0)
                    : 0,
                'rel_type_count' => $relTypeResp->successful()
                    ? (int) ($relTypeResp->json('results.0.data.0.row.0') ?? 0)
                    : 0,
                'relationship_types' => $this->neo4jRows($relsResp->successful() ? ($relsResp->json('results.0.data') ?? []) : [], 'type'),
                'labels' => $this->neo4jRows($labelsResp->successful() ? ($labelsResp->json('results.0.data') ?? []) : [], 'labels'),
            ];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function neo4jRows(array $rows, string $nameKey): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                $nameKey => $row['row'][0] ?? ($nameKey === 'labels' ? [] : null),
                'count' => $row['row'][1] ?? 0,
            ];
        }

        return $items;
    }
}
