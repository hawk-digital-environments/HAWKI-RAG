<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use App\Http\Requests\Graph\SearchGraphRequest;
use App\Services\Graph\Neo4jGraphExplorer;
use Illuminate\Http\JsonResponse;

class GraphSearchController extends GraphController
{
    public function __invoke(SearchGraphRequest $request, Neo4jGraphExplorer $graph): JsonResponse
    {
        return $this->graphResponse(
            fn (): array => $graph->searchEntities($request->queryText(), $request->limit()),
        );
    }
}
