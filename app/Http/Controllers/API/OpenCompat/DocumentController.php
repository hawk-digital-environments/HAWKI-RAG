<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\OpenCompat;

use App\Http\Controllers\Controller;
use App\Services\OpenCompat\OpenCompatDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(private readonly OpenCompatDocumentService $documents) {}

    public function list(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:250',
            'dataset_id' => 'sometimes|string|max:255',
            'datasetId' => 'sometimes|string|max:255',
            'user_identifier' => 'sometimes|string|max:255',
            'q' => 'sometimes|string|max:1000',
            'search' => 'sometimes|string|max:1000',
        ]);

        $userIdentifier = $filters['user_identifier'] ?? null;
        unset($filters['user_identifier']);

        return $this->json($this->documents->listDocuments($filters, is_string($userIdentifier) ? $userIdentifier : null));
    }

    public function show(Request $request, string $documentId): JsonResponse
    {
        return $this->json($this->documents->showDocument($documentId, $this->userIdentifier($request)));
    }

    public function status(Request $request, string $documentId): JsonResponse
    {
        return $this->json($this->documents->documentStatus($documentId, $this->userIdentifier($request)));
    }

    public function byFilename(Request $request, string $filename): JsonResponse
    {
        return $this->json($this->documents->byFilename($filename, $this->userIdentifier($request)));
    }

    public function downloadUrl(Request $request, string $documentId): JsonResponse
    {
        return $this->json($this->documents->downloadUrl($documentId, $this->userIdentifier($request)));
    }

    public function updateText(string $documentId, Request $request): JsonResponse
    {
        $input = $request->validate([
            'text' => 'required|string',
            'metadata' => 'sometimes|array',
            'collection' => 'sometimes|string|max:255',
            'graph' => 'sometimes|boolean',
            'user_identifier' => 'sometimes|string|max:255',
        ]);

        $userIdentifier = $input['user_identifier'] ?? null;
        unset($input['user_identifier']);

        return $this->json($this->documents->updateDocumentText(
            $documentId,
            $input,
            $request->header('Idempotency-Key'),
            is_string($userIdentifier) ? $userIdentifier : null,
        ));
    }

    public function updateFile(): JsonResponse
    {
        return $this->json($this->unsupported(
            'documents/update_file',
            'RAWKI file updates must pass through the pipeline upload/conversion flow and cannot safely replace an existing document in-place.',
        ));
    }

    public function updateMetadata(string $documentId, Request $request): JsonResponse
    {
        $input = $request->validate([
            'metadata' => 'sometimes|array',
            'user_identifier' => 'sometimes|string|max:255',
        ]);

        $userIdentifier = $input['user_identifier'] ?? null;
        unset($input['user_identifier']);

        return $this->json($this->documents->updateDocumentMetadata(
            $documentId,
            $input ?: $request->except('user_identifier'),
            is_string($userIdentifier) ? $userIdentifier : null,
        ));
    }

    public function delete(string $documentId, Request $request): JsonResponse
    {
        return $this->json($this->documents->deleteDocument(
            $documentId,
            $request->header('Idempotency-Key'),
            $this->userIdentifier($request),
        ));
    }

    public function summary(): JsonResponse
    {
        return $this->json($this->unsupported(
            'documents/summary',
            'RAWKI does not currently persist stored document summaries.',
        ));
    }

    public function pages(): JsonResponse
    {
        return $this->json($this->unsupported(
            'documents/pages',
            'RAWKI does not expose persisted PDF page extraction results through the document browser.',
        ));
    }

    public function file(): JsonResponse
    {
        return $this->json($this->unsupported(
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

    private function userIdentifier(Request $request): ?string
    {
        $value = $request->string('user_identifier')->trim()->value();

        return $value !== '' ? $value : null;
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    private function unsupported(string $endpoint, string $reason): array
    {
        return [
            'status' => 501,
            'payload' => [
                'ok' => false,
                'error' => 'unsupported',
                'endpoint' => $endpoint,
                'reason' => $reason,
            ],
        ];
    }
}
