<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use App\Support\PipelineExitCode;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ConversionWorkflowFinalizer
{
    public function __construct(
        private ConversionReportWriter $reports,
        private ConversionProgressTracker $progress,
    ) {
    }

    public function skipEmpty(
        ConsoleWorkflowIO $io,
        PipelineStateService $state,
        PipelineStageLogger $logger,
        string $jobId,
        string $outputDir,
        array $extensions,
        bool $scanAll,
    ): int {
        $extLabel = implode(',', $extensions);
        $scopeLabel = $scanAll ? 'recursive' : '**/files/*';
        $io->warn("No supported files found under $outputDir (extensions: {$extLabel}; scope: {$scopeLabel})");
        $this->reports->writeFailedJson([], 0, 0, 0);
        $logger->skipped('convert', [
            'job_id' => $jobId,
            'output_dir' => $outputDir,
            'reason' => 'No supported source files found.',
            'extensions' => $extensions,
            'scan_all' => $scanAll,
        ]);
        $state->skipStage($jobId, PipelineStateService::STAGE_CONVERT, [
            'dataset_path' => $outputDir,
            'counts' => [
                'total' => 0,
                'sourceFiles' => 0,
                'processed' => 0,
                'convertedFiles' => 0,
                'skipped' => 0,
                'skippedFiles' => 0,
                'failed' => 0,
                'failedFiles' => 0,
            ],
            'metadata' => [
                'reason' => 'No supported source files found.',
                'extensions' => $extensions,
                'scanAll' => $scanAll,
            ],
        ]);

        return PipelineExitCode::PARTIAL_SUCCESS;
    }

    public function cancelExisting(
        ConsoleWorkflowIO $io,
        PipelineStateService $state,
        PipelineStageLogger $logger,
        string $jobId,
        string $outputDir,
        int $total,
        int $existingOutputs,
    ): int {
        $io->info('Conversion cancelled by user request.');
        $logger->skipped('convert', [
            'job_id' => $jobId,
            'output_dir' => $outputDir,
            'reason' => 'Conversion cancelled because existing outputs were found.',
            'existing_outputs' => $existingOutputs,
        ]);
        $state->skipStage($jobId, PipelineStateService::STAGE_CONVERT, [
            'dataset_path' => $outputDir,
            'counts' => [
                'total' => $total,
                'sourceFiles' => $total,
                'processed' => 0,
                'convertedFiles' => 0,
                'skipped' => $existingOutputs,
                'skippedFiles' => $existingOutputs,
                'failed' => 0,
                'failedFiles' => 0,
            ],
            'metadata' => [
                'reason' => 'Conversion cancelled because existing outputs were found.',
                'existingOutputs' => $existingOutputs,
            ],
        ]);

        return PipelineExitCode::PARTIAL_SUCCESS;
    }

    public function finish(
        ConsoleWorkflowIO $io,
        PipelineStateService $state,
        PipelineStageLogger $logger,
        string $jobId,
        string $outputDir,
        int $total,
        int $processed,
        int $skipped,
        array $failed,
        int $maxRetries,
        array $extensions,
        bool $scanAll,
    ): int {
        $this->reports->writeFailedJson($failed, $processed, $total, $skipped);

        $io->info("Processed docs : {$processed}");
        $io->info("Skipped (cached): {$skipped}");
        $io->info('Failed docs    : '.count($failed));

        if (! empty($failed)) {
            $io->warn('See storage/logs/failed_conversion.json for details.');
        }

        $summaryContext = [
            'job_id' => $jobId,
            'output_dir' => $outputDir,
            'processed' => $processed,
            'skipped' => $skipped,
            'failed' => count($failed),
            'total' => $total,
            'status_detail' => count($failed) > 0 ? 'partial' : 'complete',
        ];
        $stageContext = [
            'dataset_path' => $outputDir,
            'counts' => $this->progress->counts($total, $processed, $skipped, $failed),
            'max_retries' => $maxRetries,
            'metadata' => [
                'statusDetail' => count($failed) > 0 ? 'partial' : 'complete',
                'extensions' => $extensions,
                'scanAll' => $scanAll,
            ],
        ];

        if (count($failed) > 0) {
            $logger->partial('convert', $summaryContext);
            $state->partialStage($jobId, PipelineStateService::STAGE_CONVERT, array_merge($stageContext, [
                'errors' => $failed,
            ]));

            return PipelineExitCode::PARTIAL_SUCCESS;
        }

        $logger->success('convert', $summaryContext);
        $state->completeStage($jobId, PipelineStateService::STAGE_CONVERT, $stageContext);

        return PipelineExitCode::SUCCESS;
    }
}
