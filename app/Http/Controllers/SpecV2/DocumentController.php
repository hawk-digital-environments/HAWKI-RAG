<?php
declare(strict_types=1);

namespace App\Http\Controllers\SpecV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecV2\CreateDocumentRequest;
use App\Http\Requests\SpecV2\ListHeapDocumentsRequest;
use App\Http\Requests\SpecV2\UpdateDocumentRequest;
use App\Http\Resources\SpecV2\DocumentCollection;
use App\Http\Resources\SpecV2\DocumentResource;
use App\Services\SpecV2\SpecV2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DocumentController extends Controller
{
    public function __construct(
        private readonly SpecV2Service $spec,
    ) {}

    public function index(string $heapId, ListHeapDocumentsRequest $request): JsonResponse
    {
        return response()->json(
            (new DocumentCollection($this->spec->documents->listForHeap($heapId, $request->filters(), $request->page(), $request->perPage())))
                ->resolve($request)
        );
    }

    public function store(string $heapId, CreateDocumentRequest $request): JsonResponse
    {
        $result = $this->spec->documents->create($heapId, $request->validated(), $request->header('Idempotency-Key'));
        if (! isset($result['document'])) {
            return response()->json($result['payload'] ?? ['message' => 'Document creation failed.'], $result['status']);
        }

        $payload = (new DocumentResource($result['document']))->resolve($request);
        $payload['isDuplicate'] = (bool) ($result['is_duplicate'] ?? false);

        return response()->json($payload, $result['status']);
    }

    public function update(string $documentId, UpdateDocumentRequest $request): JsonResponse
    {
        $result = $this->spec->documents->update($documentId, $request->validated(), $request->header('Idempotency-Key'));
        if (! isset($result['document'])) {
            return response()->json($result['payload'] ?? ['message' => 'Document update failed.'], $result['status']);
        }

        return response()->json((new DocumentResource($result['document']))->resolve($request), $result['status']);
    }

    public function destroy(string $documentId): Response|JsonResponse
    {
        $result = $this->spec->documents->delete($documentId, request()->header('Idempotency-Key'));
        if (($result['status'] ?? 500) !== 204) {
            return response()->json($result['payload'] ?? ['message' => 'Document deletion failed.'], $result['status']);
        }

        return response()->noContent();
    }
}
