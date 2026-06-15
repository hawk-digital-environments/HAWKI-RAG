<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class RagHealthController extends Controller
{
    public function show(): JsonResponse
    {
        $primaryBase = (string) config('config.rag_api_url', 'http://raganything_api_gpu:8003');
        $bridgeBase = (string) config('config.base_url', 'http://hawki_rag_bridge:8000');
        $candidates = array_values(array_unique(array_filter([
            rtrim($primaryBase, '/') . '/health',
            rtrim($bridgeBase, '/') . '/health',
            'http://raganything_api_gpu:8003/health',
            'http://raganything_api:8003/health',
        ])));

        $lastError = null;
        foreach ($candidates as $url) {
            try {
                $start = microtime(true);
                $response = Http::timeout(3)->get($url);
                $elapsedMs = (int) ((microtime(true) - $start) * 1000);

                if (! $response->successful()) {
                    $lastError = [
                        'status' => $response->status(),
                        'latency_ms' => $elapsedMs,
                        'body' => $response->body(),
                        'endpoint' => $url,
                    ];
                    continue;
                }

                return response()->json([
                    'ok' => true,
                    'status' => $response->status(),
                    'latency_ms' => $elapsedMs,
                    'endpoint' => $url,
                    'data' => $response->json(),
                ]);
            } catch (\Throwable $e) {
                $lastError = [
                    'status' => 502,
                    'latency_ms' => null,
                    'body' => $e->getMessage(),
                    'endpoint' => $url,
                ];
            }
        }

        if ($lastError) {
            return response()->json([
                'ok' => false,
                'status' => $lastError['status'],
                'latency_ms' => $lastError['latency_ms'],
                'endpoint' => $lastError['endpoint'],
                'body' => $lastError['body'],
            ], 502);
        }

        return response()->json([
            'ok' => false,
            'message' => 'No RAG health endpoints were configured.',
        ], 502);
    }
}
