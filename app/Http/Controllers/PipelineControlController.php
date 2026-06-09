<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Pipeline\UploadPipelineFileRequest;
use App\Services\Pipeline\Uploads\PipelineUploadService;
use Illuminate\Http\JsonResponse;

class PipelineControlController extends Controller
{
    public function __construct(
        private readonly PipelineUploadService $uploads,
    ) {}

    public function uploadFile(UploadPipelineFileRequest $request): JsonResponse
    {
        $result = $this->uploads->upload($request->uploadInput(), $request->uploadedFile());

        return response()->json($result->payload, $result->status);
    }
}
