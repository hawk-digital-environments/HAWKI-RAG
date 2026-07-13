<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Document\ListDocumentsRequest;
use App\Services\Document\UnifiedDocumentService;
use Illuminate\Http\JsonResponse;

class DocumentBrowserController extends Controller
{
    public function __construct(
        private readonly UnifiedDocumentService $unifiedDocuments,
    ) {
    }

    public function index(ListDocumentsRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'documents' => $this->unifiedDocuments->list($request->limit(), $request->filters()),
        ]);
    }

    public function show(string $documentId): JsonResponse
    {
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
