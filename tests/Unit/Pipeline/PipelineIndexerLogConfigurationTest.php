<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use Tests\TestCase;

final class PipelineIndexerLogConfigurationTest extends TestCase
{
    public function test_ingest_stage_discovers_indexer_and_legacy_cutover_logs(): void
    {
        $paths = config('config.pipeline_stage_runtime_log_paths.ingest', []);
        $files = array_map(
            static fn (string $path): string => basename($path),
            is_array($paths) ? $paths : [],
        );

        $this->assertContains('indexer_worker.log', $files);
        $this->assertContains('ingestion_worker.log', $files);
    }
}
