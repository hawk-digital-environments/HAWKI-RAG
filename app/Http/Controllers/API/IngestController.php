<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class IngestController extends Controller
{
    public function folders(): JsonResponse
    {
        $root = (string) config('rawki.shared_root', storage_path('app/public'));
        if (!is_dir($root)) {
            $fallback = storage_path('app/public');
            if (is_dir($fallback)) {
                $root = $fallback;
            } else {
                return response()->json(['ok' => false, 'message' => "Shared root not found: {$root}"], 404);
            }
        }

        $dirs = File::directories($root);
        $folders = [];
        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (str_starts_with($name, '.')) {
                continue;
            }
            $folders[] = [
                'name' => $name,
                'path' => $dir,
            ];
        }

        usort($folders, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        return response()->json([
            'ok' => true,
            'root' => $root,
            'folders' => $folders,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => 'required|string',
            'collection' => 'sometimes|string',
            'provider' => 'sometimes|string',
            'graph' => 'sometimes|boolean',
            'graph_engine' => 'sometimes|string',
            'chunk_chars' => 'sometimes|integer',
            'chunk_overlap' => 'sometimes|integer',
            'batch' => 'sometimes|integer',
            'timeout' => 'sometimes|integer',
        ]);

        $root = (string) config('rawki.shared_root', '/app/shared');
        $path = $data['path'];
        if (!str_starts_with($path, $root)) {
            return response()->json(['ok' => false, 'message' => 'Path must be within shared root.'], 422);
        }
        if (!is_dir($path)) {
            return response()->json(['ok' => false, 'message' => "Path not found: {$path}"], 404);
        }

        $script = base_path('python_rag/ingest/ingest_crawled.py');
        if (!is_file($script)) {
            return response()->json(['ok' => false, 'message' => 'ingest_crawled.py not found'], 500);
        }

        $baseUrl = (string) env('RAWKI_BRIDGE_URL', 'http://rawki_bridge:8000');
        $statusPath = (string) config('rawki.ingest_status_path', storage_path('logs/ingest_status.json'));
        $logPath = (string) config('rawki.ingest_log_path', storage_path('logs/ingest_progress.log'));
        File::ensureDirectoryExists(dirname($statusPath));
        File::ensureDirectoryExists(dirname($logPath));

        $cmd = [
            'python3',
            $script,
            '--root', $path,
            '--base-url', $baseUrl,
        ];

        $collection = $data['collection'] ?? basename($path);
        if ($collection) {
            $cmd[] = '--collection';
            $cmd[] = (string) $collection;
        }

        if (!empty($data['provider'])) {
            $cmd[] = '--provider';
            $cmd[] = (string) $data['provider'];
        }
        if (!empty($data['graph_engine'])) {
            $cmd[] = '--graph-engine';
            $cmd[] = (string) $data['graph_engine'];
        }
        if (!empty($data['chunk_chars'])) {
            $cmd[] = '--chunk-chars';
            $cmd[] = (string) $data['chunk_chars'];
        }
        if (!empty($data['chunk_overlap'])) {
            $cmd[] = '--chunk-overlap';
            $cmd[] = (string) $data['chunk_overlap'];
        }
        if (!empty($data['batch'])) {
            $cmd[] = '--batch';
            $cmd[] = (string) $data['batch'];
        }
        if (!empty($data['timeout'])) {
            $cmd[] = '--timeout';
            $cmd[] = (string) $data['timeout'];
        }
        if (!empty($data['graph'])) {
            $cmd[] = '--graph';
        }

        $summaryPath = storage_path('logs/ingest_summary.json');
        $cmd[] = '--summary-file';
        $cmd[] = $summaryPath;

        $status = [
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'status' => 'running',
            'progress' => null,
            'last_line' => null,
            'summary_path' => $summaryPath,
            'command' => $cmd,
            'path' => $path,
        ];
        File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::append($logPath, 'INGEST_STARTED ' . $path . PHP_EOL);

        $escaped = array_map('escapeshellarg', $cmd);
        $commandLine = implode(' ', $escaped) . ' >> ' . escapeshellarg($logPath) . ' 2>&1; echo "INGEST_DONE" >> ' . escapeshellarg($logPath);
        $process = Process::fromShellCommandline($commandLine, base_path());
        $process->setTimeout(null);
        $process->start();

        $status['pid'] = $process->getPid();
        $status['updated_at'] = now()->toIso8601String();
        File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return response()->json([
            'ok' => true,
            'pid' => $process->getPid(),
            'status_path' => $statusPath,
            'log_path' => $logPath,
        ]);
    }

    public function stop(): JsonResponse
    {
        $statusPath = (string) config('rawki.ingest_status_path', storage_path('logs/ingest_status.json'));
        if (!is_file($statusPath)) {
            return response()->json(['ok' => false, 'message' => 'No ingest status found.'], 404);
        }

        $statusRaw = @file_get_contents($statusPath);
        $status = $statusRaw ? json_decode($statusRaw, true) : null;
        $pid = is_array($status) ? ($status['pid'] ?? null) : null;
        if (!$pid || !is_numeric($pid)) {
            return response()->json(['ok' => false, 'message' => 'No ingest process id found.'], 422);
        }

        $pid = (int) $pid;
        $stopped = false;
        if (function_exists('posix_kill')) {
            $stopped = @posix_kill($pid, SIGTERM);
        }
        if (!$stopped) {
            @exec('kill -TERM ' . $pid, $out, $code);
            $stopped = ($code === 0);
        }

        if (!$stopped) {
            return response()->json(['ok' => false, 'message' => 'Failed to stop ingest process.'], 500);
        }

        if (is_array($status)) {
            $status['status'] = 'stopped';
            $status['updated_at'] = now()->toIso8601String();
            File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return response()->json(['ok' => true, 'pid' => $pid]);
    }
}
