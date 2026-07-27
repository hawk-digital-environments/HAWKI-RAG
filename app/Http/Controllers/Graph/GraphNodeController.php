<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use App\Http\Requests\Graph\ShowGraphNodeRequest;
use App\Services\Graph\Neo4jGraphExplorer;
use Illuminate\Http\JsonResponse;

class GraphNodeController extends GraphController
{
    public function show(ShowGraphNodeRequest $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        return $this->graphResponse(
            fn (): array => $graph->graphForNode($request->nodeId(), $request->limit()),
        );
    }
}
