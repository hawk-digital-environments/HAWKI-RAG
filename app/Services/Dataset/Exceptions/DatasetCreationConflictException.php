<?php

declare(strict_types=1);

namespace App\Services\Dataset\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DatasetCreationConflictException extends HttpException implements DatasetExceptionInterface
{
    private function __construct(string $datasetId, string $field)
    {
        parent::__construct(
            409,
            "Dataset [{$datasetId}] already exists with a different [{$field}].",
        );
    }

    public static function forField(string $datasetId, string $field): self
    {
        return new self($datasetId, $field);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'dataset_creation_conflict',
            'message' => $this->getMessage(),
        ], $this->getStatusCode());
    }
}
