<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class LimitTextIngestionRequestSize
{
    public function handle(Request $request, \Closure $next): Response
    {
        $maximumBytes = (int) config('config.text_ingestion.max_request_bytes');
        if (strlen($request->getContent()) > $maximumBytes) {
            return new JsonResponse([
                'error' => 'text_ingestion_request_too_large',
                'message' => 'The text ingestion request is too large.',
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return $next($request);
    }
}
