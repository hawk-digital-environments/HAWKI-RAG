<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Document\ListDocumentsRequest;
use App\Services\Document\DocumentBrowserService;
use Illuminate\Http\JsonResponse;

class DocumentBrowserController extends Controller
{
    public function __construct(
        private readonly DocumentBrowserService $documents,
    ) {
    }

    public function index(ListDocumentsRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'documents' => $this->documents->list($request->limit(), $request->filters()),
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
