<?php

declare(strict_types=1);

namespace App\Services\Authorization\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GrantTokenRequiredException extends HttpException implements AuthorizationExceptionInterface
{
    private function __construct()
    {
        parent::__construct(403, 'A one-time dataset grant token is required to self-grant ingest access.');
    }

    public static function forSelfGrant(): self
    {
        return new self;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'grant_token_required',
        ], $this->getStatusCode());
    }
}
