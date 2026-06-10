<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\EventHandlers\ConverterEventHandler;
use App\Services\Pipeline\Exceptions\PipelineSmokeException;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class PipelineSmokeConversionStage
{
    public function __construct(
        private PipelineSmokeEventFactory $eventFactory,
        private Filesystem $files,
    ) {
    }

    public function run(
        PipelineSmokeStageRunner $runner,
        PipelineSmokeRunContext $context,
        PipelineTask $task,
        PipelineJob $scrapeJob,
        string $fixturePath,
        ConverterEventHandler $converter,
        ActivePipelineJobsQuery $jobs,
    ): PipelineSmokeConversionResult {
        $convertJobId = $this->eventFactory->convertJobId($task->task_id, $fixturePath);
        $fileDiscovered = $this->eventFactory->fileDiscovered(
            $task,
            $scrapeJob,
            $convertJobId,
            $context->sourceUrl,
            $fixturePath,
            $context->graph,
        );

        $convertedPath = $runner->stage('Convert', function () use ($converter, $convertJobId, $fileDiscovered, $jobs): string {
            $converter->handle($fileDiscovered);
            $job = $jobs->findByJobId($convertJobId);
            $path = is_array($job?->metadata) ? (string) ($job->metadata['converted_path'] ?? '') : '';

            if (! $job || $job->status !== PipelineJob::STATUS_COMPLETED) {
                throw PipelineSmokeException::convertDidNotComplete($convertJobId);
            }

            if ($path === '' || ! $this->files->isFile($path) || trim($this->files->get($path)) === '') {
                throw PipelineSmokeException::convertMarkdownMissing($convertJobId);
            }

            return $path;
        }, fn (string $path): string => "Converted fixture to Markdown at {$path}.");

        return new PipelineSmokeConversionResult($convertJobId, $convertedPath);
    }
}
