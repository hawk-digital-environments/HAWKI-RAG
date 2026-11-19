<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Document\DownloadUploadedSourceRequest;
use App\Services\Authorization\BrowserQueryPrincipalService;
use App\Services\Document\Clients\PythonManagedDocumentClient;
use App\Services\Document\UploadedSourceDocumentService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UploadedSourceDocumentController extends Controller
{
    public function __construct(
        private readonly UploadedSourceDocumentService $documents,
        private readonly BrowserQueryPrincipalService $principals,
        private readonly PythonManagedDocumentClient $python,
        private readonly ConfigRepository $config,
    ) {}

    public function __invoke(DownloadUploadedSourceRequest $request): BinaryFileResponse|JsonResponse
    {
        $backend = $this->config->get('config.managed_document_backend', 'python');
        if (! in_array($backend, ['python', 'laravel'], true)) {
            return response()->json(['success' => false, 'message' => 'Managed document backend configuration is invalid.'], 503);
        }
        if ($request->documentId() !== null && $backend === 'python') {
            $user = $this->principals->resolve($request);
            if ($user === null) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }
            // Python authorizes the requested document using its tenant and
            // query grants. A denial or outage cannot fall back to a local scan.
            $result = $this->python->read($user, $request->documentId());
            if ($result['status'] !== 200) {
                return response()->json($result['payload'], $result['status']);
            }
            $metadata = $result['payload']['document'];
            $document = ($metadata['document_id'] ?? null) === $request->documentId()
                ? $this->documents->resolvePython($request->sourceUrl(), $request->contentHash(), $metadata) : null;
        } else {
            $document = $this->documents->resolve($request->sourceUrl(), $request->contentHash());
        }
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
