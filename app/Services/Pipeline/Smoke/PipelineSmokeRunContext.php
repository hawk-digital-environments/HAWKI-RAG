<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

readonly class PipelineSmokeRunContext
{
    public function __construct(
        public string $taskId,
        public string $datasetId,
        public bool $graph,
        public int $timeout,
        public bool $keepFiles,
        public string $sourceUrl,
        public string $fixtureDir,
    ) {
    }
}
