<?php

declare(strict_types=1);

namespace App\Http\Controllers\Graph;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

abstract class GraphController extends Controller
{
    protected function graphResponse(\Closure $callback): JsonResponse
    {
        try {
            $result = $callback();
            $status = (int) ($result['status'] ?? (($result['ok'] ?? true) ? 200 : 422));

            return $this->noStore(response()->json($result, $status));
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return $this->noStore(response()->json([
                'ok' => false,
                'message' => 'Neo4j graph explorer request failed.',
            ], 502));
        }
    }

    protected function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
