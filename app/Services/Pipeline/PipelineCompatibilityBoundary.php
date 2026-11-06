<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\User;
use App\Services\Pipeline\Clients\PythonUrlIngestionClient;
use App\Services\Pipeline\Recovery\PipelineRecoveryFailedJobPresenter;
use App\Services\Pipeline\Recovery\PipelineRecoveryService;
use App\Services\Pipeline\Repositories\PipelineOwnershipRepository;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Request;

#[Singleton]
readonly class PipelineCompatibilityBoundary
{
    public function __construct(
        private PythonUrlIngestionClient $python,
        private PipelineOwnership $ownership,
        private PipelineOwnershipRepository $legacy,
        private PipelineRecoveryService $recovery,
        private PipelineRecoveryFailedJobPresenter $failedJobs,
        private ActivePipelineJobsQuery $jobs,
        private PipelineTaskService $tasks,
    ) {}

    public function inventory(User $user, string $kind, array $filters): array
    {
        $limit = max(1, min($kind === 'tasks' ? 250 : 500, (int) ($filters['limit'] ?? ($kind === 'tasks' ? 30 : 100))));
        $path = $kind === 'tasks' ? 'pipeline/tasks' : 'pipeline/recovery/failed-jobs';
        $remote = $this->python->forward($user, 'GET', $path, [...$filters, 'limit' => $limit], null);
        if ($remote['status'] !== 200) {
            return $remote;
        }
        if (! is_array($remote['payload'][$kind] ?? null)) {
            return $this->failure(502, 'Invalid pipeline projection.');
        }
        $field = $kind === 'tasks' ? 'task_id' : 'job_id';
        $candidates = $kind === 'tasks' ? $this->legacy->tasks() : $this->legacy->jobs($filters['task_id'] ?? $filters['taskId'] ?? null, $filters['dataset_id'] ?? $filters['datasetId'] ?? null, recoverableOnly: true);
        $access = $this->access($user, $candidates, $field);
        if ($access['status'] !== 200) {
            return $access;
        }
        $rows = $remote['payload'][$kind];
        $seen = array_fill_keys(array_column($rows, $field), true);
        foreach ($candidates as $candidate) {
            $identity = $candidate[$field];
            if (! in_array($identity, $access['payload']['identities'], true) || isset($seen[$identity])) {
                continue;
            }
            if ($kind === 'tasks') {
                $row = $this->tasks->show($identity);
            } else {
                $job = $this->jobs->findWithTaskByJobId($identity);
                $row = $job && ($job->status === 'failed' || isset($job->metadata['pending_recovery'])) ? $this->failedJobs->present($job) : null;
            }
            if ($row !== null) {
                $rows[] = $row;
                $seen[$identity] = true;
            }
        }
        // Both backends use timestamp DESC, then durable public identity ASC.
        $time = $kind === 'tasks' ? 'started_at' : 'timestamp';
        usort($rows, static function (array $a, array $b) use ($field, $time): int {
            $left = empty($a[$time]) ? -INF : (float) (new \DateTimeImmutable($a[$time]))->format('U.u');
            $right = empty($b[$time]) ? -INF : (float) (new \DateTimeImmutable($b[$time]))->format('U.u');

            return ($right <=> $left) ?: strcmp($a[$field], $b[$field]);
        });

        return ['status' => 200, 'payload' => ['success' => true, $kind => array_slice($rows, 0, $limit)]];
    }

    /** Partition a mixed batch before dispatch; each ID is attempted at most once. */
    public function recover(User $user, Request $request): array
    {
        $path = substr($request->path(), strlen('api/'));
        $selected = str_ends_with($path, '/jobs/retry-selected');
        $single = is_string($request->route('jobId'));
        if ($selected) {
            $ids = $request->input('job_ids', $request->input('jobIds', []));
            if (! is_array($ids) || count($ids) > 1000 || array_filter($ids, static fn ($id): bool => ! is_string($id) || trim($id) === '' || strlen($id) > 191)) {
                return $this->failure(422, 'Invalid recovery job IDs.');
            }
        } elseif ($single) {
            $ids = [$request->route('jobId')];
        } else {
            $filters = array_filter(['task_id' => $request->route('taskId'), 'dataset_id' => $request->route('datasetId')]);
            $remote = $this->python->forward($user, 'GET', 'pipeline/recovery/candidates', $filters, null);
            if ($remote['status'] !== 200 || ! is_array($remote['payload']['jobs'] ?? null)) {
                return $remote['status'] !== 200 ? $remote : $this->failure(502, 'Invalid recovery candidates.');
            }
            $candidates = $this->legacy->jobs($filters['task_id'] ?? null, $filters['dataset_id'] ?? null, recoverableOnly: true);
            $access = $this->access($user, $candidates, 'job_id');
            if ($access['status'] !== 200) {
                return $access;
            }
            $ids = array_merge(array_column($remote['payload']['jobs'], 'job_id'), $access['payload']['identities']);
        }
        $ids = array_values(array_unique(array_map('trim', $ids)));
        $partition = [];
        foreach ($ids as $id) {
            $partition[$id] = $this->ownership->classify('job_id', $id);
        }
        $result = ['scope' => $selected ? 'selected' : ($single ? 'job' : 'mixed'), 'scopeId' => $request->route('jobId'), 'attempted' => 0, 'retried' => 0, 'skipped' => 0, 'failed' => 0, 'jobs' => []];
        foreach ($partition as $id => $owner) {
            $outcome = $owner;
            if ($owner['status'] === 200) {
                if (in_array($owner['payload']['owner'], ['url', 'managed', 'text'], true)) {
                    $outcome = $this->python->forward($user, 'POST', 'pipeline/recovery/jobs/'.rawurlencode($id).'/retry', [], $request->header('Idempotency-Key'));
                } elseif ($owner['payload']['owner'] === 'laravel') {
                    // Exact INGEST authorization precedes any legacy recovery.
                    $candidates = array_values(array_filter($this->legacy->jobs(), static fn ($row): bool => $row['job_id'] === $id));
                    $access = $this->access($user, $candidates, 'job_id');
                    if ($access['status'] !== 200 || ! in_array($id, $access['payload']['identities'] ?? [], true)) {
                        $outcome = $access['status'] !== 200 ? $access : $this->failure(404, 'Recovery job is unavailable.');
                    } else {
                        try {
                            $outcome = ['status' => 200, 'payload' => ['success' => true, 'recovery' => $this->recovery->retryJob($id)]];
                        } catch (\Throwable) {
                            $outcome = $this->failure(503, 'Legacy recovery is unconfirmed; reconcile this job.');
                        }
                    }
                } else {
                    $outcome = $this->failure(409, 'Use the owning document or text API for this operation.');
                }
            }
            if ($single && $outcome['status'] !== 200) {
                return $outcome;
            }
            $item = $outcome['status'] === 200 ? ($outcome['payload']['recovery'] ?? null) : null;
            if (! is_array($item)) {
                $result['attempted']++;
                $result['failed']++;
                $result['jobs'][] = ['job_id' => $id, 'result' => 'failed', 'status' => $outcome['status'], 'message' => $outcome['payload']['message'] ?? 'Invalid recovery response.'];

                continue;
            }
            foreach (['attempted', 'retried', 'skipped', 'failed'] as $count) {
                $result[$count] += (int) ($item[$count] ?? 0);
            }
            $result['jobs'] = array_merge($result['jobs'], $item['jobs'] ?? []);
        }

        return ['status' => 200, 'payload' => ['success' => true, 'recovery' => $result]];
    }

    public function access(User $user, array $candidates, string $field): array
    {
        $identities = [];
        foreach (array_chunk($candidates, 250) as $chunk) {
            $response = $this->python->forward($user, 'POST', 'pipeline/legacy-access', ['candidates' => array_map(static fn ($row): array => ['field' => $field, 'identity' => $row[$field], 'dataset_id' => $row['dataset_id']], $chunk)], null);
            if ($response['status'] !== 200 || ! is_array($response['payload']['identities'] ?? null)) {
                return $response['status'] !== 200 ? $response : $this->failure(502, 'Invalid legacy access response.');
            }
            $identities = array_merge($identities, $response['payload']['identities']);
        }

        return ['status' => 200, 'payload' => ['success' => true, 'identities' => $identities]];
    }

    private function failure(int $status, string $message): array
    {
        return ['status' => $status, 'payload' => ['success' => false, 'message' => $message]];
    }
}
