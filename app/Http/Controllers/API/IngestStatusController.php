<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class IngestStatusController extends Controller
{
    public function show(): JsonResponse
    {
        $statusPath = (string) config('hawki_rag.ingest_status_path', storage_path('logs/ingest_status.json'));
        $logPath = (string) config('hawki_rag.ingest_log_path', storage_path('logs/ingest_progress.log'));

        $status = null;
        if (is_file($statusPath)) {
            $raw = @file_get_contents($statusPath);
            $decoded = $raw ? json_decode($raw, true) : null;
            if (is_array($decoded) && array_key_exists('ingests', $decoded) && is_array($decoded['ingests'])) {
                $ingests = $decoded['ingests'];
                if ($ingests) {
                    $status = $ingests[count($ingests) - 1];
                }
            } else {
                $status = $decoded;
            }
        }

        $lines = $this->tailLines($logPath, 40);
        if (is_array($status) && $lines) {
            $last = $lines[count($lines) - 1];
            if ($last === 'INGEST_DONE') {
                $status['status'] = 'completed';
                $status['updated_at'] = now()->toIso8601String();
                File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

    public function clear(): JsonResponse
    {
        $statusPath = (string) config('hawki_rag.ingest_status_path', storage_path('logs/ingest_status.json'));
        $logPath = (string) config('hawki_rag.ingest_log_path', storage_path('logs/ingest_progress.log'));

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
