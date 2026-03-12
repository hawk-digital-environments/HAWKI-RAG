<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class HawkiRagProxyController extends Controller
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('config.base_url');
    }

    public function query(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query'           => 'required|string',
            'top_k'           => 'sometimes|integer|min:1|max:100',
            'is_optimized'    => 'sometimes|boolean',
            'generate'        => 'sometimes|boolean',
            'fast_mode'       => 'sometimes|boolean',
            'smart_lookup'    => 'sometimes|boolean',
            'preferred_tags'  => 'sometimes|array',
            'preferred_tags.*'=> 'string',
        ]);

        $payload = [
            'query'        => $data['query'],
            'top_k'        => $data['top_k'] ?? 5,
            'is_optimized' => $data['is_optimized'] ?? false,
            'generate'     => $data['generate'] ?? true,
            'fast_mode'    => $data['fast_mode'] ?? false,
            'smart_lookup' => $data['smart_lookup'] ?? false,
        ];

        if (!empty($data['preferred_tags'])) {
            $payload['preferred_tags'] = $data['preferred_tags'];
        }

        try {
            $response = Http::timeout(60)
                ->post($this->baseUrl . '/query', $payload);

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Failed to reach HAWKI RAG bridge',
                'error' => $e->getMessage(),
            ], 502);
        }

        $status = $response->status();
        $json = $response->json();

        if ($json === null) {
            return response()->json([
                'ok' => false,
                'message' => 'HAWKI RAG bridge returned an invalid response',
                'status' => $status,
                'body' => $response->body(),
            ], 502);
        }

        return response()->json($json, $status);
    }
}
