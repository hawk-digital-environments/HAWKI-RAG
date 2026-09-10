<?php

declare(strict_types=1);

namespace App\Services\Dataset\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DatasetInactiveException extends HttpException implements DatasetExceptionInterface
{
    private function __construct(string $datasetId)
    {
        parent::__construct(409, "Dataset [{$datasetId}] is not active.");
    }

    public static function forId(string $datasetId): self
    {
        return new self($datasetId);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'dataset_inactive',
            'message' => $this->getMessage(),
        ], $this->getStatusCode());
    }
}
