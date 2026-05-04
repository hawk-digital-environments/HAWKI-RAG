<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Pipeline\PipelineLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

    private function resolveCrawledDataRoot(): ?string
    {
        $root = (string) config('config.crawled_data_root', '/app/shared/crawled-data');
        if (is_dir($root)) {
            return realpath($root) ?: $root;
        }

        return null;
    }

    private function isPathWithinRoot(string $path, string $root): bool
    {
        $resolvedPath = realpath($path);
        $resolvedRoot = realpath($root);

        if ($resolvedPath === false || $resolvedRoot === false) {
            return false;
        }

        return $resolvedPath === $resolvedRoot
            || str_starts_with($resolvedPath, $resolvedRoot . DIRECTORY_SEPARATOR);
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

    private function listLiveIngestions(string $mode = 'default'): array
    {
        [$statusPath] = $this->resolveStatusPaths($mode);
        return $this->listLiveIngestionsFrom($statusPath);
    }

    private function resolveStatusModeForRequest(array $data): string
    {
        $graphEnabled = !empty($data['graph']) || !empty($data['graph_only']);
        return $graphEnabled ? 'neo4j' : 'default';
    }

    private function resolveStatusPaths(string $mode = 'default'): array
    {
        if ($mode === 'neo4j') {
            $statusPath = (string) config('config.ingest_status_path_neo4j', storage_path('logs/ingest_status_neo4j.json'));
            $cacheLogPath = (string) config('config.ingest_log_cache_path_neo4j', storage_path('logs/ingest_progress_neo4j_cache.log'));
            $fullLogPath = (string) config('config.ingest_log_path_neo4j', storage_path('logs/ingest_progress_neo4j_full.log'));
            return [$statusPath, $cacheLogPath, $fullLogPath];
        }
        $statusPath = (string) config('config.ingest_status_path', storage_path('logs/ingest_status.json'));
        $cacheLogPath = (string) config('config.ingest_log_cache_path', storage_path('logs/ingest_progress_cache.log'));
        $fullLogPath = (string) config('config.ingest_log_path', storage_path('logs/ingest_progress_full.log'));
        return [$statusPath, $cacheLogPath, $fullLogPath];
    }

    public function folders(): JsonResponse
    {
        $root = $this->resolveCrawledDataRoot();
        if (!$root) {
            return response()->json([
                'ok' => false,
                'message' => 'Crawled-data root not found.',
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
            'graph_model' => 'sometimes|string',
            'neo4j_database' => 'sometimes|string',
            'graph_only' => 'sometimes|boolean',
            'chunk_chars' => 'sometimes|integer',
            'chunk_overlap' => 'sometimes|integer',
            'batch' => 'sometimes|integer',
            'timeout' => 'sometimes|integer',
            'resume_mode' => 'sometimes|string|in:resume,start',
        ]);

        $root = $this->resolveCrawledDataRoot();
        if (!$root) {
            return response()->json(['ok' => false, 'message' => 'Crawled-data root not found.'], 404);
        }

        $path = $data['path'];
        if (!is_dir($path)) {
            return response()->json(['ok' => false, 'message' => "Path not found: {$path}"], 404);
        }
        if (!$this->isPathWithinRoot($path, $root)) {
            return response()->json(['ok' => false, 'message' => 'Path must be within the crawled-data root.'], 422);
        }

        $script = base_path('python_rag/ingest/ingest_crawled.py');
        if (!is_file($script)) {
            return response()->json(['ok' => false, 'message' => 'ingest_crawled.py not found'], 500);
        }

        $baseUrl = (string) config('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge:8000');
        $statusMode = $this->resolveStatusModeForRequest($data);
        [$statusPath, $cacheLogPath, $fullLogPath] = $this->resolveStatusPaths($statusMode);
        File::ensureDirectoryExists(dirname($statusPath));
        File::ensureDirectoryExists(dirname($cacheLogPath));
        File::ensureDirectoryExists(dirname($fullLogPath));

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
        if (!empty($data['neo4j_database'])) {
            $cmd[] = '--neo4j-database';
            $cmd[] = (string) $data['neo4j_database'];
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
        $timeout = $data['timeout'] ?? (int) config('config.ingest_timeout', 6000);
        if ($timeout > 0) {
            $cmd[] = '--timeout';
            $cmd[] = (string) $timeout;
        }
        if (!empty($data['graph'])) {
            $cmd[] = '--graph';
        }
        if (!empty($data['graph_only'])) {
            $cmd[] = '--graph-only';
        }
        $resumeMode = $data['resume_mode'] ?? 'resume';
        if ($resumeMode === 'start') {
            $cmd[] = '--start';
        } else {
            $cmd[] = '--resume';
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
            'resume_mode' => $resumeMode,
            'graph' => !empty($data['graph']),
            'graph_only' => !empty($data['graph_only']),
            'neo4j_database' => isset($data['neo4j_database']) ? trim((string) $data['neo4j_database']) : null,
            'status_mode' => $statusMode,
        ];
        $entries = $this->loadStatusEntries($statusPath);
        $entries[] = $entry;
        $this->saveStatusEntries($statusPath, $entries);
        File::append($cacheLogPath, 'INGEST_STARTED ' . $path . PHP_EOL);
        File::append($fullLogPath, 'INGEST_STARTED ' . $path . PHP_EOL);
        PipelineLogger::started('ingest', [
            'job_id' => $entry['id'],
            'file_path' => $path,
            'collection' => (string) $collection,
            'pipeline_stage' => 'process_launch',
            'graph' => !empty($data['graph']),
            'graph_only' => !empty($data['graph_only']),
        ]);

        $escaped = array_map('escapeshellarg', $cmd);
        $command = implode(' ', $escaped);
        $graphModel = isset($data['graph_model']) ? trim((string) $data['graph_model']) : '';
        $envPrefix = $graphModel !== '' ? ('export GRAPH_OLLAMA_RAG_MODEL=' . escapeshellarg($graphModel) . '; ') : '';
        $cacheEsc = escapeshellarg($cacheLogPath);
        $fullEsc = escapeshellarg($fullLogPath);
        $commandLine = $envPrefix . '(' . $command . ') 2>&1 | tee -a ' . $fullEsc . ' >> ' . $cacheEsc
            . '; echo "INGEST_DONE" | tee -a ' . $fullEsc . ' >> ' . $cacheEsc;
        // Launch a detached shell process. Symfony Process would be destroyed at the
        // end of the request and terminate the child before ingest can continue.
        $launcher = 'cd ' . escapeshellarg(base_path())
            . ' && nohup sh -lc ' . escapeshellarg($commandLine) . ' >/dev/null 2>&1 & echo $!';
        $pidOutput = [];
        $launchCode = 0;
        @exec($launcher, $pidOutput, $launchCode);
        $pid = isset($pidOutput[0]) ? (int) trim((string) $pidOutput[0]) : 0;
        if ($launchCode !== 0 || $pid <= 0) {
            $entry['status'] = 'failed';
            $entry['updated_at'] = now()->toIso8601String();
            $entries = $this->loadStatusEntries($statusPath);
            foreach ($entries as &$existing) {
                if (is_array($existing) && ($existing['id'] ?? null) === $entry['id']) {
                    $existing = $entry;
                    break;
                }
            }
            unset($existing);
            $this->saveStatusEntries($statusPath, $entries);
            PipelineLogger::failed('ingest', [
                'job_id' => $entry['id'],
                'file_path' => $path,
                'collection' => (string) $collection,
                'pipeline_stage' => 'process_launch',
                'error_message' => 'Failed to launch ingest process.',
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Failed to launch ingest process.',
            ], 500);
        }

        $entry['pid'] = $pid;
        $entry['updated_at'] = now()->toIso8601String();
        $entries = $this->loadStatusEntries($statusPath);
        foreach ($entries as &$existing) {
            if (is_array($existing) && ($existing['id'] ?? null) === $entry['id']) {
                $existing = $entry;
                break;
            }
        }
        unset($existing);
        $this->saveStatusEntries($statusPath, $entries);
        PipelineLogger::success('ingest', [
            'job_id' => $entry['id'],
            'file_path' => $path,
            'collection' => (string) $collection,
            'pipeline_stage' => 'process_launch',
            'pid' => $pid,
            'status_path' => $statusPath,
        ]);

        return response()->json([
            'ok' => true,
            'pid' => $pid,
            'status_path' => $statusPath,
            'log_path' => $cacheLogPath,
            'full_log_path' => $fullLogPath,
            'collection_exists' => $collectionExists,
        ]);
    }

    public function stop(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pid' => 'sometimes|integer|min:1',
            'pids' => 'sometimes|array',
            'pids.*' => 'integer|min:1',
            'mode' => 'sometimes|string|in:default,neo4j',
        ]);

        $mode = $data['mode'] ?? 'default';
        [$statusPath] = $this->resolveStatusPaths($mode);
        $liveBefore = $this->listLiveIngestionsFrom($statusPath);
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
            if ($pid > 0 && !$this->isPidAlive($pid)) {
                $stopped = true; // already finished
            } elseif ($pid > 0 && function_exists('posix_kill')) {
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

        $entries = $this->loadStatusEntries($statusPath);
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
        $this->saveStatusEntries($statusPath, $entries);

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

    private function loadStatusEntries(string $statusPath): array
    {
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

    private function saveStatusEntries(string $statusPath, array $entries): void
    {
        File::put($statusPath, json_encode(['ingests' => array_values($entries)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function listLiveIngestionsFrom(string $statusPath): array
    {
        $entries = $this->loadStatusEntries($statusPath);
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

    private function collectionExistsInQdrant(string $collection): bool
    {
        $collection = trim($collection);
        if ($collection === '') {
            return false;
        }
        $baseUrl = rtrim((string) config('config.qdrant_http_url', 'http://qdrant:6333'), '/');
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

    public function live(Request $request): JsonResponse
    {
        $mode = (string) $request->query('mode', 'default');
        if (!in_array($mode, ['default', 'neo4j'], true)) {
            $mode = 'default';
        }
        $live = $this->listLiveIngestions($mode);
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

        $root = $this->resolveCrawledDataRoot();
        if (!$root) {
            return response()->json([
                'ok' => false,
                'message' => 'Crawled-data root not found.',
            ], 404);
        }
        $path = $data['path'];
        if (!is_dir($path)) {
            return response()->json(['ok' => false, 'message' => "Folder not found: {$path}"], 404);
        }
        if (!$this->isPathWithinRoot($path, $root)) {
            return response()->json(['ok' => false, 'message' => 'Path must be within the crawled-data root.'], 422);
        }

        $resolvedPath = realpath($path);
        if ($resolvedPath === false) {
            return response()->json(['ok' => false, 'message' => 'Unable to resolve folder path.'], 422);
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
