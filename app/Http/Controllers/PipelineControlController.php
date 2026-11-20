<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Pipeline\UploadPipelineFileRequest;
use App\Services\Document\ManagedDocumentService;
use Illuminate\Http\JsonResponse;

class PipelineControlController extends Controller
{
    public function __construct(
        private readonly ManagedDocumentService $documents,
    ) {}

    public function uploadFile(UploadPipelineFileRequest $request): JsonResponse
    {
        $result = $this->documents->createFromPipelineUpload($request->uploadInput(), $request->uploadedFile());

        return response()->json($result->payload, $result->status);
    }
}
