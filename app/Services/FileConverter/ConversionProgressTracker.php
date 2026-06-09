<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class ConversionProgressTracker
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /**
     * @param list<array<string, mixed>> $failed
     */
    public function update(
        PipelineStateService $state,
        string $jobId,
        string $outputDir,
        int $total,
        int $processed,
        int $skipped,
        array $failed
    ): void {
        $state->updateStage($jobId, PipelineStateService::STAGE_CONVERT, [
            'status' => 'running',
            'dataset_path' => $outputDir,
            'counts' => $this->counts($total, $processed, $skipped, $failed),
            'errors' => $failed,
            'max_retries' => (int) $this->config->get('file_converter.retries', 3),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $failed
     * @return array<string, int>
     */
    public function counts(int $total, int $processed, int $skipped, array $failed): array
    {
        return [
            'total' => $total,
            'sourceFiles' => $total,
            'processed' => $processed,
            'convertedFiles' => $processed,
            'skipped' => $skipped,
            'skippedFiles' => $skipped,
            'failed' => count($failed),
            'failedFiles' => count($failed),
        ];
    }
}
