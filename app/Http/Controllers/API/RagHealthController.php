<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class RagHealthController extends Controller
{
    public function show(): JsonResponse
    {
        $baseUrl = (string) config('rawki.rag_api_url', 'http://raganything_api:8003');
        $url = rtrim($baseUrl, '/') . '/health';

        try {
            $start = microtime(true);
            $response = Http::timeout(3)->get($url);
            $elapsedMs = (int) ((microtime(true) - $start) * 1000);

            if (! $response->successful()) {
                return response()->json([
                    'ok' => false,
                    'status' => $response->status(),
                    'latency_ms' => $elapsedMs,
                    'body' => $response->body(),
                ], 502);
            }

            return response()->json([
                'ok' => true,
                'status' => $response->status(),
                'latency_ms' => $elapsedMs,
                'data' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 502);
        }
    }
}
