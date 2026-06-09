<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use App\Services\DirectIngest\Values\DirectIngestActionResult;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class DirectIngestStatusService
{
    public function __construct(
        private DirectIngestStatusStore $statuses,
        private DirectIngestLogReader $logs,
        private DirectIngestPipelineStatusSyncer $pipelineStatus,
        private Filesystem $files,
        private ClockInterface $clock = new Clock(),
    ) {}

    public function show(string $mode): DirectIngestActionResult
    {
        $paths = $this->statuses->paths($mode);
        [$status, $statusIndex] = $this->statuses->latest($paths->statusPath);
        $lines = $this->logs->tailLines($paths->cacheLogPath, 40);

        if (is_array($status) && $lines) {
            $status = $this->syncTerminalStatus($paths->statusPath, $status, $statusIndex, $lines);
        }

        $progress = $this->logs->progress($lines);
        if (is_array($status) && $progress) {
            $status['progress'] = array_merge($status['progress'] ?? [], $progress);
        }

        return DirectIngestActionResult::fromPayload([
            'ok' => true,
            'status' => $status,
            'log_lines' => $lines,
        ]);
    }

    public function clear(string $mode): DirectIngestActionResult
    {
        foreach ($this->clearTargets($mode) as $paths) {
            if ($this->files->isFile($paths->statusPath)) {
                $this->files->delete($paths->statusPath);
            }
            if ($this->files->isFile($paths->cacheLogPath)) {
                $this->files->delete($paths->cacheLogPath);
            }
        }

        return DirectIngestActionResult::fromPayload(['ok' => true]);
    }

    private function syncTerminalStatus(string $statusPath, array $status, ?int $statusIndex, array $lines): array
    {
        $last = $lines[count($lines) - 1];
        if ($last !== 'INGEST_DONE' && $last !== 'INGEST_FAILED') {
            return $status;
        }

        $exitCode = $this->logs->exitCode($lines);
        $status['status'] = $last === 'INGEST_DONE' ? 'completed' : 'failed';
        if ($exitCode !== null) {
            $status['exit_code'] = $exitCode;
        }
        $status['updated_at'] = $this->timestamp();

        $this->pipelineStatus->sync($status);
        if ($statusIndex !== null) {
            $this->statuses->replaceAt($statusPath, $statusIndex, $status);
        }

        return $status;
    }

    private function clearTargets(string $mode): array
    {
        if ($mode === 'all' || $mode === 'both') {
            return [
                $this->statuses->paths('default'),
                $this->statuses->paths('neo4j'),
            ];
        }

        return [$this->statuses->paths($mode)];
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }
}
