<?php

declare(strict_types=1);

namespace App\Services\Authorization\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DatasetNotReadyException extends HttpException implements AuthorizationExceptionInterface
{
    private function __construct()
    {
        parent::__construct(409, 'The requested dataset is not ready for querying.');
    }

    public static function storageTargetsAreMissing(): self
    {
        return new self;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'dataset_not_ready',
        ], $this->getStatusCode());
    }
}
