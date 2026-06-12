<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Uploads;

use App\Services\Dataset\DatasetService;
use App\Services\Pipeline\Exceptions\PipelineUploadStorageException;
use App\Services\Pipeline\Repositories\PipelineJobCreationRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Values\PipelineUploadInput;
use App\Services\Pipeline\Values\PipelineUploadResult;
use Illuminate\Container\Attributes\Singleton;
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
        private readonly PipelineTaskRepository $taskRepository,
        private readonly PipelineJobCreationRepository $jobCreation,
        private readonly PipelineUploadStorage $storage,
        private readonly PipelineUploadPolicy $policy,
        private readonly PipelineUploadIdentifierFactory $identifiers,
        private readonly PipelineUploadPayloadService $payloads,
        private readonly PipelineUploadResultFactory $results,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock = new Clock(),
    ) {}

    public function upload(PipelineUploadInput $input, ?UploadedFile $file): PipelineUploadResult
    {
        if (! $file || ! $file->isValid()) {
            return $this->results->unreadableFile();
        }

        $extension = $this->storage->extensionFor($file);

        if (! $this->policy->supports($extension)) {
            return $this->results->unsupportedFile();
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

            return $this->results->storageFailure($input, $exception);
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

        $this->logger->info('Pipeline controller upload stored as Laravel metadata.', [
            'task_id' => $task->task_id,
            'job_id' => $job->job_id,
            'source_url' => $sourceUrl,
            'local_path' => $storedUpload->localPath,
            'orchestration' => 'temporal',
        ]);

        return $this->results->success($task, $job);
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
