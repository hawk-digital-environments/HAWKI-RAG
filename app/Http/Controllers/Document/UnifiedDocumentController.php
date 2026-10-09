<?php

declare(strict_types=1);

namespace App\Http\Controllers\Document;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\CreateManagedDocumentBatchRequest;
use App\Http\Requests\Document\CreateManagedDocumentRequest;
use App\Http\Requests\Document\UpdateManagedDocumentRequest;
use App\Services\Document\UnifiedDocumentService;
use Illuminate\Http\JsonResponse;

class UnifiedDocumentController extends Controller
{
    public function __construct(
        private readonly UnifiedDocumentService $documents,
        private readonly \App\Services\Document\Repositories\ManagedDocumentRepository $managed,
        private readonly \App\Services\Authorization\DatasetIngestionAuthorizationService $authorization,
    ) {
    }

    public function store(CreateManagedDocumentRequest $request): JsonResponse
    {
        $result = $this->documents->create(
            $request->managedInput(false),
            $request->uploadedFile(),
            $request->idempotencyKey(),
        );

        return response()->json($result['payload'], $result['status']);
    }

    public function storeBatch(CreateManagedDocumentBatchRequest $request): JsonResponse
    {
        $result = $this->documents->createBatch(
            $request->managedInput(false),
            $request->uploadedFiles(),
            $request->idempotencyKey(),
        );

        return response()->json($result['payload'], $result['status']);
    }

    public function update(UpdateManagedDocumentRequest $request, string $documentId): JsonResponse
    {
        $this->authorizeMutation($documentId);
        $result = $this->documents->update(
            $documentId,
            $request->managedInput(false),
            $request->uploadedFile(),
            $request->idempotencyKey(),
        );

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Document was not found.',
            ], 404);
        }

        return response()->json($result['payload'], $result['status']);
    }

    public function destroy(string $documentId): JsonResponse
    {
        $this->authorizeMutation($documentId);
        $result = $this->documents->delete($documentId, request()->header('Idempotency-Key'));

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Document was not found.',
            ], 404);
        }

        return response()->json($result['payload'], $result['status']);
    }
    private function authorizeMutation(string $documentId): void
    {
        $document = $this->managed->find($documentId);
        abort_if($document === null, 404, 'Document was not found.');
        $this->authorization->authorize(request()->user(), $document->dataset_id);
    }
}
