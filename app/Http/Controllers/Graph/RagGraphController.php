<?php

namespace App\Http\Controllers\Graph;

use App\Http\Controllers\Controller;
use App\Services\GraphService\Neo4jAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class RagGraphController extends Controller
{
    public function clearNeo4j(Neo4jAdmin $neo4j): JsonResponse
    {
        $result = $neo4j->clearAll();
        $status = ($result['ok'] ?? false) ? 200 : 502;
        if (($result['ok'] ?? false)) {
            $result['graph_cache'] = $this->clearGraphCache();
            $this->writeGraphSnapshot($this->emptyGraphSnapshot());
        }

        return response()->json($result, $status);
    }

    private function clearGraphCache(): array
    {
        $baseUrl = rtrim((string) config('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge:8000'), '/');

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->post($baseUrl . '/graph/cache/clear');

            if ($response->failed()) {
                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'message' => 'Python RAG bridge failed to clear graph cache.',
                ];
            }

            return $response->json() ?? ['ok' => true];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function writeGraphSnapshot(array $snapshot): void
    {
        $path = public_path('neo4j_graph_visualization.json');
        $payload = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        if (File::exists($path) && ! is_writable($path)) {
            File::delete($path);
        }

        File::put($path, $payload);
        @chmod($path, 0666);
    }

    private function emptyGraphSnapshot(): array
    {
        return [
            'ok' => true,
            'generated_at' => now()->toIso8601String(),
            'limit' => 250,
            'node_count' => 0,
            'relationship_count' => 0,
            'recent_doc_id' => null,
            'recent_relationship_count' => 0,
            'document_count' => 0,
            'nodes' => [],
            'links' => [],
        ];
    }
}
