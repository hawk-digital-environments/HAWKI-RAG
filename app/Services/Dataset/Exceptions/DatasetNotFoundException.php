<?php

declare(strict_types=1);

namespace App\Services\Dataset\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DatasetNotFoundException extends NotFoundHttpException implements DatasetExceptionInterface
{
    private function __construct(string $datasetId)
    {
        parent::__construct("Dataset [{$datasetId}] was not found.");
    }

    public static function forId(string $datasetId): self
    {
        return new self($datasetId);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'dataset_not_found',
            'message' => $this->getMessage(),
        ], $this->getStatusCode());
    }
}
