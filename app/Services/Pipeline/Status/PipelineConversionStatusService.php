<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class PipelineConversionStatusService
{
    public function __construct(
        private Filesystem $files,
        private PipelineConversionDatasetScanner $scanner,
        private PipelineStageEmptyResponseFactory $emptyStages,
        #[Config('file_converter.retries')]
        private int $converterRetries = 3,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stage(?string $datasetPath): array
    {
        if (! $datasetPath) {
            return $this->emptyStages->stage('unknown', 'No dataset path available yet.');
        }

        $resolvedPath = realpath($datasetPath);
        if ($resolvedPath === false || ! $this->files->isDirectory($resolvedPath)) {
            return $this->emptyStages->stage('unknown', 'Dataset path does not exist yet.', [
                'dataset_path' => $datasetPath,
            ]);
        }

        $scan = $this->scanner->scan($resolvedPath);

        return [
            'status' => $this->convertStatus($scan['sourceCount'], $scan['convertedCount'], $scan['failedCount']),
            'dataset_path' => $resolvedPath,
            'started_at' => $scan['convertedAt'] === [] ? null : min($scan['convertedAt']),
            'completed_at' => $scan['convertedAt'] === [] ? null : max($scan['convertedAt']),
            'counts' => [
                'source_files' => $scan['sourceCount'],
                'converted_files' => $scan['convertedCount'],
                'failed_files' => $scan['failedCount'],
            ],
            'supported_extensions' => $scan['supportedExtensions'],
            'errors' => $scan['failures'],
            'retry' => [
                'retry_count' => null,
                'max_retries' => $this->converterRetries,
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
}
