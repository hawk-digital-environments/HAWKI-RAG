<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Uploads;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Exceptions\PipelineUploadStorageException;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use App\Services\Pipeline\Values\PipelineUploadInput;
use App\Services\Pipeline\Values\PipelineUploadResult;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Routing\UrlGenerator;

#[Singleton]
readonly class PipelineUploadResultFactory
{
    public function __construct(
        private PipelineTaskService $tasks,
        private PipelineUploadPolicy $policy,
        private UrlGenerator $urls,
    ) {
    }

    public function unreadableFile(): PipelineUploadResult
    {
        return PipelineUploadResult::fromPayload([
            'success' => false,
            'message' => 'Upload a readable document file.',
        ], 422);
    }

    public function unsupportedFile(PipelineUploadInput $input): PipelineUploadResult
    {
        return PipelineUploadResult::fromPayload([
            'success' => false,
            'message' => $this->policy->unsupportedMessage($input),
            'acceptedExtensions' => $this->policy->supportedExtensions(),
        ], 422);
    }

    public function customConverterProfileFailure(
        PipelineUploadInput $input,
        \Throwable $exception,
    ): PipelineUploadResult {
        return PipelineUploadResult::fromPayload([
            'success' => false,
            'message' => 'Custom converter profile could not be prepared for this upload.',
            'datasetId' => $input->datasetId,
            'taskId' => null,
            'jobId' => null,
            'error' => $exception->getMessage(),
        ], 500);
    }

    public function storageFailure(
        PipelineUploadInput $input,
        PipelineUploadStorageException $exception,
    ): PipelineUploadResult {
        return PipelineUploadResult::fromPayload([
            'success' => false,
            'message' => $exception->responseMessage(),
            'datasetId' => $input->datasetId,
            'taskId' => null,
            'jobId' => null,
            'error' => $exception->getMessage(),
        ], 500);
    }

    public function success(PipelineTask $task, PipelineJob $job): PipelineUploadResult
    {
        return PipelineUploadResult::fromPayload([
            'success' => true,
            'taskId' => $task->task_id,
            'jobId' => $job->job_id,
            'datasetId' => $task->dataset_id,
            'task' => $this->tasks->show($task->task_id),
            'dashboardUrl' => $this->urls->to('/pipeline-controller'),
            'controllerUrl' => $this->urls->to('/pipeline-controller'),
        ], 201);
    }
}
