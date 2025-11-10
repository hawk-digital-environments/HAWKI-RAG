<?php

namespace App\Services\EmbeddingService;

use Illuminate\Http\Client\ConnectionException;
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
        $this->base              = config('lightrag.base_url');
        $this->healthPath        = config('lightrag.health_path');
        $this->ingestPath        = config('lightrag.ingest_path');
        $this->queryPath         = config('lightrag.queryPath');
        $this->graphFromTextPath = config('lightrag.graphFromTextPath');
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
