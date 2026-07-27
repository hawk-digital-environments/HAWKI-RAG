<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use App\Http\Requests\Graph\ExpandGraphNodeRequest;
use App\Services\Graph\Neo4jGraphExplorer;
use Illuminate\Http\JsonResponse;

class GraphExpansionController extends GraphController
{
    public function __invoke(ExpandGraphNodeRequest $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        return $this->graphResponse(fn (): array => $graph->expand(
            $request->nodeId(),
            $request->depth(),
            $request->limit(),
        ));
    }
}
