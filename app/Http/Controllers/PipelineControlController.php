<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Pipeline\UploadPipelineFileRequest;
use App\Services\Document\ManagedDocumentBoundary;
use App\Services\Document\ManagedDocumentService;
use App\Services\Document\ManagedPipelineResponseMapper;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;

class PipelineControlController extends Controller
{
    public function __construct(
        private readonly ManagedDocumentService $documents,
        private readonly ManagedDocumentBoundary $boundary,
        private readonly ManagedPipelineResponseMapper $mapper,
        private readonly SettingsService $settings,
    ) {}

    public function uploadFile(UploadPipelineFileRequest $request): JsonResponse
    {
        $input = $request->uploadInput();
        $normalized = ['dataset_id' => $input->datasetId, 'graph' => $input->graph, 'converter_mode' => $input->converterMode, ...$input->customConverterProfile()];
        if ($input->usesCustomConverter() && $input->customConverterToken === null) {
            $normalized['converter_token'] = $this->settings->customConverterToken();
        }
        if ($result = $this->boundary->forward($request, 'create', $normalized, [$request->uploadedFile()])) {
            if ($result['status'] === 202 && ($result['payload']['success'] ?? false)) {
                $body = $result['payload'];

                return response()->json([
                    ...$this->mapper->map($body),
                    'dashboard_url' => url('/pipeline-controller'), 'controller_url' => url('/pipeline-controller'),
                ], 201);
            }

            return response()->json($result['payload'], $result['status']);
        }
        $result = $this->documents->createFromPipelineUpload($request->uploadInput(), $request->uploadedFile());

        return response()->json($result->payload, $result->status);
    }
}
