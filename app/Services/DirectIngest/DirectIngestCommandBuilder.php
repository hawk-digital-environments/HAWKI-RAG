<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DirectIngestCommandBuilder
{
    public function build(array $data, string $script, string $path, string $baseUrl, string $summaryPath): array
    {
        $cmd = [
            'python3',
            '-u',
            $script,
            '--root', $path,
            '--base-url', $baseUrl,
        ];

        $collection = $data['collection'] ?? basename($path);
        if ($collection) {
            $cmd[] = '--collection';
            $cmd[] = (string) $collection;
        }

        foreach ([
            'provider' => '--provider',
            'embedding_model' => '--embedding-model',
            'graph_engine' => '--graph-engine',
            'neo4j_database' => '--neo4j-database',
            'chunk_chars' => '--chunk-chars',
            'chunk_overlap' => '--chunk-overlap',
            'batch' => '--batch',
        ] as $field => $option) {
            if (! empty($data[$field])) {
                $cmd[] = $option;
                $cmd[] = (string) $data[$field];
            }
        }

        $timeout = $data['timeout'] ?? (int) config('config.ingest_timeout', 6000);
        if ($timeout > 0) {
            $cmd[] = '--timeout';
            $cmd[] = (string) $timeout;
        }
        if (! empty($data['graph'])) {
            $cmd[] = '--graph';
        }
        if (! empty($data['graph_only'])) {
            $cmd[] = '--graph-only';
        }

        $cmd[] = ($data['resume_mode'] ?? 'resume') === 'start' ? '--start' : '--resume';
        $cmd[] = '--summary-file';
        $cmd[] = $summaryPath;

        return $cmd;
    }
}
