<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Recovery;

use App\Models\PipelineJob;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use App\Services\Pipeline\Repositories\Queries\FailedPipelineJobsQuery;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class PipelineRecoveryRetryCoordinator
{
    public function __construct(
        private PipelineRecoveryInputNormalizer $input,
        private ActivePipelineJobsQuery $activeJobs,
        private FailedPipelineJobsQuery $failedJobs,
        private PipelineRecoveryAttemptService $attempts,
    ) {
    }

    /**
     * @param list<mixed> $jobIds
     * @return array<string, mixed>
     */
    public function retrySelected(array $jobIds): array
    {
        $jobIds = $this->input->jobIds($jobIds);

        if ($jobIds === []) {
            return $this->emptyResult('selected');
        }

        return $this->retryJobs($this->activeJobs->findByJobIds($jobIds), 'selected');
    }

    /**
     * @return array<string, mixed>
     */
    public function retryJob(string $jobId): array
    {
        $job = $this->activeJobs->findByJobId($jobId);

        return $this->retryJobs($job ? collect([$job]) : collect(), 'job', $jobId);
    }

    /**
     * @return array<string, mixed>
     */
    public function retryAll(): array
    {
        return $this->retryJobs($this->failedJobs->forRecovery(), 'all');
    }

    /**
     * @return array<string, mixed>
     */
    public function retryTask(string $taskId): array
    {
        return $this->retryJobs($this->failedJobs->forRecovery($taskId), 'task', $taskId);
    }

    /**
     * @return array<string, mixed>
     */
    public function retryHeap(string $heapId): array
    {
        return $this->retryJobs($this->failedJobs->forRecovery(null, $heapId), 'heap', $heapId);
    }

    /**
     * @param Collection<int, PipelineJob> $jobs
     * @return array<string, mixed>
     */
    private function retryJobs(Collection $jobs, string $scope, ?string $scopeId = null): array
    {
        $result = $this->emptyResult($scope, $scopeId);

        foreach ($jobs as $job) {
            $attempt = $this->attempts->retry($job, $scope, $scopeId);
            $result['jobs'][] = $attempt;
            $result['attempted']++;
            $result[$attempt['result']] = ($result[$attempt['result']] ?? 0) + 1;
        }

        return $result;
    }

    /**
     * @return array{scope:string,scopeId:string|null,attempted:int,retried:int,skipped:int,failed:int,jobs:list<array<string, mixed>>}
     */
    private function emptyResult(string $scope, ?string $scopeId = null): array
    {
        return [
            'scope' => $scope,
            'scopeId' => $scopeId,
            'attempted' => 0,
            'retried' => 0,
            'skipped' => 0,
            'failed' => 0,
            'jobs' => [],
        ];
    }
}
