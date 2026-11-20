<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PipelineWorkerEventException extends HttpException implements PipelineExceptionInterface
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct(409, $message);
    }

    public static function eventIdCollision(): self
    {
        return new self(
            'The worker event ID has already been used with a different payload.',
            'pipeline_worker_event_id_collision',
        );
    }

    public static function targetUnavailable(): self
    {
        return new self(
            'The worker event does not reference an available pipeline execution.',
            'pipeline_worker_event_target_unavailable',
        );
    }

    public static function targetMismatch(): self
    {
        return new self(
            'The worker event identifiers do not match the pipeline execution.',
            'pipeline_worker_event_target_mismatch',
        );
    }

    public static function stateUnavailable(): self
    {
        return new self(
            'The pipeline state could not be updated for this worker event.',
            'pipeline_worker_event_state_unavailable',
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => $this->errorCode,
        ], $this->getStatusCode());
    }
}
