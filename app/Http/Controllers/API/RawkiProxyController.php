<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class RawkiProxyController extends Controller
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('rawki.base_url');
    }

    public function query(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query'           => 'required|string',
            'top_k'           => 'sometimes|integer|min:1|max:25',
            'is_optimized'    => 'sometimes|boolean',
            'generate'        => 'sometimes|boolean',
            'preferred_tags'  => 'sometimes|array',
            'preferred_tags.*'=> 'string',
        ]);

        $payload = [
            'query'        => $data['query'],
            'top_k'        => $data['top_k'] ?? 5,
            'is_optimized' => $data['is_optimized'] ?? false,
            'generate'     => $data['generate'] ?? true,
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
                'message' => 'Failed to reach RAWKI bridge',
                'error' => $e->getMessage(),
            ], 502);
        }

        $status = $response->status();
        $json = $response->json();

        if ($json === null) {
            return response()->json([
                'ok' => false,
                'message' => 'RAWKI bridge returned an invalid response',
                'status' => $status,
                'body' => $response->body(),
            ], 502);
        }

        return response()->json($json, $status);
    }
}
