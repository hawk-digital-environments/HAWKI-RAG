<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\OpenCompat;

use App\Http\Controllers\Controller;
use App\Services\OpenCompat\OpenCompatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(private readonly OpenCompatService $compat) {}

    public function list(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:250',
            'dataset_id' => 'sometimes|string|max:255',
            'datasetId' => 'sometimes|string|max:255',
            'q' => 'sometimes|string|max:1000',
            'search' => 'sometimes|string|max:1000',
        ]);

        return $this->json($this->compat->listDocuments($filters));
    }

    public function show(string $documentId): JsonResponse
    {
        return $this->json($this->compat->showDocument($documentId));
    }

    public function status(string $documentId): JsonResponse
    {
        return $this->json($this->compat->documentStatus($documentId));
    }

    public function byFilename(string $filename): JsonResponse
    {
        return $this->json($this->compat->byFilename($filename));
    }

    public function downloadUrl(string $documentId): JsonResponse
    {
        return $this->json($this->compat->downloadUrl($documentId));
    }

    public function updateText(string $documentId, Request $request): JsonResponse
    {
        $input = $request->validate([
            'text' => 'required|string',
            'metadata' => 'sometimes|array',
            'collection' => 'sometimes|string|max:255',
            'graph' => 'sometimes|boolean',
        ]);

        return $this->json($this->compat->updateDocumentText($documentId, $input, $request->header('Idempotency-Key')));
    }

    public function updateFile(): JsonResponse
    {
        return $this->json($this->compat->unsupported(
            'documents/update_file',
            'RAWKI file updates must pass through the pipeline upload/conversion flow and cannot safely replace an existing document in-place.',
        ));
    }

    public function updateMetadata(string $documentId, Request $request): JsonResponse
    {
        $input = $request->validate([
            'metadata' => 'sometimes|array',
        ]);

        return $this->json($this->compat->updateDocumentMetadata($documentId, $input ?: $request->all()));
    }

    public function delete(string $documentId, Request $request): JsonResponse
    {
        return $this->json($this->compat->deleteDocument($documentId, $request->header('Idempotency-Key')));
    }

    public function summary(): JsonResponse
    {
        return $this->json($this->compat->unsupported(
            'documents/summary',
            'RAWKI does not currently persist stored document summaries.',
        ));
    }

    public function pages(): JsonResponse
    {
        return $this->json($this->compat->unsupported(
            'documents/pages',
            'RAWKI does not expose persisted PDF page extraction results through the document browser.',
        ));
    }

    public function file(): JsonResponse
    {
        return $this->json($this->compat->unsupported(
            'documents/file',
            'RAWKI file streaming is only supported for uploaded-source documents through /documents/uploads/download.',
        ));
    }

    /**
     * @param array{payload: array<string, mixed>, status: int} $result
     */
    private function json(array $result): JsonResponse
    {
        return response()->json($result['payload'], $result['status']);
    }
}
