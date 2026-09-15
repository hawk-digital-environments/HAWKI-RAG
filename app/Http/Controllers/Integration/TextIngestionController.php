<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\IngestTextRequest;
use App\Services\Authorization\DatasetIngestionAuthorizationService;
use App\Services\TextIngestion\TextIngestionService;
use Illuminate\Http\JsonResponse;

final class TextIngestionController extends Controller
{
    public function __invoke(
        IngestTextRequest $request,
        DatasetIngestionAuthorizationService $authorization,
        TextIngestionService $service,
    ): JsonResponse {
        $input = $request->ingestionInput();
        $authorization->authorize($request->authenticatedUser(), $input->datasetId);

        $result = $service->ingest(
            $input,
            $request->idempotencyKey(),
        );

        return response()->json(
            $result->toArray(),
            $result->replayed ? 200 : 202,
        );
    }
}
