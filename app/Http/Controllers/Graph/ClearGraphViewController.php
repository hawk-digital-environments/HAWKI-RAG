<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use Illuminate\Http\JsonResponse;

class ClearGraphViewController extends GraphController
{
    public function __invoke(): JsonResponse
    {
        return $this->noStore(response()->json([
            'ok' => true,
            'nodes' => [],
            'edges' => [],
        ]));
    }
}
