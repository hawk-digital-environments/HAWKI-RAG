<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TextIngestionNotFoundException extends NotFoundHttpException implements TextIngestionExceptionInterface
{
    private function __construct()
    {
        parent::__construct('The requested direct-text ingestion is not available.');
    }

    public static function unavailable(): self
    {
        return new self;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'text_ingestion_not_found',
            'message' => $this->getMessage(),
        ], 404);
    }
}
