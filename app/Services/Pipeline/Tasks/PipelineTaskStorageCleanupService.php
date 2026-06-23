<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class PipelineTaskStorageCleanupService
{
    public function __construct(
        private Filesystem $files,
        private ConfigRepository $config,
        private PipelineTaskRepository $tasks,
        private IngestionSourceRepository $sources,
    ) {
    }

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

        foreach ($this->sourceIds($task) as $sourceId) {
            if (! $this->sourceWorkspaceCanBeDeleted($sourceId, (string) $task->task_id)) {
                $result['skipped'][] = [
                    'path' => $this->sourceRoot($sourceId),
                    'reason' => 'source is still referenced by another task or scheduled source',
                ];

                continue;
            }

            $this->deleteDirectory($this->sourceRoot($sourceId), $result);
            $this->sources->deleteIfOwnedByTask($sourceId, (string) $task->task_id);
        }

        $result['ok'] = $result['failed'] === [];

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

    private function sourceWorkspaceCanBeDeleted(string $sourceId, string $taskId): bool
    {
        if ($this->tasks->sourceHasJobsOutsideTask($sourceId, $taskId)) {
            return false;
        }

        $source = $this->sources->findBySourceId($sourceId);
        if ($source === null) {
            return true;
        }

        return (string) $source->task_id === $taskId
            && ! $this->filledString($source->refresh_cadence)
            && ! $this->filledString($source->temporal_schedule_id);
    }

    /**
     * @param array{ok: bool, deleted: list<string>, skipped: list<array{path: string, reason: string}>, failed: list<array{path: string, message: string}>} $result
     */
    private function deleteDirectory(string $path, array &$result): void
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '') {
            return;
        }

        if (! $this->isSafeSharedPath($path)) {
            $result['failed'][] = [
                'path' => $path,
                'message' => 'refusing to delete a path outside configured shared storage',
            ];

            return;
        }

        if (! $this->files->exists($path)) {
            $result['skipped'][] = [
                'path' => $path,
                'reason' => 'missing',
            ];

            return;
        }

        if (! $this->files->isDirectory($path)) {
            $result['failed'][] = [
                'path' => $path,
                'message' => 'expected a directory',
            ];

            return;
        }

        try {
            $deleted = $this->files->deleteDirectory($path);
        } catch (\Throwable $exception) {
            $result['failed'][] = [
                'path' => $path,
                'message' => $exception->getMessage(),
            ];

            return;
        }

        if (! $deleted || $this->files->exists($path)) {
            $result['failed'][] = [
                'path' => $path,
                'message' => 'directory could not be removed',
            ];

            return;
        }

        $result['deleted'][] = $path;
    }

    private function taskRoot(PipelineTask $task): string
    {
        return $this->sharedRoot().DIRECTORY_SEPARATOR.(string) $task->task_id;
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function sourceRoot(string $sourceId): string
    {
        return $this->sharedRoot().DIRECTORY_SEPARATOR.'sources'.DIRECTORY_SEPARATOR.$sourceId;
    }

    private function sharedRoot(): string
    {
        return rtrim((string) $this->config->get('temporal.storage.shared_root', '/shared'), DIRECTORY_SEPARATOR);
    }

    private function isSafeSharedPath(string $path): bool
    {
        $normalizedPath = $this->normalizePath($path);
        if ($normalizedPath === null) {
            return false;
        }

        foreach ($this->sharedRoots() as $root) {
            if ($normalizedPath !== $root && str_starts_with($normalizedPath, $root.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function sharedRoots(): array
    {
        return collect([
            $this->config->get('temporal.storage.shared_root', '/shared'),
            $this->config->get('config.shared_root', '/shared'),
            '/shared',
            '/app/shared',
        ])
            ->filter(fn (mixed $path): bool => is_scalar($path) && trim((string) $path) !== '')
            ->map(fn (mixed $path): ?string => $this->normalizePath((string) $path))
            ->filter(fn (?string $path): bool => $path !== null)
            ->unique()
            ->values()
            ->all();
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
