<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class RagHealthController extends Controller
{
    public function show(): JsonResponse
    {
        $bridgeBase = (string) config('config.hawki_rag_bridge_url', config('config.base_url', 'http://hawki_rag_bridge:8000'));
        $primaryBase = (string) config('config.rag_api_url', '');
        $candidates = array_values(array_unique(array_filter([
            rtrim($bridgeBase, '/') . '/health',
            $primaryBase !== '' ? rtrim($primaryBase, '/') . '/health' : null,
        ])));

        $lastError = null;
        foreach ($candidates as $url) {
            try {
                $start = microtime(true);
                $response = Http::connectTimeout(2)->timeout(10)->get($url);
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
