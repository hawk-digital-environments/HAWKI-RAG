<?php

declare(strict_types=1);

namespace App\Services\Dataset\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fail-closed failures of the dataset backend boundary.
 *
 * The envelope is the existing stable dataset error envelope. Driver details,
 * upstream bodies, tokens and physical targets are never included.
 */
final class DatasetBackendUnavailableException extends HttpException implements DatasetExceptionInterface
{
    private function __construct(int $status, private readonly string $errorCode, string $message)
    {
        parent::__construct($status, $message);
    }

    public static function configurationInvalid(): self
    {
        return new self(
            503,
            'dataset_backend_configuration_invalid',
            'Dataset management is not available.',
        );
    }

    public static function upstreamUnavailable(): self
    {
        return new self(
            503,
            'dataset_unavailable',
            'The dataset service is not available.',
        );
    }

    /**
     * A legacy Laravel code path tried to write dataset authority while
     * Python owns it. Fail closed instead of creating a second writer.
     */
    public static function readOnly(): self
    {
        return new self(
            503,
            'dataset_backend_read_only',
            'Dataset metadata is owned by the Python dataset authority.',
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->getStatusCode());
    }
}
