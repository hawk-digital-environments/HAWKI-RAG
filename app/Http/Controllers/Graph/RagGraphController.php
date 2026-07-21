<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use App\Http\Controllers\Controller;
use App\Http\Requests\Graph\ExpandGraphNodeRequest;
use App\Http\Requests\Graph\SaveGraphSnapshotRequest;
use App\Http\Requests\Graph\SearchGraphRequest;
use App\Http\Requests\Graph\SemanticDatasetGraphSearchRequest;
use App\Http\Requests\Graph\ShowGraphNodeRequest;
use App\Http\Requests\Graph\ShowGraphOverviewRequest;
use App\Models\User;
use App\Services\Graph\GraphCacheService;
use App\Services\Graph\Neo4jAdmin;
use App\Services\Graph\Neo4jGraphExplorer;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RagGraphController extends Controller
{
    public function overview(ShowGraphOverviewRequest $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        return $this->graphResponse(fn () => $graph->overview($request->limit()));
    }

    public function search(SearchGraphRequest $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        return $this->graphResponse(fn () => $graph->searchEntities($request->queryText(), $request->limit()));
    }

    public function semanticSearch(SemanticDatasetGraphSearchRequest $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->noStore(response()->json([
                'message' => 'Authentication is required.',
            ], 401));
        }

        return $this->graphResponse(fn () => $graph->semanticSearch(
            $user,
            $request->datasetId(),
            $request->queryText(),
            $request->limit(),
        ));
    }

    public function expand(ExpandGraphNodeRequest $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        return $this->graphResponse(fn () => $graph->expand(
            $request->nodeId(),
            $request->depth(),
            $request->limit(),
        ));
    }

    public function node(ShowGraphNodeRequest $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        return $this->graphResponse(fn () => $graph->graphForNode($request->nodeId(), $request->limit()));
    }

    public function snapshots(Neo4jGraphExplorer $graph): JsonResponse
    {
        return $this->noStore(response()->json($graph->snapshots()));
    }

    public function saveSnapshot(SaveGraphSnapshotRequest $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        return response()->json($graph->saveSnapshot($request->snapshot()));
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
            $status = (int) ($result['status'] ?? (($result['ok'] ?? true) ? 200 : 422));

            return $this->noStore(response()->json($result, $status));
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $e) {
            report($e);

            return $this->noStore(response()->json([
                'ok' => false,
                'message' => 'Neo4j graph explorer request failed.',
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
