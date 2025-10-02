<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\SemanticQdrantSearch;
use Illuminate\Http\Request;

class QdrantTestController extends Controller
{
    protected SemanticQdrantSearch $svc;

    public function __construct(SemanticQdrantSearch $svc)
    {
        $this->svc = $svc;
    }

    /**
     * Quick JSON test endpoint for semantic search.
     * POST /api/qdrant-test { "query": "...", "top_k": 5, "filters": { "source_format": "markdown" } }
     */
    public function test(Request $request)
    {
        $data = $request->validate([
            'query'   => 'required|string',
            'top_k'   => 'sometimes|integer|min:1|max:50',
            'filters' => 'sometimes|array',
        ]);

        $query   = $data['query'];
        $topK    = $data['top_k']   ?? 5;
        $filters = $data['filters'] ?? [];

        $t0 = microtime(true);
        $result = $this->svc->search($query, $topK, $filters);

        return response()->json([
            'ok'         => true,
            'elapsed_ms' => round((microtime(true) - $t0) * 1000, 2),
            'count'      => count($result),
            'result'     => $result,
        ]);
    }
}
