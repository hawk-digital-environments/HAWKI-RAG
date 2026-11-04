<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\IngestTextRequest;
use App\Services\Authorization\DatasetIngestionAuthorizationService;
use App\Services\TextIngestion\Clients\PythonTextIngestionClient;
use App\Services\TextIngestion\TextIngestionService;
use App\Services\TextIngestion\Values\TextIngestionInput;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Psr\Log\LoggerInterface;

final class TextIngestionController extends Controller
{
    public function __invoke(
        IngestTextRequest $request,
        DatasetIngestionAuthorizationService $authorization,
        TextIngestionService $service,
        PythonTextIngestionClient $python,
        ConfigRepository $config,
        LoggerInterface $logger,
    ): JsonResponse {
        return match ($config->get('config.text_ingestion_backend', 'laravel')) {
            'laravel' => $this->legacy($request, $request->ingestionInput(), $authorization, $service),
            'python' => $this->proxied($request, $python),
            default => $this->invalidBackend($logger),
        };
    }

    private function legacy(
        IngestTextRequest $request,
        TextIngestionInput $input,
        DatasetIngestionAuthorizationService $authorization,
        TextIngestionService $service,
    ): JsonResponse {
        $authorization->authorize($request->authenticatedUser(), $input->datasetId);

        $result = $service->ingest($input, $request->idempotencyKey());

        return response()->json(
            $result->toArray(),
            $result->replayed ? 200 : 202,
        );
    }

    private function proxied(
        IngestTextRequest $request,
        PythonTextIngestionClient $python,
    ): JsonResponse {
        // No Laravel pipeline state and no Laravel dataset grant decision: the
        // Python operation performs the final authorization and owns the row.
        $result = $python->ingest(
            $request->authenticatedUser(),
            (string) $request->getContent(),
            (string) $request->header('Idempotency-Key'),
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
