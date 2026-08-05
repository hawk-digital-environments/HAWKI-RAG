<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PipelineWorkerSignatureException extends HttpException implements PipelineExceptionInterface
{
    private function __construct(int $statusCode, string $message, private readonly string $errorCode)
    {
        parent::__construct($statusCode, $message);
    }

    public static function configurationMissing(): self
    {
        return new self(
            503,
            'Pipeline worker callbacks are unavailable because their signing secret is not configured.',
            'pipeline_worker_signature_unavailable',
        );
    }

    public static function unauthorized(): self
    {
        return new self(
            401,
            'The pipeline worker callback signature is invalid or expired.',
            'pipeline_worker_signature_invalid',
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
