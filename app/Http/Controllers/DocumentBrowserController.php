<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Document\ListDocumentsRequest;
use App\Services\Authorization\AuthorizationService;
use App\Services\Document\DocumentBrowserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentBrowserController extends Controller
{
    public function __construct(
        private readonly DocumentBrowserService $documents,
        private readonly AuthorizationService $authorization,
    ) {
    }

    public function index(ListDocumentsRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'documents' => $this->documents->list($request->limit(), $request->filters()),
        ]);
    }

    public function show(string $documentId, Request $request): JsonResponse
    {
        $document = $this->documents->show($documentId);
        if (!$document) {
            return response()->json([
                'success' => false,
                'message' => "Document {$documentId} was not found.",
            ], 404);
        }

        if (! $this->authorization->canViewDocument($request->user(), $documentId)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access this document.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'document' => $document,
        ]);
    }
}
