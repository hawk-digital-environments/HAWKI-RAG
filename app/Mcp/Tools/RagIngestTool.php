<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class RagIngestTool extends Tool
{
    public function description(): string
    {
        return 'Ingest documents into RAWKI via the FastAPI bridge.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->raw('docs', [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'text' => ['type' => 'string'],
                        'payload' => ['type' => 'object'],
                    ],
                    'required' => ['id', 'text'],
                ],
                'description' => 'Documents to ingest',
            ])->required()
            ->string('provider')->description('Embedding provider, e.g. ollama')
            ->string('collection')->description('Qdrant collection name')
            ->string('distance')->description('Qdrant distance metric, e.g. Cosine')
            ->integer('chunk_chars')->description('Chunk size in characters')
            ->integer('chunk_overlap')->description('Chunk overlap in characters')
            ->boolean('graph')->description('Whether to extract graph data')
            ->string('graph_engine')->description('Graph engine: raganything')
            ->boolean('dry_run')->description('If true, do not persist data')
            ->boolean('dry_include_graph')->description('Include graph output in dry runs');
    }

    public function handle(array $arguments): ToolResult
    {
        $docs = $arguments['docs'] ?? null;
        if (! is_array($docs) || $docs === []) {
            return ToolResult::error('docs is required and must be a non-empty array');
        }

        $payload = [
            'docs' => $docs,
            'provider' => $arguments['provider'] ?? null,
            'collection' => $arguments['collection'] ?? null,
            'distance' => $arguments['distance'] ?? null,
            'chunk_chars' => $arguments['chunk_chars'] ?? null,
            'chunk_overlap' => $arguments['chunk_overlap'] ?? null,
            'graph' => $arguments['graph'] ?? null,
            'graph_engine' => $arguments['graph_engine'] ?? null,
            'dry_run' => $arguments['dry_run'] ?? null,
            'dry_include_graph' => $arguments['dry_include_graph'] ?? null,
        ];

        $payload = array_filter($payload, static fn ($value) => $value !== null);

        $baseUrl = rtrim((string) env('RAWKI_BRIDGE_URL', 'http://rawki_bridge:8000'), '/');
        $response = Http::timeout(120)->post($baseUrl.'/ingest', $payload);

        if (! $response->successful()) {
            return ToolResult::error(sprintf('RAG ingest failed (%s): %s', $response->status(), $response->body()));
        }

        return ToolResult::json($response->json() ?? ['raw' => $response->body()]);
    }
}
