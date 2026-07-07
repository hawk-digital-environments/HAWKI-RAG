<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchQueryRequest;
use App\Services\Authorization\ApiActorResolver;
use App\Services\Authorization\GatewaySearchFilterService;
use App\Services\OpenCompat\OpenCompatService;
use Illuminate\Http\JsonResponse;

class SearchChunkController extends Controller
{
    public function __construct(
        private readonly OpenCompatService $chunks,
    ) {}

    public function chunks(SearchQueryRequest $request, ApiActorResolver $actors, GatewaySearchFilterService $filters): JsonResponse
    {
        $input = $request->payload();
        $input['filters'] = $filters->build($input['filters'] ?? [], $actors->resolve($request), $input['user_identifier'] ?? null);
        unset($input['user_identifier']);

        return $this->json($this->chunks->retrieveChunks($input));
    }

    public function groupedChunks(SearchQueryRequest $request, ApiActorResolver $actors, GatewaySearchFilterService $filters): JsonResponse
    {
        $input = $request->payload();
        $input['filters'] = $filters->build($input['filters'] ?? [], $actors->resolve($request), $input['user_identifier'] ?? null);
        unset($input['user_identifier']);

        return $this->json($this->chunks->retrieveChunks($input, grouped: true));
    }

    /**
     * @param array{payload: array<string, mixed>, status: int} $result
     */
    private function json(array $result): JsonResponse
    {
        return response()->json($result['payload'], $result['status']);
    }
}
