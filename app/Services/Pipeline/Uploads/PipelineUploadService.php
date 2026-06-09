<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Uploads;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Dataset\DatasetService;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Exceptions\PipelineUploadStorageException;
use App\Services\Pipeline\Repositories\PipelineJobCreationRepository;
use App\Services\Pipeline\Repositories\PipelineJobStateMutationRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use App\Services\Pipeline\Values\PipelineUploadInput;
use App\Services\Pipeline\Values\PipelineUploadResult;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
class PipelineUploadService
{
    public function __construct(
        private readonly DatasetService $datasets,
        private readonly PipelineTaskService $tasks,
        private readonly PipelineEventBus $events,
        private readonly PipelineTaskRepository $taskRepository,
        private readonly PipelineJobCreationRepository $jobCreation,
        private readonly PipelineJobStateMutationRepository $jobStates,
        private readonly PipelineUploadStorage $storage,
        private readonly PipelineUploadPolicy $policy,
        private readonly PipelineUploadIdentifierFactory $identifiers,
        private readonly PipelineUploadPayloadService $payloads,
        private readonly LoggerInterface $logger,
        private readonly UrlGenerator $urls,
        private readonly ClockInterface $clock = new Clock(),
    ) {}

    public function upload(PipelineUploadInput $input, ?UploadedFile $file): PipelineUploadResult
    {
        if (! $file || ! $file->isValid()) {
            return $this->unreadableFileResult();
        }

        $extension = $this->storage->extensionFor($file);

        if (! $this->policy->supports($extension)) {
            return $this->unsupportedFileResult();
        }

        $taskId = $this->identifiers->uploadTaskId();

        try {
            $storedUpload = $this->storage->store($taskId, $file, $extension);
        } catch (PipelineUploadStorageException $exception) {
            $this->logger->warning($exception->logMessage(), array_merge([
                'dataset_id' => $input->datasetId,
                'task_id' => $taskId,
                'error' => $exception->getMessage(),
            ], $exception->logContext()));

            return $this->storageFailureResult($input, $exception);
        }

        $dataset = $this->datasets->ensure($input->datasetId);
        $jobId = $this->identifiers->convertJobId($taskId, $storedUpload);
        $sourceUrl = $this->identifiers->sourceUrl($storedUpload);
        $now = $this->now();

        $task = $this->taskRepository->createUploadTask(
            $taskId,
            $dataset,
            $now,
            $this->payloads->taskMetadata($dataset, $input, $storedUpload),
        );

        $metadata = $this->payloads->jobMetadata($dataset, $input, $storedUpload);

        $job = $this->jobCreation->createUploadConvertJob(
            $jobId,
            $task,
            $sourceUrl,
            $storedUpload,
            $now,
            $metadata,
        );

        $payload = $this->payloads->fileDiscovered($task, $job, $sourceUrl, $storedUpload, $metadata);

        try {
            $this->events->publish(PipelineEvent::FILE_DISCOVERED, $payload);
        } catch (\Throwable $exception) {
            $failedAt = $this->now();
            $job = $this->jobStates->markFailed(
                $job,
                'Unable to publish file.discovered event: '.$exception->getMessage(),
                $failedAt,
            );
            $task = $this->taskRepository->markFailed($task, $failedAt);

            $this->logger->warning('Pipeline controller file upload event publish failed.', [
                'task_id' => $task->task_id,
                'job_id' => $job->job_id,
                'error' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return $this->publishFailureResult($task, $job, $exception);
        }

        return $this->successResult($task, $job);
    }

    private function unreadableFileResult(): PipelineUploadResult
    {
        return PipelineUploadResult::fromPayload([
            'success' => false,
            'message' => 'Upload a readable document file.',
        ], 422);
    }

    private function unsupportedFileResult(): PipelineUploadResult
    {
        return PipelineUploadResult::fromPayload([
            'success' => false,
            'message' => $this->policy->unsupportedMessage(),
        ], 422);
    }

    private function storageFailureResult(
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

    private function publishFailureResult(
        PipelineTask $task,
        PipelineJob $job,
        \Throwable $exception,
    ): PipelineUploadResult {
        return PipelineUploadResult::fromPayload([
            'success' => false,
            'message' => 'The file was stored, but RabbitMQ did not accept the converter job.',
            'taskId' => $task->task_id,
            'jobId' => $job->job_id,
            'datasetId' => $task->dataset_id,
            'error' => $exception->getMessage(),
            'dashboardUrl' => $this->dashboardUrl($task),
        ], 502);
    }

    private function successResult(PipelineTask $task, PipelineJob $job): PipelineUploadResult
    {
        return PipelineUploadResult::fromPayload([
            'success' => true,
            'taskId' => $task->task_id,
            'jobId' => $job->job_id,
            'datasetId' => $task->dataset_id,
            'task' => $this->tasks->show($task->task_id),
            'dashboardUrl' => $this->dashboardUrl($task),
            'controllerUrl' => $this->urls->to('/pipeline-controller'),
        ], 201);
    }

    private function dashboardUrl(PipelineTask $task): string
    {
        return $this->urls->to('/pipeline-dashboard?task_id='.rawurlencode($task->task_id));
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
