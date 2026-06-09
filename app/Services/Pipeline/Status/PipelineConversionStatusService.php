<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use App\Services\FileConverter\ConversionFailureReportReader;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

#[Singleton]
readonly class PipelineConversionStatusService
{
    public function __construct(
        private ConversionFailureReportReader $failures,
        private Filesystem $files,
        #[Config('file_converter.retries')]
        private int $converterRetries = 3,
        #[Config('file_converter.supported_extensions')]
        private array $converterExtensions = ['pdf', 'doc', 'docx'],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stage(?string $datasetPath): array
    {
        if (! $datasetPath) {
            return $this->emptyStage('unknown', 'No dataset path available yet.');
        }

        $resolvedPath = realpath($datasetPath);
        if ($resolvedPath === false || ! $this->files->isDirectory($resolvedPath)) {
            return $this->emptyStage('unknown', 'Dataset path does not exist yet.', [
                'datasetPath' => $datasetPath,
            ]);
        }

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

            if (in_array(strtolower($file->getExtension()), $extensions, true)) {
                $sourceCount++;
            }
        }

        $failures = $this->failures->failuresFor($resolvedPath);
        $failedCount = count($failures);

        return [
            'status' => $this->convertStatus($sourceCount, $convertedCount, $failedCount),
            'datasetPath' => $resolvedPath,
            'startedAt' => $convertedAt === [] ? null : min($convertedAt),
            'completedAt' => $convertedAt === [] ? null : max($convertedAt),
            'counts' => [
                'sourceFiles' => $sourceCount,
                'convertedFiles' => $convertedCount,
                'failedFiles' => $failedCount,
            ],
            'supportedExtensions' => $extensions,
            'errors' => $failures,
            'retry' => [
                'retryCount' => null,
                'maxRetries' => $this->converterRetries,
            ],
        ];
    }

    private function convertStatus(int $sourceCount, int $convertedCount, int $failedCount): string
    {
        if ($sourceCount === 0) {
            return 'skipped';
        }
        if ($failedCount > 0 && $convertedCount > 0) {
            return 'partial';
        }
        if ($failedCount > 0) {
            return 'failed';
        }
        if ($convertedCount >= $sourceCount) {
            return 'completed';
        }
        if ($convertedCount > 0) {
            return 'partial';
        }

        return 'pending';
    }

    private function emptyStage(string $status, string $message, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'message' => $message,
            'startedAt' => null,
            'completedAt' => null,
            'counts' => [],
            'errors' => [],
            'retry' => [
                'retryCount' => null,
                'maxRetries' => null,
            ],
        ], $extra);
    }

    /**
     * @return list<string>
     */
    private function supportedExtensions(): array
    {
        $extensions = $this->converterExtensions;
        if (! is_array($extensions)) {
            return ['pdf', 'doc', 'docx'];
        }

        $extensions = array_values(array_filter(
            array_map(static fn ($extension) => is_scalar($extension) ? ltrim(strtolower(trim((string) $extension)), '.') : '', $extensions),
            static fn ($extension) => $extension !== ''
        ));

        return $extensions ?: ['pdf', 'doc', 'docx'];
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
