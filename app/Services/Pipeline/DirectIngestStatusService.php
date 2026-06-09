<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Pipeline\Values\DirectIngestActionResult;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DirectIngestStatusService
{
    public function __construct(
        private DirectIngestStatusStore $statuses,
        private PipelineStateService $pipelineState,
    ) {
    }

    public function show(string $mode): DirectIngestActionResult
    {
        $paths = $this->statuses->paths($mode);
        [$status, $statusIndex] = $this->statuses->latest($paths->statusPath);
        $lines = $this->tailLines($paths->cacheLogPath, 40);

        if (is_array($status) && $lines) {
            $status = $this->syncTerminalStatus($paths->statusPath, $status, $statusIndex, $lines);
        }

        $progress = $this->extractProgress($lines);
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
            if (is_file($paths->statusPath)) {
                @unlink($paths->statusPath);
            }
            if (is_file($paths->cacheLogPath)) {
                @unlink($paths->cacheLogPath);
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

        $exitCode = $this->extractExitCode($lines);
        $status['status'] = $last === 'INGEST_DONE' ? 'completed' : 'failed';
        if ($exitCode !== null) {
            $status['exit_code'] = $exitCode;
        }
        $status['updated_at'] = now()->toIso8601String();

        $this->syncPipelineIngestStatus($status);
        if ($statusIndex !== null) {
            $this->statuses->replaceAt($statusPath, $statusIndex, $status);
        }

        return $status;
    }

    private function syncPipelineIngestStatus(array $status): void
    {
        $pipelineJobId = (string) ($status['pipeline_job_id'] ?? '');
        if ($pipelineJobId === '') {
            return;
        }

        $payload = [
            'dataset_path' => $status['path'] ?? null,
            'counts' => [
                'total' => 0,
                'received' => 0,
                'processing' => 0,
                'completed' => ($status['status'] ?? null) === 'completed' ? 1 : 0,
                'failed' => ($status['status'] ?? null) === 'failed' ? 1 : 0,
            ],
            'metadata' => [
                'mode' => 'direct-ui',
                'pid' => $status['pid'] ?? null,
                'collection' => $status['collection'] ?? null,
                'lastLine' => $status['last_line'] ?? null,
                'exitCode' => $status['exit_code'] ?? null,
            ],
        ];

        if (($status['status'] ?? null) === 'completed') {
            $this->pipelineState->completeStage($pipelineJobId, PipelineStateService::STAGE_INGEST, $payload);
            return;
        }

        if (($status['status'] ?? null) === 'failed') {
            $this->pipelineState->failStage($pipelineJobId, PipelineStateService::STAGE_INGEST, array_merge($payload, [
                'errors' => [[
                    'message' => $status['last_line'] ?? 'Ingest process failed.',
                    'updatedAt' => now()->toIso8601String(),
                ]],
            ]));
        }
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

    private function tailLines(string $path, int $count): array
    {
        if (!is_file($path)) {
            return [];
        }

        $count = max(1, $count);
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $start = max(0, $lastLine - $count);
        $lines = [];
        for ($i = $start; $i <= $lastLine; $i++) {
            $file->seek($i);
            $line = trim((string) $file->current());
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function extractExitCode(array $lines): ?int
    {
        foreach (array_reverse($lines) as $line) {
            if (preg_match('/^INGEST_EXIT_CODE=(\\d+)$/', $line, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private function extractProgress(array $lines): array
    {
        $progress = [];
        foreach (array_reverse($lines) as $line) {
            if (!isset($progress['folders']) && preg_match('/Folder\\s+(\\d+)[\\/](\\d+)/', $line, $m)) {
                $progress['folders'] = [
                    'current' => (int) $m[1],
                    'total' => (int) $m[2],
                ];
            }
            if (!isset($progress['docs']) && preg_match('/Sent\\s+(\\d+)[\\/](\\d+)\\s+docs/i', $line, $m)) {
                $progress['docs'] = [
                    'sent' => (int) $m[1],
                    'total' => (int) $m[2],
                ];
            }
            if (!isset($progress['docs']) && preg_match('/Planned\\s+(\\d+)[\\/](\\d+)\\s+docs/i', $line, $m)) {
                $progress['docs'] = [
                    'sent' => (int) $m[1],
                    'total' => (int) $m[2],
                    'mode' => 'dry',
                ];
            }
            if (count($progress) >= 2) {
                break;
            }
        }

        return $progress;
    }
}
