<?php

declare(strict_types=1);

namespace App\Services\Authorization\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GrantTokenInvalidException extends HttpException implements AuthorizationExceptionInterface
{
    private function __construct()
    {
        parent::__construct(403, 'The provided dataset grant token is invalid, expired, or already used.');
    }

    public static function forRedemption(): self
    {
        return new self;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'grant_token_invalid',
        ], $this->getStatusCode());
    }
}
