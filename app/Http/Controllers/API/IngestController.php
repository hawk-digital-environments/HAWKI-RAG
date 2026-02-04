<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class IngestController extends Controller
{
    private function buildFolderList(string $root): array
    {
        $dirs = File::directories($root);
        $folders = [];
        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (str_starts_with($name, '.')) {
                continue;
            }
            if (preg_match('/^sitemaps?$/i', $name)) {
                continue;
            }
            $folders[] = [
                'name' => $name,
                'path' => $dir,
            ];
        }

        usort($folders, static fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $folders;
    }

    private function resolveSharedRoot(): ?string
    {
        $root = (string) config('hawki_rag.shared_root', storage_path('app/public'));
        if (is_dir($root)) {
            return $root;
        }
        $fallback = storage_path('app/public');
        return is_dir($fallback) ? $fallback : null;
    }

    private function isPidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        @exec('kill -0 ' . $pid, $out, $code);
        return $code === 0;
    }

    private function listLiveIngestions(): array
    {
        $entries = $this->loadStatusEntries();
        if (!$entries) {
            return [];
        }

        $live = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $statusValue = $entry['status'] ?? null;
            if ($statusValue !== 'running') {
                continue;
            }
            $pid = $entry['pid'] ?? null;
            $pidValue = ($pid && is_numeric($pid)) ? (int) $pid : null;
            $live[] = [
                'pid' => $pidValue,
                'path' => $entry['path'] ?? null,
                'status' => $statusValue,
                'started_at' => $entry['started_at'] ?? null,
                'updated_at' => $entry['updated_at'] ?? null,
                'source' => $entry['source'] ?? ($pidValue ? 'api' : 'mcp'),
                'alive' => null,
                'collection' => $entry['collection'] ?? null,
            ];
        }

        return $live;
    }

    public function folders(): JsonResponse
    {
        $root = $this->resolveSharedRoot();
        if (!$root) {
            return response()->json([
                'ok' => false,
                'message' => 'Shared root not found.',
            ], 404);
        }

        $folders = $this->buildFolderList($root);

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
            'embedding_model' => 'sometimes|string',
            'graph' => 'sometimes|boolean',
            'graph_engine' => 'sometimes|string',
            'graph_only' => 'sometimes|boolean',
            'chunk_chars' => 'sometimes|integer',
            'chunk_overlap' => 'sometimes|integer',
            'batch' => 'sometimes|integer',
            'timeout' => 'sometimes|integer',
        ]);

        $root = (string) config('hawki_rag.shared_root', '/app/shared');
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

        $baseUrl = (string) env('HAWKI_RAG_BRIDGE_URL', 'http://hawki_rag_bridge:8000');
        $statusPath = (string) config('hawki_rag.ingest_status_path', storage_path('logs/ingest_status.json'));
        $logPath = (string) config('hawki_rag.ingest_log_path', storage_path('logs/ingest_progress.log'));
        File::ensureDirectoryExists(dirname($statusPath));
        File::ensureDirectoryExists(dirname($logPath));

        $cmd = [
            'python3',
            '-u',
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
        if (!empty($data['embedding_model'])) {
            $cmd[] = '--embedding-model';
            $cmd[] = (string) $data['embedding_model'];
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
        if (!empty($data['graph_only'])) {
            $cmd[] = '--graph-only';
        }

        $summaryPath = storage_path('logs/ingest_summary.json');
        $cmd[] = '--summary-file';
        $cmd[] = $summaryPath;

        $collectionExists = $this->collectionExistsInQdrant((string) $collection);
        $now = now()->toIso8601String();
        $entry = [
            'id' => (string) Str::uuid(),
            'started_at' => $now,
            'updated_at' => $now,
            'status' => 'running',
            'progress' => null,
            'last_line' => null,
            'summary_path' => $summaryPath,
            'command' => $cmd,
            'path' => $path,
            'collection' => (string) $collection,
            'collection_exists' => $collectionExists,
            'source' => 'api',
        ];
        $entries = $this->loadStatusEntries();
        $entries[] = $entry;
        $this->saveStatusEntries($entries);
        File::append($logPath, 'INGEST_STARTED ' . $path . PHP_EOL);

        $escaped = array_map('escapeshellarg', $cmd);
        $commandLine = implode(' ', $escaped) . ' >> ' . escapeshellarg($logPath) . ' 2>&1; echo "INGEST_DONE" >> ' . escapeshellarg($logPath);
        $process = Process::fromShellCommandline($commandLine, base_path());
        $process->setTimeout(null);
        $process->start();

        $entry['pid'] = $process->getPid();
        $entry['updated_at'] = now()->toIso8601String();
        $entries = $this->loadStatusEntries();
        foreach ($entries as &$existing) {
            if (is_array($existing) && ($existing['id'] ?? null) === $entry['id']) {
                $existing = $entry;
                break;
            }
        }
        unset($existing);
        $this->saveStatusEntries($entries);

        return response()->json([
            'ok' => true,
            'pid' => $process->getPid(),
            'status_path' => $statusPath,
            'log_path' => $logPath,
            'collection_exists' => $collectionExists,
        ]);
    }

    public function stop(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pid' => 'sometimes|integer|min:1',
            'pids' => 'sometimes|array',
            'pids.*' => 'integer|min:1',
        ]);

        $liveBefore = $this->listLiveIngestions();
        if (!$liveBefore) {
            return response()->json([
                'ok' => false,
                'message' => 'No running ingest process found.',
                'live_ingestions' => $liveBefore,
            ], 404);
        }

        $targetPids = [];
        if (!empty($data['pids']) && is_array($data['pids'])) {
            $targetPids = array_values(array_filter($data['pids'], 'is_numeric'));
        } elseif (!empty($data['pid'])) {
            $targetPids = [(int) $data['pid']];
        }
        if (!$targetPids) {
            $targetPids = array_values(array_filter(array_map(function ($item) {
                return isset($item['pid']) ? (int) $item['pid'] : null;
            }, $liveBefore)));
        }

        $stoppedCount = 0;
        $stoppedPids = [];
        foreach ($targetPids as $pid) {
            $pid = (int) $pid;
            $stopped = false;
            if ($pid > 0 && function_exists('posix_kill')) {
                $stopped = @posix_kill($pid, SIGTERM);
            }
            if (!$stopped && $pid > 0) {
                @exec('kill -TERM ' . $pid, $out, $code);
                $stopped = ($code === 0);
            }
            if ($stopped) {
                $stoppedCount += 1;
                $stoppedPids[] = $pid;
            }
        }

        if ($stoppedCount === 0) {
            $stoppedCount = $this->stopByCommandMatch('ingest_crawled.py');
            $stoppedPids = $stoppedCount > 0 ? $targetPids : [];
        }

        if ($stoppedCount === 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Failed to stop ingest process.',
                'live_ingestions' => $liveBefore,
            ], 500);
        }

        $entries = $this->loadStatusEntries();
        $now = now()->toIso8601String();
        foreach ($entries as &$entry) {
            if (!is_array($entry)) {
                continue;
            }
            $pid = isset($entry['pid']) ? (int) $entry['pid'] : null;
            if ($pid && in_array($pid, $stoppedPids, true)) {
                $entry['status'] = 'stopped';
                $entry['updated_at'] = $now;
            }
        }
        unset($entry);
        $this->saveStatusEntries($entries);

        return response()->json([
            'ok' => true,
            'stopped_count' => $stoppedCount,
            'stopped_pids' => $stoppedPids,
            'live_ingestions' => $liveBefore,
        ]);
    }

    private function stopByCommandMatch(string $needle): int
    {
        $count = 0;
        foreach (glob('/proc/[0-9]*/cmdline') as $cmdlinePath) {
            $cmdline = @file_get_contents($cmdlinePath);
            if (!$cmdline) {
                continue;
            }
            $cmdline = str_replace("\0", " ", $cmdline);
            if (stripos($cmdline, $needle) === false) {
                continue;
            }
            if (preg_match('~/proc/(\\d+)/cmdline$~', $cmdlinePath, $m)) {
                $pid = (int) $m[1];
                if ($pid > 0 && function_exists('posix_kill')) {
                    if (@posix_kill($pid, SIGTERM)) {
                        $count += 1;
                    }
                }
            }
        }
        return $count;
    }

    private function loadStatusEntries(): array
    {
        $statusPath = (string) config('hawki_rag.ingest_status_path', storage_path('logs/ingest_status.json'));
        if (!is_file($statusPath)) {
            return [];
        }
        $raw = @file_get_contents($statusPath);
        $data = $raw ? json_decode($raw, true) : null;
        if (is_array($data) && array_key_exists('ingests', $data) && is_array($data['ingests'])) {
            return $data['ingests'];
        }
        if (is_array($data)) {
            return [$data];
        }
        return [];
    }

    private function saveStatusEntries(array $entries): void
    {
        $statusPath = (string) config('hawki_rag.ingest_status_path', storage_path('logs/ingest_status.json'));
        File::put($statusPath, json_encode(['ingests' => array_values($entries)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function collectionExistsInQdrant(string $collection): bool
    {
        $collection = trim($collection);
        if ($collection === '') {
            return false;
        }
        $baseUrl = rtrim((string) env('QDRANT_HTTP_URL', 'http://qdrant:6333'), '/');
        try {
            $resp = Http::timeout(3)->get($baseUrl . '/collections');
            if (!$resp->successful()) {
                return false;
            }
            $data = $resp->json();
            foreach (($data['result']['collections'] ?? []) as $col) {
                if (($col['name'] ?? null) === $collection) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    public function live(): JsonResponse
    {
        $live = $this->listLiveIngestions();
        return response()->json([
            'ok' => true,
            'live_ingestions' => $live,
        ]);
    }

    public function deleteFolder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => 'required|string',
        ]);

        $root = $this->resolveSharedRoot();
        if (!$root) {
            return response()->json([
                'ok' => false,
                'message' => 'Shared root not found.',
            ], 404);
        }
        $path = $data['path'];
        $resolvedRoot = realpath($root);
        $resolvedPath = realpath($path);
        if (!$resolvedRoot || !$resolvedPath) {
            return response()->json(['ok' => false, 'message' => 'Unable to resolve folder path.'], 422);
        }
        $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $pathPrefix = rtrim($resolvedPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($pathPrefix, $rootPrefix)) {
            return response()->json(['ok' => false, 'message' => 'Path must be within shared root.'], 422);
        }
        if (!is_dir($resolvedPath)) {
            return response()->json(['ok' => false, 'message' => "Folder not found: {$resolvedPath}"], 404);
        }

        try {
            $deleted = File::deleteDirectory($resolvedPath);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Failed to delete folder.'], 500);
        }

        if (!$deleted || is_dir($resolvedPath)) {
            $perms = @fileperms($resolvedPath);
            $permText = $perms ? substr(sprintf('%o', $perms), -4) : 'unknown';
            return response()->json([
                'ok' => false,
                'message' => 'Delete failed. Check folder permissions/ownership.',
                'path' => $resolvedPath,
                'writable' => is_writable($resolvedPath),
                'permissions' => $permText,
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'deleted' => $resolvedPath,
            'folders' => $this->buildFolderList($root),
            'root' => $root,
        ]);
    }
}
