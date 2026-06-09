<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Pipeline\Values\DirectIngestStatusPaths;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\File;

#[Singleton]
readonly class DirectIngestStatusStore
{
    public function modeForPayload(array $data): string
    {
        $graphEnabled = !empty($data['graph']) || !empty($data['graph_only']);

        return $graphEnabled ? 'neo4j' : 'default';
    }

    public function normalizeMode(?string $mode): string
    {
        return in_array($mode, ['default', 'neo4j'], true) ? $mode : 'default';
    }

    public function paths(string $mode = 'default'): DirectIngestStatusPaths
    {
        $mode = $this->normalizeMode($mode);
        if ($mode === 'neo4j') {
            return new DirectIngestStatusPaths(
                (string) config('config.ingest_status_path_neo4j', storage_path('logs/ingest_status_neo4j.json')),
                (string) config('config.ingest_log_cache_path_neo4j', storage_path('logs/ingest_progress_neo4j_cache.log')),
                (string) config('config.ingest_log_path_neo4j', storage_path('logs/ingest_progress_neo4j_full.log')),
            );
        }

        return new DirectIngestStatusPaths(
            (string) config('config.ingest_status_path', storage_path('logs/ingest_status.json')),
            (string) config('config.ingest_log_cache_path', storage_path('logs/ingest_progress_cache.log')),
            (string) config('config.ingest_log_path', storage_path('logs/ingest_progress_full.log')),
        );
    }

    public function ensureDirectories(DirectIngestStatusPaths $paths): void
    {
        File::ensureDirectoryExists(dirname($paths->statusPath));
        File::ensureDirectoryExists(dirname($paths->cacheLogPath));
        File::ensureDirectoryExists(dirname($paths->fullLogPath));
    }

    public function appendStartedLines(DirectIngestStatusPaths $paths, string $path): void
    {
        File::append($paths->cacheLogPath, 'INGEST_STARTED ' . $path . PHP_EOL);
        File::append($paths->fullLogPath, 'INGEST_STARTED ' . $path . PHP_EOL);
    }

    public function load(string $statusPath): array
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

    public function latest(string $statusPath): array
    {
        $entries = $this->load($statusPath);
        if (!$entries) {
            return [null, null];
        }

        $index = count($entries) - 1;
        $status = $entries[$index];

        return [is_array($status) ? $status : null, $index];
    }

    public function save(string $statusPath, array $entries): void
    {
        File::put($statusPath, json_encode(['ingests' => array_values($entries)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function append(string $statusPath, array $entry): void
    {
        $entries = $this->load($statusPath);
        $entries[] = $entry;

        $this->save($statusPath, $entries);
    }

    public function replaceById(string $statusPath, string $id, array $entry): void
    {
        $entries = $this->load($statusPath);
        foreach ($entries as &$existing) {
            if (is_array($existing) && ($existing['id'] ?? null) === $id) {
                $existing = $entry;
                break;
            }
        }
        unset($existing);

        $this->save($statusPath, $entries);
    }

    public function replaceAt(string $statusPath, int $index, array $entry): void
    {
        $entries = $this->load($statusPath);
        $entries[$index] = $entry;

        $this->save($statusPath, $entries);
    }

    public function live(string $mode = 'default'): array
    {
        $paths = $this->paths($mode);
        $entries = $this->load($paths->statusPath);
        if (!$entries) {
            return [];
        }

        $live = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || ($entry['status'] ?? null) !== 'running') {
                continue;
            }

            $pid = $entry['pid'] ?? null;
            $pidValue = ($pid && is_numeric($pid)) ? (int) $pid : null;
            $live[] = [
                'pid' => $pidValue,
                'path' => $entry['path'] ?? null,
                'status' => $entry['status'],
                'started_at' => $entry['started_at'] ?? null,
                'updated_at' => $entry['updated_at'] ?? null,
                'source' => $entry['source'] ?? ($pidValue ? 'api' : 'mcp'),
                'alive' => null,
                'collection' => $entry['collection'] ?? null,
            ];
        }

        return $live;
    }
}
