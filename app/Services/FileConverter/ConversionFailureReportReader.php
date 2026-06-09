<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class ConversionFailureReportReader
{
    public function __construct(
        private ConfigRepository $config,
        private Filesystem $files,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function failuresFor(?string $datasetPath): array
    {
        $reportPath = (string) $this->config->get('file_converter.failed_report_path');
        if ($datasetPath === null || $datasetPath === '' || ! $this->files->isFile($reportPath)) {
            return [];
        }

        $report = json_decode($this->files->get($reportPath), true);
        if (! is_array($report) || ! is_array($report['failures'] ?? null)) {
            return [];
        }

        $prefix = rtrim($datasetPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return array_values(array_filter($report['failures'], function ($failure) use ($prefix): bool {
            $path = is_array($failure) ? (string) ($failure['file_local_path'] ?? $failure['pdf_local_path'] ?? '') : '';

            return $path !== '' && str_starts_with($path, $prefix);
        }));
    }
}
