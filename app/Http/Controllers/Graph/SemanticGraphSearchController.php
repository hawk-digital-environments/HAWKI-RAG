<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use App\Http\Requests\Graph\SemanticDatasetGraphSearchRequest;
use App\Models\User;
use App\Services\Graph\Neo4jGraphExplorer;
use Illuminate\Http\JsonResponse;

class SemanticGraphSearchController extends GraphController
{
    public function __invoke(
        SemanticDatasetGraphSearchRequest $request,
        Neo4jGraphExplorer $graph,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->noStore(response()->json([
                'message' => 'Authentication is required.',
            ], 401));
        }

        return $this->graphResponse(fn (): array => $graph->semanticSearch(
            $user,
            $request->datasetId(),
            $request->queryText(),
            $request->limit(),
        ));
    }
}
