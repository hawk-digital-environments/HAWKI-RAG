<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Uploads;

use App\Services\Pipeline\Values\PipelineUploadInput;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class PipelineCustomConverterProfileWriter
{
    public function __construct(
        private Filesystem $files,
        private ConfigRepository $config,
    ) {
    }

    /**
     * @param array{raw: string, markdown: string} $storage
     */
    public function write(string $sourceId, PipelineUploadInput $input, array $storage): ?string
    {
        $profile = $input->customConverterProfile();
        if ($profile === []) {
            return null;
        }

        $profile['version'] = '1';
        $profile['source_id'] = $sourceId;
        $statusPath = $this->statusPath();
        if ($statusPath !== null) {
            $profile['converter_status_path'] = $statusPath;
        }

        $path = $this->profilePath($storage['raw']);
        $this->files->ensureDirectoryExists(dirname($path));

        $encoded = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            throw new \RuntimeException('Custom converter profile could not be encoded.');
        }

        $this->files->put($path, $encoded."\n");
        @chmod($path, 0600);

        return $path;
    }

    private function profilePath(string $rawStoragePath): string
    {
        $rawPath = rtrim($rawStoragePath, '/');
        if (str_starts_with($rawPath, 's3://')) {
            throw new \RuntimeException('Custom converter profiles require shared local storage.');
        }

        return dirname($rawPath).DIRECTORY_SEPARATOR.'secrets'.DIRECTORY_SEPARATOR.'custom_converter.json';
    }

    private function statusPath(): ?string
    {
        $path = $this->config->get('file_converter.custom_converter_status_path');
        if (! is_scalar($path) || trim((string) $path) === '') {
            return null;
        }

        $path = trim((string) $path);

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }
}
