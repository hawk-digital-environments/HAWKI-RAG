<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use App\Http\Controllers\Controller;
use App\Services\Graph\GraphCacheService;
use App\Services\Graph\Neo4jAdmin;
use App\Services\Graph\Neo4jGraphExplorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        return $this->noStore(response()->json($graph->snapshots()));
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
        return $this->noStore(response()->json($result, ($result['ok'] ?? false) ? 200 : 404));
    }

    public function deleteSnapshot(string $id, Neo4jGraphExplorer $graph): JsonResponse
    {
        return $this->noStore(response()->json($graph->deleteSnapshot($id)));
    }

    public function clearView(): JsonResponse
    {
        return $this->noStore(response()->json(['ok' => true, 'nodes' => [], 'edges' => []]));
    }

    public function clearNeo4j(Neo4jAdmin $neo4j, GraphCacheService $cache): JsonResponse
    {
        $result = $neo4j->clearAll();
        $status = ($result['ok'] ?? false) ? 200 : 502;
        if (($result['ok'] ?? false)) {
            $result['graph_cache'] = $cache->clearBridgeCache();
            $cache->writeEmptyVisualizationSnapshot();
        }

        return $this->noStore(response()->json($result, $status));
    }

    private function graphResponse(callable $callback): JsonResponse
    {
        try {
            $result = $callback();
            return $this->noStore(response()->json($result, ($result['ok'] ?? true) ? 200 : 422));
        } catch (\Throwable $e) {
            return $this->noStore(response()->json([
                'ok' => false,
                'message' => 'Neo4j graph explorer request failed.',
                'error' => $e->getMessage(),
            ], 502));
        }
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
