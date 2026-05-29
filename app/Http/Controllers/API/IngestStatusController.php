<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Pipeline\PipelineStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class IngestStatusController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $mode = (string) $request->query('mode', 'default');
        if (!in_array($mode, ['default', 'neo4j'], true)) {
            $mode = 'default';
        }
        if ($mode === 'neo4j') {
            $statusPath = (string) config('config.ingest_status_path_neo4j', storage_path('logs/ingest_status_neo4j.json'));
            $logPath = (string) config('config.ingest_log_cache_path_neo4j', storage_path('logs/ingest_progress_neo4j_cache.log'));
        } else {
            $statusPath = (string) config('config.ingest_status_path', storage_path('logs/ingest_status.json'));
            $logPath = (string) config('config.ingest_log_cache_path', storage_path('logs/ingest_progress_cache.log'));
        }

        $status = null;
        $ingests = null;
        $statusIndex = null;
        if (is_file($statusPath)) {
            $raw = @file_get_contents($statusPath);
            $decoded = $raw ? json_decode($raw, true) : null;
            if (is_array($decoded) && array_key_exists('ingests', $decoded) && is_array($decoded['ingests'])) {
                $ingests = $decoded['ingests'];
                if ($ingests) {
                    $statusIndex = count($ingests) - 1;
                    $status = $ingests[$statusIndex];
                }
            } else {
                $status = $decoded;
            }
        }

        $lines = $this->tailLines($logPath, 40);
        if (is_array($status) && $lines) {
            $last = $lines[count($lines) - 1];
            $exitCode = $this->extractExitCode($lines);
            if ($last === 'INGEST_DONE' || $last === 'INGEST_FAILED') {
                $status['status'] = $last === 'INGEST_DONE' ? 'completed' : 'failed';
                if ($exitCode !== null) {
                    $status['exit_code'] = $exitCode;
                }
                $status['updated_at'] = now()->toIso8601String();
                $this->syncPipelineIngestStatus($status);
                if (is_array($ingests) && $statusIndex !== null) {
                    $ingests[$statusIndex] = $status;
                    File::put($statusPath, json_encode(['ingests' => $ingests], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            }
        }

        $progress = $this->extractProgress($lines);
        if (is_array($status) && $progress) {
            $status['progress'] = array_merge($status['progress'] ?? [], $progress);
        }

        return response()->json([
            'ok' => true,
            'status' => $status,
            'log_lines' => $lines,
        ]);
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
            app(PipelineStateService::class)->completeStage($pipelineJobId, PipelineStateService::STAGE_INGEST, $payload);
            return;
        }

        if (($status['status'] ?? null) === 'failed') {
            app(PipelineStateService::class)->failStage($pipelineJobId, PipelineStateService::STAGE_INGEST, array_merge($payload, [
                'errors' => [[
                    'message' => $status['last_line'] ?? 'Ingest process failed.',
                    'updatedAt' => now()->toIso8601String(),
                ]],
            ]));
        }
    }

    public function clear(Request $request): JsonResponse
    {
        $mode = (string) $request->query('mode', 'default');
        $targets = [];
        if ($mode === 'all' || $mode === 'both') {
            $targets[] = [
                (string) config('config.ingest_status_path', storage_path('logs/ingest_status.json')),
                (string) config('config.ingest_log_cache_path', storage_path('logs/ingest_progress_cache.log')),
            ];
            $targets[] = [
                (string) config('config.ingest_status_path_neo4j', storage_path('logs/ingest_status_neo4j.json')),
                (string) config('config.ingest_log_cache_path_neo4j', storage_path('logs/ingest_progress_neo4j_cache.log')),
            ];
        } else {
            if (!in_array($mode, ['default', 'neo4j'], true)) {
                $mode = 'default';
            }
            if ($mode === 'neo4j') {
                $targets[] = [
                    (string) config('config.ingest_status_path_neo4j', storage_path('logs/ingest_status_neo4j.json')),
                    (string) config('config.ingest_log_cache_path_neo4j', storage_path('logs/ingest_progress_neo4j_cache.log')),
                ];
            } else {
                $targets[] = [
                    (string) config('config.ingest_status_path', storage_path('logs/ingest_status.json')),
                    (string) config('config.ingest_log_cache_path', storage_path('logs/ingest_progress_cache.log')),
                ];
            }
        }

        foreach ($targets as [$statusPath, $logPath]) {
            if (is_file($statusPath)) {
                @unlink($statusPath);
            }
            if (is_file($logPath)) {
                @unlink($logPath);
            }
        }

        return response()->json(['ok' => true]);
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
