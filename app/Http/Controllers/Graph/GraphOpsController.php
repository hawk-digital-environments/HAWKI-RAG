<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use App\Models\User;
use App\Services\Graph\GraphOpsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GraphOpsController extends GraphController
{
    public function overview(Request $request, GraphOpsService $ops): JsonResponse
    {
        return $this->read($request, $ops, 'overview');
    }

    public function search(Request $request, GraphOpsService $ops): JsonResponse
    {
        return $this->read($request, $ops, 'search');
    }

    public function semanticSearch(Request $request, GraphOpsService $ops): JsonResponse
    {
        return $this->read($request, $ops, 'semantic-search');
    }

    public function node(Request $request, GraphOpsService $ops): JsonResponse
    {
        return $this->read($request, $ops, 'node');
    }

    public function expand(Request $request, GraphOpsService $ops): JsonResponse
    {
        return $this->read($request, $ops, 'expand');
    }

    private function read(Request $request, GraphOpsService $ops, string $action): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $rules = ['dataset_id' => ['required', 'string', 'max:191'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.match ($action) {
                'overview' => '300', 'expand', 'node' => '250', 'semantic-search' => '25', default => '50',
            }]];
        if (in_array($action, ['search', 'semantic-search'], true)) {
            $rules['q'] = ['required', 'string', 'max:4000'];
        }
        if (in_array($action, ['node', 'expand'], true)) {
            $rules['node_id'] = ['required', 'string', 'max:4096'];
        }
        if ($action === 'expand') {
            $rules['depth'] = ['sometimes', 'integer'];
        }
        $data = $request->validate($rules);
        if (isset($data['depth'])) {
            $data['depth'] = max(1, min(3, (int) $data['depth']));
        }

        return $this->graphResponse(fn (): array => $ops->read($user, $action, $data));
    }
}
