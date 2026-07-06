<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\OpenCompat;

use App\Http\Controllers\Controller;
use App\Services\Authorization\ApplicationReadPolicy;
use App\Services\Authorization\ApiActorResolver;
use App\Services\Authorization\GatewaySearchFilterService;
use App\Services\OpenCompat\OpenCompatDocumentService;
use App\Services\OpenCompat\OpenCompatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetrievalController extends Controller
{
    public function __construct(
        private readonly OpenCompatService $compat,
        private readonly OpenCompatDocumentService $documents,
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

    public function docs(Request $request, ApiActorResolver $actors, GatewaySearchFilterService $filters): JsonResponse
    {
        $input = $this->validateQuery($request);
        $input['filters'] = $filters->build($input['filters'] ?? [], $actors->resolve($request), $input['user_identifier'] ?? null);
        unset($input['user_identifier']);

        return $this->json($this->documents->retrieveDocs($input));
    }

    public function searchDocuments(Request $request, ApiActorResolver $actors, ApplicationReadPolicy $policy): JsonResponse
    {
        $input = $request->validate([
            'query' => 'sometimes|string|max:1000',
            'q' => 'sometimes|string|max:1000',
            'filename' => 'sometimes|string|max:255',
            'limit' => 'sometimes|integer|min:1|max:250',
            'user_identifier' => 'sometimes|string|max:255',
        ]);
        $input['scope_filters'] = $policy->documentScope(
            $actors->resolve($request),
            $input['user_identifier'] ?? null,
        )->repositoryFilters;
        unset($input['user_identifier']);

        return $this->json($this->documents->searchDocuments($input));
    }

    public function batchDocuments(Request $request, ApiActorResolver $actors, ApplicationReadPolicy $policy): JsonResponse
    {
        $input = $request->validate([
            'document_ids' => 'sometimes|array|max:250',
            'document_ids.*' => 'string|max:255',
            'documentIds' => 'sometimes|array|max:250',
            'documentIds.*' => 'string|max:255',
            'ids' => 'sometimes|array|max:250',
            'ids.*' => 'string|max:255',
            'user_identifier' => 'sometimes|string|max:255',
        ]);
        $input['scope_filters'] = $policy->documentScope(
            $actors->resolve($request),
            $input['user_identifier'] ?? null,
        )->repositoryFilters;
        unset($input['user_identifier']);

        return $this->json($this->documents->batchDocuments($input));
    }

    public function batchChunks(Request $request, ApiActorResolver $actors, GatewaySearchFilterService $filters): JsonResponse
    {
        $input = $request->validate([
            'query' => 'sometimes|string|max:4000',
            'top_k' => 'sometimes|integer|min:1|max:100',
            'k' => 'sometimes|integer|min:1|max:100',
            'chunk_ids' => 'sometimes|array|max:250',
            'chunk_ids.*' => 'string|max:255',
            'chunkIds' => 'sometimes|array|max:250',
            'chunkIds.*' => 'string|max:255',
            'filters' => 'sometimes|array',
            'user_identifier' => 'sometimes|string|max:255',
        ]);
        $input['filters'] = $filters->build($input['filters'] ?? [], $actors->resolve($request), $input['user_identifier'] ?? null);
        unset($input['user_identifier']);

        return $this->json($this->compat->batchChunks($input));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateQuery(Request $request): array
    {
        return $request->validate([
            'query' => 'required|string|max:4000',
            'top_k' => 'sometimes|integer|min:1|max:100',
            'k' => 'sometimes|integer|min:1|max:100',
            'filters' => 'sometimes|array',
            'user_identifier' => 'sometimes|string|max:255',
            'preferred_tags' => 'sometimes|array|max:20',
            'preferred_tags.*' => 'string|max:80',
            'fast_mode' => 'sometimes|boolean',
            'smart_lookup' => 'sometimes|boolean',
        ]);
    }

    /**
     * @param array{payload: array<string, mixed>, status: int} $result
     */
    private function json(array $result): JsonResponse
    {
        return response()->json($result['payload'], $result['status']);
    }
}
