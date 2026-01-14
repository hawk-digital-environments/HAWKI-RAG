<?php

namespace App\Services\EmbeddingService;

use App\Services\Mcp\McpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class PythonRagClient
{
    protected string $base;
    protected string $healthPath;
    protected string $ingestPath;
    protected string $queryPath;
    protected string $graphFromTextPath;
    protected bool $useMcp;
    protected ?McpClient $mcpClient;
    protected bool $mcpFallback;
    protected string $mcpToolQuery;
    protected string $mcpToolIngest;

    public function __construct()
    {
        $this->base              = config('lightrag.base_url');
        $this->healthPath        = config('lightrag.health_path');
        $this->ingestPath        = config('lightrag.ingest_path');
        $this->queryPath         = config('lightrag.queryPath');
        $this->graphFromTextPath = config('lightrag.graphFromTextPath');
        $this->useMcp            = (bool) config('mcp.enabled', true);
        $this->mcpFallback       = (bool) config('mcp.use_fallback', true);
        $this->mcpToolQuery      = (string) config('mcp.tools.rag_query', 'rag-query-tool');
        $this->mcpToolIngest     = (string) config('mcp.tools.rag_ingest', 'rag-ingest-tool');
        $this->mcpClient         = $this->useMcp ? app(McpClient::class) : null;
    }

    public function health(): array
    {
        return Http::timeout(5)->get($this->base . $this->healthPath)->json() ?? [];
    }

    public function ingest(array $docs, array $options = []): array
    {
        $payload = array_merge(['docs' => $docs], $options);
        if ($this->useMcp && $this->mcpClient) {
            $result = $this->mcpClient->callTool($this->mcpToolIngest, $payload);
            $parsed = $this->parseMcpResult($result);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        if (! $this->mcpFallback) {
            return ['error' => 'MCP ingest unavailable'];
        }

        return Http::timeout(120)->post($this->base . $this->ingestPath, $payload)->json() ?? [];
    }

    public function query(string $query, array $options = []): array
    {
        $payload = array_merge(['query' => $query], $options);
        if ($this->useMcp && $this->mcpClient) {
            $result = $this->mcpClient->callTool($this->mcpToolQuery, $payload);
            $parsed = $this->parseMcpResult($result);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        if (! $this->mcpFallback) {
            return ['error' => 'MCP query unavailable'];
        }

        return Http::timeout(60)->post($this->base . $this->queryPath, $payload)->json() ?? [];
    }

    public function graphFromText(string $text, array $options = []): array
    {
        $payload = array_merge(['text' => $text], $options);
        return Http::timeout(60)->post($this->base . $this->graphFromTextPath, $payload)->json() ?? [];
    }

    private function parseMcpResult(array $result): ?array
    {
        if (($result['ok'] ?? true) === false) {
            return null;
        }

        if (($result['isError'] ?? false) === true) {
            $content = $result['content'][0]['text'] ?? null;
            return ['error' => $content ?? 'MCP tool error'];
        }

        $content = $result['content'][0]['text'] ?? null;
        if (! is_string($content) || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['raw' => $content];
        }

        return is_array($decoded) ? $decoded : null;
    }
}
