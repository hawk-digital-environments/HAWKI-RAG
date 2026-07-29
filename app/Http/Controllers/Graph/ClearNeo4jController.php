<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use App\Services\Graph\GraphCacheService;
use App\Services\Graph\Neo4jAdmin;
use Illuminate\Http\JsonResponse;

class ClearNeo4jController extends GraphController
{
    public function __invoke(Neo4jAdmin $neo4j, GraphCacheService $cache): JsonResponse
    {
        $result = $neo4j->clearAll();
        $status = ($result['ok'] ?? false) ? 200 : 502;
        if (($result['ok'] ?? false)) {
            $result['graph_cache'] = $cache->clearBridgeCache();
            $cache->writeEmptyVisualizationSnapshot();
        }

        return $this->noStore(response()->json($result, $status));
    }
}
