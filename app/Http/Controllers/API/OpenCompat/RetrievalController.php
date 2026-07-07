<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\OpenCompat;

use App\Http\Controllers\Controller;
use App\Services\Authorization\ApiActorResolver;
use App\Services\Authorization\GatewaySearchFilterService;
use App\Services\OpenCompat\OpenCompatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RetrievalController extends Controller
{
    public function __construct(
        private readonly OpenCompatService $compat,
    ) {}

    public function chunks(Request $request, ApiActorResolver $actors, GatewaySearchFilterService $filters): JsonResponse
    {
        $input = $this->validateQuery($request);
        $input['filters'] = $filters->build($input['filters'] ?? [], $actors->resolve($request), $input['user_identifier'] ?? null);
        unset($input['user_identifier']);

        return $this->json($this->compat->retrieveChunks($input));
    }

    public function groupedChunks(Request $request, ApiActorResolver $actors, GatewaySearchFilterService $filters): JsonResponse
    {
        $input = $this->validateQuery($request);
        $input['filters'] = $filters->build($input['filters'] ?? [], $actors->resolve($request), $input['user_identifier'] ?? null);
        unset($input['user_identifier']);

        return $this->json($this->compat->retrieveChunks($input, grouped: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateQuery(Request $request): array
    {
        $input = $request->validate([
            'query' => 'required|string|max:4000',
            'limit' => 'sometimes|integer|min:1|max:100',
            'top_k' => 'sometimes|integer|min:1|max:100',
            'k' => 'sometimes|integer|min:1|max:100',
            'filters' => 'sometimes|array',
            'user_identifier' => 'sometimes|string|max:255',
            'preferred_tags' => 'sometimes|array|max:20',
            'preferred_tags.*' => 'string|max:80',
            'fast_mode' => 'sometimes|boolean',
            'smart_lookup' => 'sometimes|boolean',
        ]);

        $limits = array_filter([
            'limit' => $input['limit'] ?? null,
            'top_k' => $input['top_k'] ?? null,
            'k' => $input['k'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        if (count(array_unique(array_map('intval', $limits))) > 1) {
            throw ValidationException::withMessages([
                'limit' => ['Provide only one search limit value. limit, top_k, and k must match when multiple aliases are sent.'],
            ]);
        }

        $input['limit'] = (int) ($input['limit'] ?? $input['top_k'] ?? $input['k'] ?? 5);
        unset($input['top_k'], $input['k']);

        return $input;
    }

    /**
     * @param array{payload: array<string, mixed>, status: int} $result
     */
    private function json(array $result): JsonResponse
    {
        return response()->json($result['payload'], $result['status']);
    }
}
