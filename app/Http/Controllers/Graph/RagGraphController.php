<?php

namespace App\Http\Controllers\Graph;

use App\Http\Controllers\Controller;
use App\Services\GraphService\Neo4jAdmin;
use Illuminate\Http\JsonResponse;

class RagGraphController extends Controller
{
    public function clearNeo4j(Neo4jAdmin $neo4j): JsonResponse
    {
        $result = $neo4j->clearAll();
        $status = ($result['ok'] ?? false) ? 200 : 502;

        return response()->json($result, $status);
    }
}
