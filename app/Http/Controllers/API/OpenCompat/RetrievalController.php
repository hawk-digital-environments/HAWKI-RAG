<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\OpenCompat;

use App\Http\Controllers\Controller;
use App\Services\Authorization\AuthorizationService;
use App\Services\OpenCompat\OpenCompatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetrievalController extends Controller
{
    public function __construct(private readonly OpenCompatService $compat) {}

    public function chunks(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $input = $this->validateQuery($request);

        return $this->json($this->compat->retrieveChunks($input, $authorization->retrievalContextFor($request->user())));
    }

    public function groupedChunks(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $input = $this->validateQuery($request);

        return $this->json($this->compat->retrieveChunks($input, $authorization->retrievalContextFor($request->user()), grouped: true));
    }

    public function docs(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $input = $this->validateQuery($request);

        return $this->json($this->compat->retrieveDocs($input, $authorization->retrievalContextFor($request->user())));
    }

    public function searchDocuments(Request $request): JsonResponse
    {
        $input = $request->validate([
            'query' => 'sometimes|string|max:1000',
            'q' => 'sometimes|string|max:1000',
            'filename' => 'sometimes|string|max:255',
            'limit' => 'sometimes|integer|min:1|max:250',
        ]);

        return $this->json($this->compat->searchDocuments($input));
    }

    public function batchDocuments(Request $request): JsonResponse
    {
        $input = $request->validate([
            'document_ids' => 'sometimes|array|max:250',
            'document_ids.*' => 'string|max:255',
            'documentIds' => 'sometimes|array|max:250',
            'documentIds.*' => 'string|max:255',
            'ids' => 'sometimes|array|max:250',
            'ids.*' => 'string|max:255',
        ]);

        return $this->json($this->compat->batchDocuments($input));
    }

    public function batchChunks(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $input = $request->validate([
            'query' => 'sometimes|string|max:4000',
            'top_k' => 'sometimes|integer|min:1|max:100',
            'k' => 'sometimes|integer|min:1|max:100',
            'chunk_ids' => 'sometimes|array|max:250',
            'chunk_ids.*' => 'string|max:255',
            'chunkIds' => 'sometimes|array|max:250',
            'chunkIds.*' => 'string|max:255',
        ]);

        return $this->json($this->compat->batchChunks($input, $authorization->retrievalContextFor($request->user())));
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
