<?php

namespace App\Mcp\Tools;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Illuminate\Validation\ValidationException;

class RagTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Fetches information from the HAWK website and HAWK Project Catalogue.
    MARKDOWN;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        try {
            $validated = $request->validate([
                'query' => 'required|string',
                'preferred_tags' => 'sometimes|array',
            ]);
        } catch (ValidationException $e) {
            return Response::text(json_encode([
                'ok' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ]));
        }

        $payload = [
            'query'        => $validated['query'],
            'top_k'        => 5,
            'is_optimized' => false,
            'generate'     => true,
        ];

        if (!empty($validated['preferred_tags'])) {
            $payload['preferred_tags'] = $validated['preferred_tags'];
        }

        try {
            $baseUrl = rtrim(config('services.rawki.base_url', env('RAWKI_BASE_URL', 'http://rawki_bridge:8000')), '/');
            $response = Http::timeout(60)->post($baseUrl . '/query', $payload);
        } catch (\Throwable $e) {
            return Response::text(json_encode([
                'ok' => false,
                'message' => 'Failed to reach RAWKI bridge',
                'error' => $e->getMessage(),
            ]));
        }

        $json = $response->json();

        return Response::text(json_encode([
            'ok' => true,
            'data' => $json,
        ]));
    }

    /**
     * Get the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The query to search for context information')
                ->required(),
            'preferred_tags' => $schema->array()
                ->items($schema->string())
                ->description('Optional list of preferred tags for filtering results')
        ];
    }
}
