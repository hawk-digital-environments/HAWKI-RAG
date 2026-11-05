<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Document\ListDocumentsRequest;
use App\Services\Document\ManagedDocumentReadBoundary;
use App\Services\Document\UnifiedDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentBrowserController extends Controller
{
    public function __construct(
        private readonly UnifiedDocumentService $unifiedDocuments,
        private readonly ManagedDocumentReadBoundary $boundary,
    ) {}

    public function index(ListDocumentsRequest $request): JsonResponse
    {
        if ($result = $this->boundary->read($request, filters: $request->filters(), limit: $request->limit())) {
            return response()->json($result['payload'], $result['status']);
        }

        return response()->json([
            'success' => true,
            'documents' => $this->unifiedDocuments->list($request->limit(), $request->filters()),
        ]);
    }

    public function show(Request $request, string $documentId): JsonResponse
    {
        if ($result = $this->boundary->read($request, $documentId)) {
            return response()->json($result['payload'], $result['status']);
        }
        $result = $this->unifiedDocuments->show($documentId);
        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => "Document {$documentId} was not found.",
            ], 404);
        }

        return response()->json($result['payload'], $result['status']);
    }
}
