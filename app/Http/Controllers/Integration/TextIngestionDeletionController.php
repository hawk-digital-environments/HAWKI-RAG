<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\DeleteTextIngestionRequest;
use App\Services\Authorization\DatasetIngestionAuthorizationService;
use App\Services\TextIngestion\Clients\PythonTextIngestionClient;
use App\Services\TextIngestion\TextIngestionDeletionService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Psr\Log\LoggerInterface;

final class TextIngestionDeletionController extends Controller
{
    public function __invoke(
        DeleteTextIngestionRequest $request,
        string $sourceId,
        DatasetIngestionAuthorizationService $authorization,
        TextIngestionDeletionService $deletions,
        PythonTextIngestionClient $python,
        ConfigRepository $config,
        LoggerInterface $logger,
    ): JsonResponse {
        return match ($config->get('config.text_ingestion_backend', 'laravel')) {
            'laravel' => $this->legacy($request, $sourceId, $authorization, $deletions),
            'python' => $this->proxied($request, $sourceId, $python),
            default => $this->invalidBackend($logger),
        };
    }

    private function legacy(
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

    private function proxied(
        DeleteTextIngestionRequest $request,
        string $sourceId,
        PythonTextIngestionClient $python,
    ): JsonResponse {
        // The Python operation resolves the owned source, applies the dataset
        // grant and performs the cleanup. Laravel owns no direct-text row.
        $result = $python->delete(
            $request->authenticatedUser(),
            $sourceId,
            $request->idempotencyKey(),
        );

        return response()->json($result['payload'], $result['status']);
    }

    private function invalidBackend(LoggerInterface $logger): JsonResponse
    {
        $logger->error('Text-ingestion backend configuration is invalid.');

        return response()->json([
            'error' => 'text_ingestion_backend_configuration',
            'message' => 'Text ingestion is not available.',
        ], 503);
    }
}
