<?php

declare(strict_types=1);

namespace App\Services\Graph;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

class GraphSnapshotStore
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Filesystem $files,
        private readonly ClockInterface $clock = new Clock,
    ) {}

    public function save(array $payload): array
    {
        $dir = $this->directory();
        $this->files->ensureDirectoryExists($dir);

        $now = $this->clock->now();
        $id = $now->format('YmdHis').'-'.Str::random(8);
        $snapshot = [
            'id' => $id,
            'name' => trim((string) ($payload['name'] ?? '')) ?: 'Graph snapshot '.$now->format('Y-m-d H:i:s'),
            'created_at' => $now->format(\DateTimeInterface::ATOM),
            'scene' => $payload['scene'] ?? [],
        ];

        $this->files->put($dir.DIRECTORY_SEPARATOR.$id.'.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return ['ok' => true, 'snapshot' => $snapshot];
    }

    public function list(): array
    {
        $dir = $this->directory();
        $this->files->ensureDirectoryExists($dir);
        $items = [];
        foreach ($this->files->files($dir) as $file) {
            $data = json_decode($this->files->get($file->getPathname()), true);
            if (is_array($data)) {
                $items[] = [
                    'id' => $data['id'] ?? $file->getBasename('.json'),
                    'name' => $data['name'] ?? $file->getBasename('.json'),
                    'created_at' => $data['created_at'] ?? null,
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return ['ok' => true, 'snapshots' => $items];
    }

    public function load(string $id): array
    {
        $path = $this->path($id);
        if (! $this->files->exists($path)) {
            return ['ok' => false, 'message' => 'Snapshot not found.'];
        }

        return ['ok' => true, 'snapshot' => json_decode($this->files->get($path), true)];
    }

    public function delete(string $id): array
    {
        $path = $this->path($id);
        if ($this->files->exists($path)) {
            $this->files->delete($path);
        }

        return ['ok' => true];
    }

    private function path(string $id): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.basename($id).'.json';
    }

    private function directory(): string
    {
        return rtrim((string) $this->config->get('config.graph_snapshot_path'), DIRECTORY_SEPARATOR);
    }
}
