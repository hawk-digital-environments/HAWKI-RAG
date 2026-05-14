<?php

namespace App\Http\Controllers\Graph;

use App\Http\Controllers\Controller;
use App\Services\GraphService\Neo4jAdmin;
use App\Services\GraphService\Neo4jGraphExplorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class RagGraphController extends Controller
{
    public function overview(Request $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        return $this->graphResponse(fn() => $graph->overview((int) $request->integer('limit', 80)));
    }

    public function search(Request $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|max:200',
            'limit' => 'sometimes|integer|min:1|max:50',
        ]);

        return $this->graphResponse(fn() => $graph->searchEntities($data['q'], (int) ($data['limit'] ?? 12)));
    }

    public function semanticSearch(Request $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|max:500',
            'limit' => 'sometimes|integer|min:1|max:25',
        ]);

        return $this->graphResponse(fn() => $graph->semanticSearch($data['q'], (int) ($data['limit'] ?? 8)));
    }

    public function expand(Request $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:255',
            'depth' => 'sometimes|integer|min:1|max:3',
            'limit' => 'sometimes|integer|min:5|max:250',
        ]);

        return $this->graphResponse(fn() => $graph->expand(
            $data['node_id'],
            (int) ($data['depth'] ?? 1),
            (int) ($data['limit'] ?? 80)
        ));
    }

    public function node(Request $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:255',
            'limit' => 'sometimes|integer|min:5|max:250',
        ]);

        return $this->graphResponse(fn() => $graph->graphForNode($data['node_id'], (int) ($data['limit'] ?? 80)));
    }

    public function snapshots(Neo4jGraphExplorer $graph): JsonResponse
    {
        return response()->json($graph->snapshots());
    }

    public function saveSnapshot(Request $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|nullable|string|max:120',
            'scene' => 'required|array',
        ]);

        return response()->json($graph->saveSnapshot($data));
    }

    public function loadSnapshot(string $id, Neo4jGraphExplorer $graph): JsonResponse
    {
        $result = $graph->loadSnapshot($id);
        return response()->json($result, ($result['ok'] ?? false) ? 200 : 404);
    }

    public function deleteSnapshot(string $id, Neo4jGraphExplorer $graph): JsonResponse
    {
        return response()->json($graph->deleteSnapshot($id));
    }

    public function clearView(): JsonResponse
    {
        return response()->json(['ok' => true, 'nodes' => [], 'edges' => []]);
    }

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

    private function graphResponse(callable $callback): JsonResponse
    {
        try {
            $result = $callback();
            return response()->json($result, ($result['ok'] ?? true) ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Neo4j graph explorer request failed.',
                'error' => $e->getMessage(),
            ], 502);
        }
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
            'limit' => null,
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
