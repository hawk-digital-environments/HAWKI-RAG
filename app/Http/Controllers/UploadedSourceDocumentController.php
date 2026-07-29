<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Document\DownloadUploadedSourceRequest;
use App\Services\Document\UploadedSourceDocumentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UploadedSourceDocumentController extends Controller
{
    public function __construct(private readonly UploadedSourceDocumentService $documents)
    {
    }

    public function __invoke(DownloadUploadedSourceRequest $request): BinaryFileResponse|JsonResponse
    {
        $document = $this->documents->resolve($request->sourceUrl(), $request->contentHash());
        if ($document === null) {
            return response()->json([
                'success' => false,
                'message' => 'Uploaded source document was not found.',
            ], 404);
        }

        return response()->download($document->path, $document->downloadName, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
