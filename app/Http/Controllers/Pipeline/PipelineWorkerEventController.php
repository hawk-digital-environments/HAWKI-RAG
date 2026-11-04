<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\StorePipelineWorkerEventRequest;
use App\Services\Pipeline\PipelineWorkerEventService;
use App\Services\Pipeline\Values\PipelineWorkerEvent;
use App\Services\TextIngestion\Clients\PythonTextIngestionClient;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Psr\Log\LoggerInterface;

class PipelineWorkerEventController extends Controller
{
    public function __invoke(
        StorePipelineWorkerEventRequest $request,
        PipelineWorkerEventService $events,
        PythonTextIngestionClient $python,
        ConfigRepository $config,
        LoggerInterface $logger,
    ): JsonResponse {
        $event = $request->event();

        return match ($config->get('config.text_ingestion_backend', 'laravel')) {
            'laravel' => $this->record($events, $event),
            'python' => str_starts_with($event->workflowId, 'ingest-text-')
                ? $this->relay($request, $python)
                : $this->record($events, $event),
            default => $this->invalidBackend($logger),
        };
    }

    private function record(
        PipelineWorkerEventService $events,
        PipelineWorkerEvent $event,
    ): JsonResponse {
        $receipt = $events->record($event);

        return response()->json($receipt, $receipt['duplicate'] ? 200 : 202);
    }

    private function relay(
        StorePipelineWorkerEventRequest $request,
        PythonTextIngestionClient $python,
    ): JsonResponse {
        // The worker protocol is unchanged: forward the exact signed body shape
        // with a fresh HMAC so Python owns the terminal direct-text state.
        $result = $python->relayEvent((string) $request->getContent());

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
