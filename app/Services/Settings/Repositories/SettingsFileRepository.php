<?php
declare(strict_types=1);

namespace App\Services\Settings\Repositories;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class SettingsFileRepository
{
    public function __construct(
        private Filesystem $files,
        private ConfigRepository $config,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $path = $this->path();
        if (! $this->files->exists($path)) {
            return [];
        }

        $decoded = json_decode($this->files->get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function write(array $settings): void
    {
        $path = $this->path();
        $this->files->ensureDirectoryExists(dirname($path));

        $encoded = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            throw new \RuntimeException('Operator settings could not be encoded.');
        }

        $this->files->put($path, $encoded."\n");
        @chmod($path, 0600);
    }

    private function path(): string
    {
        return (string) $this->config->get('config.operator_settings_path');
    }
}
