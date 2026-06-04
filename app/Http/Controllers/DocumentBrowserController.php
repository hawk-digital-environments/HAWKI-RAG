<?php

namespace App\Http\Controllers;

use App\Services\Documents\DocumentBrowserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentBrowserController extends Controller
{
    public function __construct(
        private readonly DocumentBrowserService $documents,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dataset_id' => 'nullable|string|max:191',
            'datasetId' => 'nullable|string|max:191',
            'q' => 'nullable|string|max:255',
            'search' => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:250',
        ]);

        return response()->json([
            'success' => true,
            'documents' => $this->documents->list((int) ($validated['limit'] ?? 100), $validated),
        ]);
    }

    public function show(string $documentId): JsonResponse
    {
        $document = $this->documents->show($documentId);
        if (!$document) {
            return response()->json([
                'success' => false,
                'message' => "Document {$documentId} was not found.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'document' => $document,
        ]);
    }
}
