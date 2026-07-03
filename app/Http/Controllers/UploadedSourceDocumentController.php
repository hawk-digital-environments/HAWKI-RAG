<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Document\DownloadUploadedSourceRequest;
use App\Services\Authorization\AuthorizationService;
use App\Services\Document\UploadedSourceDocumentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UploadedSourceDocumentController extends Controller
{
    public function __construct(private readonly UploadedSourceDocumentService $documents)
    {
    }

    public function __invoke(DownloadUploadedSourceRequest $request, AuthorizationService $authorization): BinaryFileResponse|JsonResponse
    {
        $document = $this->documents->resolve($request->sourceUrl(), $request->contentHash());
        if ($document === null) {
            return response()->json([
                'success' => false,
                'message' => 'Uploaded source document was not found.',
            ], 404);
        }

        $documentId = $this->documents->documentIdForSource($request->sourceUrl(), $request->contentHash());
        if ($documentId === null && $authorization->documentApiEnforced()) {
            return response()->json([
                'success' => false,
                'message' => 'Uploaded source document is not linked to an authorizable document.',
            ], 403);
        }

        if ($documentId !== null && ! $authorization->canViewDocument($request->user(), $documentId)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to download this document.',
            ], 403);
        }

        return response()->download($document->path, $document->downloadName, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
