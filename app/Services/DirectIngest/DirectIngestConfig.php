<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use App\Services\DirectIngest\Values\DirectIngestStatusPaths;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class DirectIngestConfig
{
    public function __construct(
        private ConfigRepository $config,
        private DirectIngestPathResolver $paths,
    ) {
    }

    public function statusPaths(string $mode): DirectIngestStatusPaths
    {
        if ($mode === 'neo4j') {
            return new DirectIngestStatusPaths(
                (string) $this->config->get('config.ingest_status_path_neo4j', $this->paths->storagePath('logs/ingest_status_neo4j.json')),
                (string) $this->config->get('config.ingest_log_cache_path_neo4j', $this->paths->storagePath('logs/ingest_progress_neo4j_cache.log')),
                (string) $this->config->get('config.ingest_log_path_neo4j', $this->paths->storagePath('logs/ingest_progress_neo4j_full.log')),
            );
        }

        return new DirectIngestStatusPaths(
            (string) $this->config->get('config.ingest_status_path', $this->paths->storagePath('logs/ingest_status.json')),
            (string) $this->config->get('config.ingest_log_cache_path', $this->paths->storagePath('logs/ingest_progress_cache.log')),
            (string) $this->config->get('config.ingest_log_path', $this->paths->storagePath('logs/ingest_progress_full.log')),
        );
    }

    public function crawledDataRoot(): string
    {
        return (string) $this->config->get('config.crawled_data_root', '/app/shared');
    }

    public function hawkiRagBridgeUrl(): string
    {
        return (string) $this->config->get('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge:8000');
    }

    public function qdrantHttpUrl(): string
    {
        return rtrim((string) $this->config->get('config.qdrant_http_url', 'http://qdrant:6333'), '/');
    }

    public function ingestTimeout(): int
    {
        return (int) $this->config->get('config.ingest_timeout', 6000);
    }

    public function ingestScriptPath(): string
    {
        return $this->paths->basePath('python_rag/ingest/ingest_crawled.py');
    }

    public function ingestSummaryPath(): string
    {
        return $this->paths->storagePath('logs/ingest_summary.json');
    }
}
