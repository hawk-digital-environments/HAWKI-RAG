<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Pipeline\Validation\PipelineDataValidator;
use App\Support\PipelineExitCode;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class CrawledFileConversionWorkflow
{
    public function __construct(
        private CrawledFileDiscovery $discovery,
        private ConversionWorkflowInputResolver $inputs,
        private ConversionWorkflowOptions $options,
        private ExistingConversionResolver $existingOutputs,
        private SingleFileConversionProcessor $fileProcessor,
        private ConversionProgressTracker $progress,
        private ConversionWorkflowFinalizer $finalizer,
    ) {
    }

    public function run(
        ConsoleWorkflowIO $io,
        DocumentConverter $converter,
        PipelineDataValidator $validator,
        PipelineStateService $state,
        PipelineStageLogger $logger,
    ): int {
        $outputDir = $this->inputs->outputDir($io);
        if ($outputDir === null) {
            return PipelineExitCode::VALIDATION_FAILURE;
        }

        $jobId = (string) ($io->option('job-id') ?: $this->options->jobId($outputDir));
        $extensions = $this->options->extensions((string) $io->option('extensions'));
        $scanAll = (bool) $io->option('scan-all');
        $maxRetries = $this->options->maxRetries();
        $retryDelayMs = $this->options->retryDelayMs();

        $logger->started('convert', [
            'job_id' => $jobId,
            'output_dir' => $outputDir,
            'extensions' => $io->option('extensions'),
            'scan_all' => $scanAll,
        ]);

        $docPaths = $this->discovery->collectDocumentPaths($outputDir, $extensions, $scanAll);
        $state->startStage($jobId, PipelineStateService::STAGE_CONVERT, [
            'dataset_path' => $outputDir,
            'counts' => [
                'total' => count($docPaths),
                'sourceFiles' => count($docPaths),
                'processed' => 0,
                'convertedFiles' => 0,
                'skipped' => 0,
                'skippedFiles' => 0,
                'failed' => 0,
                'failedFiles' => 0,
            ],
            'max_retries' => $maxRetries,
            'metadata' => [
                'extensions' => $extensions,
                'scanAll' => $scanAll,
            ],
        ]);

        if (empty($docPaths)) {
            return $this->finalizer->skipEmpty($io, $state, $logger, $jobId, $outputDir, $extensions, $scanAll);
        }

        $io->info('Found '.count($docPaths).' supported file(s). Converting...');
        $existingDecision = $this->existingOutputs->resolve($docPaths, $io);
        if ($existingDecision->cancelled) {
            return $this->finalizer->cancelExisting(
                $io,
                $state,
                $logger,
                $jobId,
                $outputDir,
                count($docPaths),
                $existingDecision->existingOutputs,
            );
        }

        $failed = [];
        $processed = 0;
        $skipped = 0;

        $bar = $io->createProgressBar(count($docPaths));
        $bar->start();

        foreach ($docPaths as $docPath) {
            $bar->advance();
            $result = $this->fileProcessor->process(
                $docPath,
                $jobId,
                $existingDecision->forceReprocess,
                $maxRetries,
                $retryDelayMs,
                $converter,
                $validator,
                $logger,
            );

            if ($result->isProcessed()) {
                $processed++;
            } elseif ($result->isSkipped()) {
                $skipped++;
            } elseif ($result->isFailed() && $result->failure !== null) {
                $failed[] = $result->failure;
            }

            $this->progress->update($state, $jobId, $outputDir, count($docPaths), $processed, $skipped, $failed);
        }

        $bar->finish();
        $io->newLine(2);

        return $this->finalizer->finish(
            $io,
            $state,
            $logger,
            $jobId,
            $outputDir,
            count($docPaths),
            $processed,
            $skipped,
            $failed,
            $maxRetries,
            $extensions,
            $scanAll,
        );
    }
}
