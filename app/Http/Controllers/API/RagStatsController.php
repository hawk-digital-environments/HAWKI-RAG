<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class RagStatsController extends Controller
{
    public function show(): JsonResponse
    {
        $qdrantUrl = rtrim((string) config('config.qdrant_http_url', 'http://qdrant:6333'), '/');
        $neo4jUrl = rtrim((string) config('config.neo4j_http_url', 'http://hawki_rag_neo4j:7474'), '/');
        $neo4jUser = (string) config('config.neo4j_user', 'neo4j');
        $neo4jPassword = (string) config('config.neo4j_password', '');

        $qdrant = $this->fetchQdrantStats($qdrantUrl);
        $neo4j = $this->fetchNeo4jStats($neo4jUrl, $neo4jUser, $neo4jPassword);

        return response()->json([
            'ok' => true,
            'qdrant' => $qdrant,
            'neo4j' => $neo4j,
        ]);
    }

    private function fetchQdrantStats(string $baseUrl): array
    {
        try {
            $collectionsResp = Http::timeout(3)->get($baseUrl . '/collections');
            if (! $collectionsResp->successful()) {
                return ['ok' => false, 'error' => $collectionsResp->body()];
            }

            $data = $collectionsResp->json();
            $names = [];
            foreach (($data['result']['collections'] ?? []) as $col) {
                if (isset($col['name'])) {
                    $names[] = $col['name'];
                }
            }

            $counts = [];
            foreach ($names as $name) {
                $countResp = Http::timeout(3)->post($baseUrl . '/collections/' . $name . '/points/count', [
                    'exact' => true,
                ]);
                $count = null;
                if ($countResp->successful()) {
                    $count = $countResp->json()['result']['count'] ?? null;
                }
                $counts[] = ['name' => $name, 'count' => $count];
            }

            return [
                'ok' => true,
                'collections' => $counts,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function fetchNeo4jStats(string $baseUrl, string $user, string $password): array
    {
        $endpoint = $baseUrl . '/db/neo4j/tx/commit';
        $query = function (string $cypher) use ($endpoint, $user, $password) {
            return Http::timeout(4)
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
            $relsResp = $query('MATCH ()-[r]->() RETURN type(r) AS rel_type, count(r) AS count');
            $labelsResp = $query('MATCH (n) RETURN labels(n) AS labels, count(*) AS count');

            if (! $entitiesResp->successful() || ! $tripletsResp->successful()) {
                return ['ok' => false, 'error' => 'Neo4j query failed'];
            }

            $entities = $entitiesResp->json()['results'][0]['data'][0]['row'][0] ?? 0;
            $triplets = $tripletsResp->json()['results'][0]['data'][0]['row'][0] ?? 0;

            $relCounts = [];
            if ($relsResp->successful()) {
                foreach ($relsResp->json()['results'][0]['data'] ?? [] as $row) {
                    $relCounts[] = [
                        'type' => $row['row'][0] ?? null,
                        'count' => $row['row'][1] ?? 0,
                    ];
                }
            }

            $labelCounts = [];
            if ($labelsResp->successful()) {
                foreach ($labelsResp->json()['results'][0]['data'] ?? [] as $row) {
                    $labelCounts[] = [
                        'labels' => $row['row'][0] ?? [],
                        'count' => $row['row'][1] ?? 0,
                    ];
                }
            }

            return [
                'ok' => true,
                'entities' => (int) $entities,
                'triplets' => (int) $triplets,
                'relationship_types' => $relCounts,
                'labels' => $labelCounts,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
