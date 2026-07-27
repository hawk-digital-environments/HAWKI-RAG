<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use App\Http\Requests\Graph\ShowGraphOverviewRequest;
use App\Services\Graph\Neo4jGraphExplorer;
use Illuminate\Http\JsonResponse;

class GraphOverviewController extends GraphController
{
    public function index(ShowGraphOverviewRequest $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        return $this->graphResponse(fn (): array => $graph->overview($request->limit()));
    }
}
