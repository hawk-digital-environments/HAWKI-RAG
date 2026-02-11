<?php

namespace App\Services\Graph;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Neo4jAdmin
{
    public function clearAll(): array
    {
        $neo4jUrl = rtrim((string) env('NEO4J_HTTP_URL', 'http://hawki_rag_neo4j:7474'), '/');
        $neo4jUser = (string) env('NEO4J_USER', 'neo4j');
        $neo4jPassword = (string) env('NEO4J_PASSWORD', '');
        $endpoint = $neo4jUrl . '/db/neo4j/tx/commit';

        try {
            $response = Http::timeout(8)
                ->withBasicAuth($neo4jUser, $neo4jPassword)
                ->post($endpoint, [
                    'statements' => [
                        ['statement' => 'MATCH (n) DETACH DELETE n'],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Neo4j clear failed', ['status' => $response->status(), 'body' => $response->body()]);
                return [
                    'ok' => false,
                    'message' => 'Neo4j clear failed',
                    'status' => $response->status(),
                ];
            }

            $payload = $response->json() ?: [];
            $stats = $payload['results'][0]['stats'] ?? null;

            return [
                'ok' => true,
                'stats' => $stats,
            ];
        } catch (\Throwable $e) {
            Log::warning('Neo4j clear exception', ['error' => $e->getMessage()]);
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
