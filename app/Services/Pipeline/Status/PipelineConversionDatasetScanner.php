<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

#[Singleton]
readonly class PipelineConversionDatasetScanner
{
    public function __construct(
        private Filesystem $files,
        #[Config('file_converter.supported_extensions')]
        private array $converterExtensions = [],
    ) {
    }

    /**
     * @return array{
     *     sourceCount: int,
     *     convertedCount: int,
     *     failedCount: int,
     *     convertedAt: list<string>,
     *     supportedExtensions: list<string>,
     *     failures: list<array<string, mixed>>
     * }
     */
    public function scan(string $resolvedPath): array
    {
        $extensions = $this->supportedExtensions();
        $sourceCount = 0;
        $convertedCount = 0;
        $convertedAt = [];

        foreach ($this->filesUnder($resolvedPath) as $file) {
            $path = $file->getPathname();
            if ($this->isConvertedOutputPath($path)) {
                if ($file->getFilename() === 'conversion_meta.json') {
                    $convertedCount++;
                    $meta = json_decode($this->files->get($path), true);
                    if (is_array($meta) && ! empty($meta['converted_at'])) {
                        $convertedAt[] = (string) $meta['converted_at'];
                    }
                }

                continue;
            }

            if ($extensions === [] || in_array(strtolower($file->getExtension()), $extensions, true)) {
                $sourceCount++;
            }
        }

        return [
            'sourceCount' => $sourceCount,
            'convertedCount' => $convertedCount,
            'failedCount' => 0,
            'convertedAt' => $convertedAt,
            'supportedExtensions' => $extensions,
            'failures' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function supportedExtensions(): array
    {
        $extensions = $this->converterExtensions;
        if (! is_array($extensions)) {
            return [];
        }

        $extensions = array_values(array_filter(
            array_map(static fn ($extension) => is_scalar($extension) ? ltrim(strtolower(trim((string) $extension)), '.') : '', $extensions),
            static fn ($extension) => $extension !== ''
        ));

        return $extensions;
    }

    private function filesUnder(string $path): Finder
    {
        return Finder::create()
            ->files()
            ->ignoreUnreadableDirs()
            ->in($path);
    }

    private function isConvertedOutputPath(string $path): bool
    {
        return str_contains(str_replace('\\', '/', $path), '/converted_');
    }
}
