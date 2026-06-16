<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineIngestStatusService
{
    public function __construct(
        private PipelineStageEmptyResponseFactory $emptyStages,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stage(string $jobId, ?string $datasetPath): array
    {
        return $this->emptyStages->stage(
            'unknown',
            'Legacy RabbitMQ ingest state tracking has been removed. Use Temporal pipeline task and job status.',
        );
    }
}
