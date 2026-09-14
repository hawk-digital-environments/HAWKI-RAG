<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\DeleteTextIngestionRequest;
use App\Services\Authorization\DatasetIngestionAuthorizationService;
use App\Services\TextIngestion\TextIngestionDeletionService;
use Illuminate\Http\JsonResponse;

final class TextIngestionDeletionController extends Controller
{
    public function __invoke(
        DeleteTextIngestionRequest $request,
        string $sourceId,
        DatasetIngestionAuthorizationService $authorization,
        TextIngestionDeletionService $deletions,
    ): JsonResponse {
        $target = $deletions->target($sourceId);
        $authorization->authorize($request->authenticatedUser(), $target->datasetId);
        $result = $deletions->delete($target, $request->idempotencyKey());

        return response()->json($result->toArray());
    }
}
