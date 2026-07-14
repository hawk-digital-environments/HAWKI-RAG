<?php

declare(strict_types=1);

namespace App\Services\Authorization\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DatasetQueryNotFoundException extends NotFoundHttpException implements AuthorizationExceptionInterface
{
    private function __construct()
    {
        parent::__construct('The requested dataset is not available.');
    }

    public static function requestedDatasetIsUnavailable(): self
    {
        return new self;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'dataset_not_found',
        ], $this->getStatusCode());
    }
}
