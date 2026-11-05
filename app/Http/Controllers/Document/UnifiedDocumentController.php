<?php

declare(strict_types=1);

namespace App\Http\Controllers\Document;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\CreateManagedDocumentBatchRequest;
use App\Http\Requests\Document\CreateManagedDocumentRequest;
use App\Http\Requests\Document\UpdateManagedDocumentRequest;
use App\Services\Document\ManagedDocumentBoundary;
use App\Services\Document\UnifiedDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnifiedDocumentController extends Controller
{
    public function __construct(
        private readonly UnifiedDocumentService $documents,
        private readonly ManagedDocumentBoundary $boundary,
    ) {}

    public function store(CreateManagedDocumentRequest $request): JsonResponse
    {
        if ($result = $this->boundary->forward($request, 'create', $this->boundary->managedInput($request), [$request->uploadedFile()])) {
            return response()->json($result['payload'], $result['status']);
        }
        $result = $this->documents->create(
            $request->managedInput(false),
            $request->uploadedFile(),
            $request->idempotencyKey(),
        );

        return response()->json($result['payload'], $result['status']);
    }

    public function storeBatch(CreateManagedDocumentBatchRequest $request): JsonResponse
    {
        if ($result = $this->boundary->forward($request, 'batch', $this->boundary->managedInput($request, true), $request->uploadedFiles())) {
            return response()->json($result['payload'], $result['status']);
        }
        $result = $this->documents->createBatch(
            $request->managedInput(false),
            $request->uploadedFiles(),
            $request->idempotencyKey(),
        );

        return response()->json($result['payload'], $result['status']);
    }

    public function update(UpdateManagedDocumentRequest $request, string $documentId): JsonResponse
    {
        if ($result = $this->boundary->forward($request, 'update', $this->boundary->managedInput($request), [$request->uploadedFile()], $documentId)) {
            return response()->json($result['payload'], $result['status']);
        }
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

    public function destroy(Request $request, string $documentId): JsonResponse
    {
        if ($result = $this->boundary->forward($request, 'delete', documentId: $documentId)) {
            return response()->json($result['payload'], $result['status']);
        }
        $key = $request->header('Idempotency-Key');
        $result = $this->documents->delete($documentId, is_string($key) && trim($key) !== '' ? trim($key) : null);

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Document was not found.',
            ], 404);
        }

        return response()->json($result['payload'], $result['status']);
    }
}
