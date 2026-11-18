<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use App\Services\Graph\GraphOpsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClearNeo4jController extends GraphController
{
    public function __invoke(Request $request, GraphOpsService $ops): JsonResponse
    {
        $data = $request->validate(['dataset_id' => ['required', 'string', 'max:191'],
            'confirm' => ['required', 'in:CLEAR_DATASET_GRAPH']]);

        return $this->graphResponse(fn (): array => $ops->clear($data));
    }
}
