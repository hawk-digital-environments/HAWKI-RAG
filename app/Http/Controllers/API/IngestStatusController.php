<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
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
            $statusPath = (string) config('hawki_rag.ingest_status_path_neo4j', storage_path('logs/ingest_status_neo4j.json'));
            $logPath = (string) config('hawki_rag.ingest_log_path_neo4j', storage_path('logs/ingest_progress_neo4j.log'));
        } else {
            $statusPath = (string) config('hawki_rag.ingest_status_path', storage_path('logs/ingest_status.json'));
            $logPath = (string) config('hawki_rag.ingest_log_path', storage_path('logs/ingest_progress.log'));
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
            if ($last === 'INGEST_DONE' || $last === 'INGEST_FAILED') {
                $status['status'] = $last === 'INGEST_DONE' ? 'completed' : 'failed';
                $status['updated_at'] = now()->toIso8601String();
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

    public function clear(Request $request): JsonResponse
    {
        $mode = (string) $request->query('mode', 'default');
        if (!in_array($mode, ['default', 'neo4j'], true)) {
            $mode = 'default';
        }
        if ($mode === 'neo4j') {
            $statusPath = (string) config('hawki_rag.ingest_status_path_neo4j', storage_path('logs/ingest_status_neo4j.json'));
            $logPath = (string) config('hawki_rag.ingest_log_path_neo4j', storage_path('logs/ingest_progress_neo4j.log'));
        } else {
            $statusPath = (string) config('hawki_rag.ingest_status_path', storage_path('logs/ingest_status.json'));
            $logPath = (string) config('hawki_rag.ingest_log_path', storage_path('logs/ingest_progress.log'));
        }

        if (is_file($statusPath)) {
            @unlink($statusPath);
        }
        if (is_file($logPath)) {
            @unlink($logPath);
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
