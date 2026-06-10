<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Values;

use App\Models\PipelineJob;
use App\Models\PipelineTask;

readonly class PipelineUploadPublishOutcome
{
    private function __construct(
        public bool $published,
        public PipelineTask $task,
        public PipelineJob $job,
        public ?\Throwable $exception,
    ) {
    }

    public static function published(PipelineTask $task, PipelineJob $job): self
    {
        return new self(true, $task, $job, null);
    }

    public static function failed(PipelineTask $task, PipelineJob $job, \Throwable $exception): self
    {
        return new self(false, $task, $job, $exception);
    }
}
