<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use App\Models\PipelineJob;
use App\Models\PipelineTask;

readonly class PipelineSmokeBootstrapResult
{
    public function __construct(
        public string $fixturePath,
        public PipelineTask $task,
        public PipelineJob $scrapeJob,
    ) {
    }
}
