<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\CreateAssistantDocumentBatchRequest;
use App\Http\Requests\Assistant\CreateAssistantDocumentRequest;
use App\Http\Requests\Assistant\UpdateAssistantDocumentRequest;
use App\Services\Assistant\AssistantDocumentService;
use Illuminate\Http\JsonResponse;

class AssistantDocumentController extends Controller
{
    public function __construct(
        private readonly AssistantDocumentService $documents,
    ) {}

    public function store(CreateAssistantDocumentRequest $request): JsonResponse
    {
        $result = $this->documents->create(
            $request->assistantInput(false),
            $request->uploadedFile(),
            $request->idempotencyKey(),
        );

        return response()->json($result['payload'], $result['status']);
    }

    public function storeBatch(CreateAssistantDocumentBatchRequest $request): JsonResponse
    {
        $result = $this->documents->createBatch(
            $request->assistantInput(false),
            $request->uploadedFiles(),
            $request->idempotencyKey(),
        );

        return response()->json($result['payload'], $result['status']);
    }

    public function show(string $assistantDocumentId): JsonResponse
    {
        $result = $this->documents->show($assistantDocumentId);

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Assistant document was not found.',
            ], 404);
        }

        return response()->json($result['payload'], $result['status']);
    }

    public function update(UpdateAssistantDocumentRequest $request, string $assistantDocumentId): JsonResponse
    {
        $result = $this->documents->update(
            $assistantDocumentId,
            $request->assistantInput(false),
            $request->uploadedFile(),
            $request->idempotencyKey(),
        );

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Assistant document was not found.',
            ], 404);
        }

        return response()->json($result['payload'], $result['status']);
    }

    public function destroy(string $assistantDocumentId): JsonResponse
    {
        $result = $this->documents->delete($assistantDocumentId, request()->header('Idempotency-Key'));

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Assistant document was not found.',
            ], 404);
        }

        return response()->json($result['payload'], $result['status']);
    }
}
