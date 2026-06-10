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
                'datasetPath' => $datasetPath,
            ]);
        }

        $scan = $this->scanner->scan($resolvedPath);

        return [
            'status' => $this->convertStatus($scan['sourceCount'], $scan['convertedCount'], $scan['failedCount']),
            'datasetPath' => $resolvedPath,
            'startedAt' => $scan['convertedAt'] === [] ? null : min($scan['convertedAt']),
            'completedAt' => $scan['convertedAt'] === [] ? null : max($scan['convertedAt']),
            'counts' => [
                'sourceFiles' => $scan['sourceCount'],
                'convertedFiles' => $scan['convertedCount'],
                'failedFiles' => $scan['failedCount'],
            ],
            'supportedExtensions' => $scan['supportedExtensions'],
            'errors' => $scan['failures'],
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
}
