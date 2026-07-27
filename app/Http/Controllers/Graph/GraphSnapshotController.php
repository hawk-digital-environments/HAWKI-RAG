<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use App\Http\Requests\Graph\SaveGraphSnapshotRequest;
use App\Services\Graph\Neo4jGraphExplorer;
use Illuminate\Http\JsonResponse;

class GraphSnapshotController extends GraphController
{
    public function index(Neo4jGraphExplorer $graph): JsonResponse
    {
        return $this->noStore(response()->json($graph->snapshots()));
    }

    public function store(SaveGraphSnapshotRequest $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        return response()->json($graph->saveSnapshot($request->snapshot()));
    }

    public function show(string $id, Neo4jGraphExplorer $graph): JsonResponse
    {
        $result = $graph->loadSnapshot($id);

        return $this->noStore(response()->json($result, ($result['ok'] ?? false) ? 200 : 404));
    }

    public function destroy(string $id, Neo4jGraphExplorer $graph): JsonResponse
    {
        return $this->noStore(response()->json($graph->deleteSnapshot($id)));
    }
}
