<?php

declare(strict_types=1);

namespace App\Services\Rag;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class RagMonitorArtifactReader
{
    public function __construct(
        private ConfigRepository $config,
        private Filesystem $files,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function firstConfiguredJson(string $key): ?array
    {
        foreach ($this->configList($key) as $path) {
            $data = $this->readJson($path);
            if (is_array($data)) {
                return [
                    'path' => $path,
                    'updated_at' => date(DATE_ATOM, $this->files->lastModified($path)),
                    'data' => $data,
                ];
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function graphFailures(int $limit): array
    {
        return $this->tailJsonLines((string) $this->config->get('config.graph_failures_path'), $limit);
    }

    private function readJson(string $path): ?array
    {
        if ($path === '' || ! $this->files->isFile($path)) {
            return null;
        }

        $decoded = json_decode($this->files->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tailJsonLines(string $path, int $limit): array
    {
        if ($path === '' || ! $this->files->isFile($path)) {
            return [];
        }

        $lines = preg_split('/\R/', trim($this->files->get($path))) ?: [];
        $items = [];
        foreach (array_slice($lines, -1 * max(1, $limit)) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            $items[] = is_array($decoded) ? $decoded : ['message' => $line];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function configList(string $key): array
    {
        $value = $this->config->get($key, []);

        return is_array($value)
            ? array_values(array_filter(array_map('strval', $value)))
            : [];
    }
}
