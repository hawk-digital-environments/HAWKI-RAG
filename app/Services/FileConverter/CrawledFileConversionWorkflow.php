<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Pipeline\Validation\PipelineDataValidator;
use App\Support\PipelineExitCode;
use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Clock\ClockInterface;
use SplFileInfo;

class CrawledFileConversionWorkflow
{
    public function __construct(
        private readonly CrawledFileDiscovery $discovery,
        private readonly ExistingConversionPolicy $existingConversionPolicy,
        private readonly ConversionReportWriter $reports,
        private readonly ConvertedOutputWriter $outputs,
        private readonly ConversionRetryClient $retryClient,
        private readonly ConversionOutputContent $content,
        private readonly ConversionProgressTracker $progress,
        private readonly ConfigRepository $config,
        private readonly ClockInterface $clock,
    ) {
    }

    public function run(
        ConsoleWorkflowIO $io,
        DocumentConverter $converter,
        PipelineDataValidator $validator,
        PipelineStateService $state,
        PipelineStageLogger $logger,
    ): int {
        $outputDirArg = $io->argument('outputDir');
        if ($outputDirArg) {
            $outputDir = $this->discovery->resolveOutputDir((string) $outputDirArg);
            if (! is_dir($outputDir)) {
                $io->error("Output dir not found: $outputDir");

                return PipelineExitCode::VALIDATION_FAILURE;
            }
        } else {
            if ($this->existingConversionPolicy->automationEnabled() || ! $io->isInteractive()) {
                $io->error('Output dir is required in automation or non-interactive mode.');

                return PipelineExitCode::VALIDATION_FAILURE;
            }

            $outputDir = $this->discovery->pickOutputDir($io);
            if (! $outputDir) {
                return PipelineExitCode::VALIDATION_FAILURE;
            }
        }

        $jobId = (string) ($io->option('job-id') ?: $this->conversionJobId($outputDir));
        $logger->started('convert', [
            'job_id' => $jobId,
            'output_dir' => $outputDir,
            'extensions' => $io->option('extensions'),
            'scan_all' => (bool) $io->option('scan-all'),
        ]);

        // Find supported files under outputDir (recursive)
        $extensions = $this->parseExtensions((string) $io->option('extensions'));
        $scanAll = (bool) $io->option('scan-all');
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
            'max_retries' => (int) $this->config->get('file_converter.retries', 3),
            'metadata' => [
                'extensions' => $extensions,
                'scanAll' => $scanAll,
            ],
        ]);

        if (empty($docPaths)) {
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

        $io->info('Found '.count($docPaths).' supported file(s). Converting...');

        $existingMetaCount = 0;
        foreach ($docPaths as $docPath) {
            $destDir = dirname($docPath).'/converted_'.pathinfo($docPath, PATHINFO_FILENAME);
            if (is_file($destDir.'/conversion_meta.json')) {
                $existingMetaCount++;
            }
        }

        $forceReprocess = false;
        if ($existingMetaCount > 0) {
            $io->line("Detected {$existingMetaCount} previously converted document(s) in this directory.");
            $choice = $this->existingConversionPolicy->resolve((string) $io->option('existing'), $io);

            if ($choice === 'cancel') {
                $io->info('Conversion cancelled by user request.');
                $logger->skipped('convert', [
                    'job_id' => $jobId,
                    'output_dir' => $outputDir,
                    'reason' => 'Conversion cancelled because existing outputs were found.',
                    'existing_outputs' => $existingMetaCount,
                ]);
                $state->skipStage($jobId, PipelineStateService::STAGE_CONVERT, [
                    'dataset_path' => $outputDir,
                    'counts' => [
                        'total' => count($docPaths),
                        'sourceFiles' => count($docPaths),
                        'processed' => 0,
                        'convertedFiles' => 0,
                        'skipped' => $existingMetaCount,
                        'skippedFiles' => $existingMetaCount,
                        'failed' => 0,
                        'failedFiles' => 0,
                    ],
                    'metadata' => [
                        'reason' => 'Conversion cancelled because existing outputs were found.',
                        'existingOutputs' => $existingMetaCount,
                    ],
                ]);

                return PipelineExitCode::PARTIAL_SUCCESS;
            }

            if ($choice === 'restart') {
                $forceReprocess = true;
                $io->warn('Restart selected — existing converted outputs will be re-generated.');
            } else {
                $io->info('Continuing will skip already converted documents when their hashes match.');
            }
        }

        $failed = [];
        $processed = 0;
        $skipped = 0;

        // Read retry config (set these in config/services.php via env)
        $maxRetries = (int) $this->config->get('file_converter.retries', 3);
        $retryDelayMs = (int) $this->config->get('file_converter.retry_delay_ms', 1500);

        $bar = $io->createProgressBar(count($docPaths));
        $bar->start();

        foreach ($docPaths as $docPath) {
            $bar->advance();
            $convertedId = null;
            $docTitle = pathinfo($docPath, PATHINFO_FILENAME);
            $stagingDir = null;

            try {
                $docInfo = new SplFileInfo($docPath);
                $flatPath = dirname($docPath).'/'.pathinfo($docInfo->getFilename(), PATHINFO_FILENAME).'_converted.md';

                // Compute converted_id (sha256 of file contents)
                $convertedId = @hash_file('sha256', $docInfo->getPathname());
                if ($convertedId === false) {
                    throw new \RuntimeException('Unable to hash document (hash_file returned false).');
                }
                $docTitle = pathinfo($docInfo->getFilename(), PATHINFO_FILENAME);
                $logger->started('convert', [
                    'job_id' => $jobId,
                    'doc_id' => $convertedId,
                    'file_path' => $docPath,
                    'title' => $docTitle,
                    'pipeline_stage' => 'document_conversion',
                ]);

                // Destination folder next to the document
                $destDir = dirname($docPath).'/converted_'.pathinfo($docInfo->getFilename(), PATHINFO_FILENAME);
                $metaPath = $destDir.'/conversion_meta.json';

                // Skip if meta exists and converted_id matches
                if (! $forceReprocess && is_file($metaPath)) {
                    $meta = json_decode($this->outputs->readFile($metaPath), true);
                    if (is_array($meta) && ($meta['converted_id'] ?? null) === $convertedId) {
                        $meta = $this->content->normalizeCachedMetadata($meta, $docPath, $destDir, $convertedId, $docTitle, $jobId);
                        $flatContent = is_file($flatPath)
                            ? $this->outputs->readFile($flatPath)
                            : $this->content->loadMarkdownFromMeta($meta, $destDir);
                        $markdownValidation = $validator->validateMarkdownContent($flatContent);
                        $metadataValidation = $validator->validateConversionMetadata($meta);

                        if ($flatContent !== null && $markdownValidation['errors'] === [] && $metadataValidation['errors'] === []) {
                            if (! is_file($flatPath)) {
                                $this->outputs->writeFileAtomically($flatPath, $flatContent);
                            }
                            $this->outputs->writeFileAtomically($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                            if ($markdownValidation['warnings'] !== [] || $metadataValidation['warnings'] !== []) {
                                $logger->partial('convert', [
                                    'job_id' => $jobId,
                                    'doc_id' => $convertedId,
                                    'file_path' => $docPath,
                                    'title' => $docTitle,
                                    'pipeline_stage' => 'cached_output',
                                    'warnings' => array_merge($markdownValidation['warnings'], $metadataValidation['warnings']),
                                ]);
                            }
                            $logger->skipped('convert', [
                                'job_id' => $jobId,
                                'doc_id' => $convertedId,
                                'file_path' => $docPath,
                                'title' => $docTitle,
                                'pipeline_stage' => 'cached_output',
                                'reason' => 'Existing conversion output matches source checksum.',
                                'markdown_path' => $flatPath,
                                'metadata_path' => $metaPath,
                            ]);
                            $skipped++;
                            $this->progress->update($state, $jobId, $outputDir, count($docPaths), $processed, $skipped, $failed);

                            continue;
                        }

                        $logger->partial('convert', [
                            'job_id' => $jobId,
                            'doc_id' => $convertedId,
                            'file_path' => $docPath,
                            'title' => $docTitle,
                            'pipeline_stage' => 'cached_output',
                            'reason' => 'Existing conversion output is incomplete; reprocessing.',
                            'errors' => array_merge($markdownValidation['errors'], $metadataValidation['errors']),
                            'warnings' => array_merge($markdownValidation['warnings'], $metadataValidation['warnings']),
                        ]);
                    }
                }

                // Run conversion with retry (returns [relative_path => content])
                $files = $this->retryClient->convert($converter, $docInfo, $maxRetries, $retryDelayMs);
                $filesValidation = $validator->validateConvertedFiles($files);
                if ($filesValidation['errors'] !== []) {
                    throw new \RuntimeException('Invalid converter output: '.implode('; ', $filesValidation['errors']));
                }
                if ($filesValidation['warnings'] !== []) {
                    $logger->partial('convert', [
                        'job_id' => $jobId,
                        'doc_id' => $convertedId,
                        'file_path' => $docPath,
                        'title' => $docTitle,
                        'pipeline_stage' => 'converter_output',
                        'warnings' => $filesValidation['warnings'],
                    ]);
                }

                // Write extracted files to a staging directory. The published
                // conversion directory is replaced only after validation passes.
                $stagingDir = $this->outputs->makeStagingDir($destDir);
                $written = $this->outputs->writeConvertedFiles($stagingDir, $files);

                // Validate metadata against the staged files first.
                $metaPayload = [
                    'pipeline_job_id' => $jobId,
                    'converted_id' => $convertedId,
                    'doc_id' => $convertedId,
                    'title' => $docTitle,
                    'source_pdf' => $docPath, // kept for backward compatibility
                    'source_file' => $docPath,
                    'source_size' => @filesize($docPath),
                    'source_mtime' => @filemtime($docPath) ? date('c', filemtime($docPath)) : null,
                    'output_dir' => $stagingDir,
                    'files' => $written,
                    'converted_at' => $this->timestamp(),
                    'tool' => 'DocumentConverter::requestDocumentToMarkdown',
                    'version' => 1,
                ];
                $metadataValidation = $validator->validateConversionMetadata($metaPayload);
                if ($metadataValidation['errors'] !== []) {
                    throw new \RuntimeException('Invalid conversion metadata: '.implode('; ', $metadataValidation['errors']));
                }

                $flatContent = $this->content->pickMarkdownContent($files);
                $flatMarkdownValidation = $validator->validateMarkdownContent($flatContent);
                if ($flatMarkdownValidation['errors'] !== []) {
                    throw new \RuntimeException('Invalid Markdown output: '.implode('; ', $flatMarkdownValidation['errors']));
                }

                $this->outputs->replaceDirectory($stagingDir, $destDir);
                $stagingDir = null;

                $metaPayload['output_dir'] = $destDir;
                $metadataValidation = $validator->validateConversionMetadata($metaPayload);
                if ($metadataValidation['errors'] !== []) {
                    throw new \RuntimeException('Invalid conversion metadata after publish: '.implode('; ', $metadataValidation['errors']));
                }

                $this->outputs->writeFileAtomically($metaPath, json_encode($metaPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                if ($flatContent !== null) {
                    $this->outputs->writeFileAtomically($flatPath, $flatContent);
                }

                $logger->success('convert', [
                    'job_id' => $jobId,
                    'doc_id' => $convertedId,
                    'file_path' => $docPath,
                    'title' => $docTitle,
                    'pipeline_stage' => 'document_conversion',
                    'output_dir' => $destDir,
                    'markdown_path' => $flatPath,
                    'metadata_path' => $metaPath,
                    'files_written' => count($written),
                    'warnings' => $metadataValidation['warnings'],
                ]);
                $processed++;
                $this->progress->update($state, $jobId, $outputDir, count($docPaths), $processed, $skipped, $failed);
            } catch (\Throwable $e) {
                $this->outputs->deleteDirectoryIfExists($stagingDir);

                $failed[] = [
                    'file_local_path' => $docPath,
                    'error' => $e->getMessage(),
                ];
                $logger->failed('convert', [
                    'job_id' => $jobId,
                    'doc_id' => isset($convertedId) && is_string($convertedId) ? $convertedId : null,
                    'file_path' => $docPath,
                    'title' => isset($docTitle) ? $docTitle : pathinfo($docPath, PATHINFO_FILENAME),
                    'pipeline_stage' => 'document_conversion',
                    'error_message' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $this->progress->update($state, $jobId, $outputDir, count($docPaths), $processed, $skipped, $failed);
            }
        }

        $bar->finish();
        $io->newLine(2);

        // Write failed_conversion.json in public/
        $this->reports->writeFailedJson($failed, $processed, count($docPaths), $skipped);

        // Console summary
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
            'total' => count($docPaths),
            'status_detail' => count($failed) > 0 ? 'partial' : 'complete',
        ];
        if (count($failed) > 0) {
            $logger->partial('convert', $summaryContext);
            $state->partialStage($jobId, PipelineStateService::STAGE_CONVERT, [
                'dataset_path' => $outputDir,
                'counts' => $this->progress->counts(count($docPaths), $processed, $skipped, $failed),
                'errors' => $failed,
                'max_retries' => $maxRetries,
                'metadata' => [
                    'statusDetail' => 'partial',
                    'extensions' => $extensions,
                    'scanAll' => $scanAll,
                ],
            ]);
        } else {
            $logger->success('convert', $summaryContext);
            $state->completeStage($jobId, PipelineStateService::STAGE_CONVERT, [
                'dataset_path' => $outputDir,
                'counts' => $this->progress->counts(count($docPaths), $processed, $skipped, $failed),
                'max_retries' => $maxRetries,
                'metadata' => [
                    'statusDetail' => 'complete',
                    'extensions' => $extensions,
                    'scanAll' => $scanAll,
                ],
            ]);
        }

        return count($failed) > 0 ? PipelineExitCode::PARTIAL_SUCCESS : PipelineExitCode::SUCCESS;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }

    private function conversionJobId(string $outputDir): string
    {
        return 'convert:'.substr(hash('sha256', realpath($outputDir) ?: $outputDir), 0, 16);
    }

    /**
     * Normalize extension list from CLI option.
     *
     * @return array<int,string>
     */
    private function parseExtensions(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $this->configuredExtensions();
        }
        $parts = array_map('trim', explode(',', $raw));
        $parts = array_filter($parts, static fn ($ext) => $ext !== '');
        $parts = array_map(static fn ($ext) => ltrim(strtolower($ext), '.'), $parts);

        return $parts ?: $this->configuredExtensions();
    }

    /**
     * @return array<int,string>
     */
    private function configuredExtensions(): array
    {
        $extensions = $this->config->get('file_converter.supported_extensions', ['pdf', 'doc', 'docx']);
        if (! is_array($extensions)) {
            return ['pdf', 'doc', 'docx'];
        }

        $extensions = array_values(array_filter(
            array_map(static fn ($extension) => is_scalar($extension) ? ltrim(strtolower(trim((string) $extension)), '.') : '', $extensions),
            static fn ($extension) => $extension !== ''
        ));

        return $extensions ?: ['pdf', 'doc', 'docx'];
    }

}
