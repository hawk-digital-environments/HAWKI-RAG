<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class PipelineTaskStorageCleanupService
{
    public function __construct(
        private Filesystem $files,
        private ConfigRepository $config,
        private PipelineTaskRepository $tasks,
        private IngestionSourceRepository $sources,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{ok: bool, deleted: list<string>, skipped: list<array{path: string, reason: string}>, failed: list<array{path: string, message: string}>}
     */
    public function deleteForTask(PipelineTask $task): array
    {
        $result = [
            'ok' => true,
            'deleted' => [],
            'skipped' => [],
            'failed' => [],
        ];

        $this->deleteDirectory($this->taskRoot($task), $result);

        foreach ($this->jobWorkspaceRoots($task) as $jobRoot) {
            $this->deleteDirectory($jobRoot, $result);
        }

        foreach ($this->sourceIds($task) as $sourceId) {
            $source = $this->sources->findBySourceId($sourceId);
            $sourceRoot = $this->sourceRoot($sourceId, $source);
            if (! $this->sourceWorkspaceCanBeDeleted($sourceId, (string) $task->task_id, $source)) {
                $result['skipped'][] = [
                    'path' => $sourceRoot ?? 'source:'.$sourceId,
                    'reason' => 'source is still referenced by another task or scheduled source',
                ];

                continue;
            }

            if ($sourceRoot === null) {
                $result['failed'][] = [
                    'path' => 'source:'.$sourceId,
                    'message' => 'persisted raw and Markdown paths do not share one source workspace',
                ];

                continue;
            }

            if ($this->deleteDirectory($sourceRoot, $result)) {
                $this->sources->deleteIfOwnedByTask($sourceId, (string) $task->task_id);
            }
        }

        $result['ok'] = $result['failed'] === [];
        if (! $result['ok']) {
            $this->logger->error('Pipeline task storage cleanup failed.', [
                'task_id' => (string) $task->task_id,
                'failed_paths' => array_column($result['failed'], 'path'),
            ]);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function sourceIds(PipelineTask $task): array
    {
        $jobs = $task->relationLoaded('jobs') ? $task->jobs : collect();

        return $jobs
            ->map(fn (PipelineJob $job): string => is_string($job->source_id) ? trim($job->source_id) : '')
            ->filter(fn (string $sourceId): bool => $sourceId !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function jobWorkspaceRoots(PipelineTask $task): array
    {
        $jobs = $task->relationLoaded('jobs') ? $task->jobs : collect();

        return $jobs
            ->map(fn (PipelineJob $job): string => is_string($job->job_id) ? trim($job->job_id) : '')
            ->filter(fn (string $jobId): bool => $jobId !== '')
            ->map(fn (string $jobId): string => $this->sharedRoot().DIRECTORY_SEPARATOR.$jobId)
            ->unique()
            ->values()
            ->all();
    }

    private function sourceWorkspaceCanBeDeleted(
        string $sourceId,
        string $taskId,
        ?IngestionSource $source,
    ): bool {
        if ($this->tasks->sourceHasJobsOutsideTask($sourceId, $taskId)) {
            return false;
        }

        if ($source === null) {
            return true;
        }

        return (string) $source->task_id === $taskId
            && ! $this->filledString($source->refresh_cadence)
            && ! $this->filledString($source->temporal_schedule_id);
    }

    /**
     * @param  array{ok: bool, deleted: list<string>, skipped: list<array{path: string, reason: string}>, failed: list<array{path: string, message: string}>}  $result
     */
    private function deleteDirectory(string $path, array &$result): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '') {
            return false;
        }

        if (! $this->isSafeSharedPath($path)) {
            $result['failed'][] = [
                'path' => $path,
                'message' => 'refusing to delete a path outside configured shared storage',
            ];

            return false;
        }

        if (! $this->files->exists($path)) {
            $result['skipped'][] = [
                'path' => $path,
                'reason' => 'missing',
            ];

            return true;
        }

        if (! $this->files->isDirectory($path)) {
            $result['failed'][] = [
                'path' => $path,
                'message' => 'expected a directory',
            ];

            return false;
        }

        try {
            $deleted = $this->files->deleteDirectory($path);
        } catch (\Throwable $exception) {
            $result['failed'][] = [
                'path' => $path,
                'message' => $exception->getMessage(),
            ];

            return false;
        }

        if (! $deleted || $this->files->exists($path)) {
            $result['failed'][] = [
                'path' => $path,
                'message' => 'directory could not be removed',
            ];

            return false;
        }

        $result['deleted'][] = $path;

        return true;
    }

    private function taskRoot(PipelineTask $task): string
    {
        return $this->sharedRoot().DIRECTORY_SEPARATOR.(string) $task->task_id;
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function sourceRoot(string $sourceId, ?IngestionSource $source): ?string
    {
        if ($source === null) {
            return $this->sharedRoot().DIRECTORY_SEPARATOR.'sources'.DIRECTORY_SEPARATOR.$sourceId;
        }

        $roots = [];
        foreach ([$source->raw_storage_path, $source->markdown_storage_path] as $storagePath) {
            if (! $this->filledString($storagePath)) {
                continue;
            }

            $roots[] = dirname(rtrim((string) $storagePath, DIRECTORY_SEPARATOR));
        }
        $roots = array_values(array_unique($roots));

        if ($roots === []) {
            return $this->sharedRoot().DIRECTORY_SEPARATOR.'sources'.DIRECTORY_SEPARATOR.$sourceId;
        }

        return count($roots) === 1 ? $roots[0] : null;
    }

    private function sharedRoot(): string
    {
        return rtrim((string) $this->config->get('temporal.storage.shared_root', '/shared'), DIRECTORY_SEPARATOR);
    }

    private function isSafeSharedPath(string $path): bool
    {
        $normalizedPath = $this->normalizePath($path);
        $sharedRoot = $this->normalizePath($this->sharedRoot());
        if ($normalizedPath === null || $sharedRoot === null) {
            return false;
        }

        return $normalizedPath !== $sharedRoot
            && str_starts_with($normalizedPath, $sharedRoot.DIRECTORY_SEPARATOR);
    }

    private function normalizePath(string $path): ?string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '') {
            return null;
        }

        $realPath = realpath($path);
        if (is_string($realPath) && $realPath !== '') {
            return rtrim($realPath, DIRECTORY_SEPARATOR);
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }
}
