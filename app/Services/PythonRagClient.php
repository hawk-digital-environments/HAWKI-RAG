<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PythonRagClient
{
    protected string $base;
    protected string $healthPath;
    protected string $ingestPath;
    protected string $queryPath;
    protected string $graphFromTextPath;

    public function __construct()
    {
        $this->base = rtrim(env('LIGHTRAG_URL', 'http://lightrag:8000'), '/');
        // Allow switching to the official LightRAG server endpoints via env
        $this->healthPath        = env('LIGHTRAG_HEALTH_PATH', '/health');
        $this->ingestPath        = env('LIGHTRAG_INGEST_PATH', '/ingest');
        $this->queryPath         = env('LIGHTRAG_QUERY_PATH', '/query');
        $this->graphFromTextPath = env('LIGHTRAG_GRAPH_TEXT_PATH', '/graph/from-text');
    }

    public function health(): array
    {
        return Http::timeout(5)->get($this->base . $this->healthPath)->json() ?? [];
    }

    public function ingest(array $docs, array $options = []): array
    {
        $payload = array_merge(['docs' => $docs], $options);
        return Http::timeout(120)->post($this->base . $this->ingestPath, $payload)->json() ?? [];
    }

    public function query(string $query, array $options = []): array
    {
        $payload = array_merge(['query' => $query], $options);
        return Http::timeout(60)->post($this->base . $this->queryPath, $payload)->json() ?? [];
    }

    public function graphFromText(string $text, array $options = []): array
    {
        $payload = array_merge(['text' => $text], $options);
        return Http::timeout(60)->post($this->base . $this->graphFromTextPath, $payload)->json() ?? [];
    }
}
