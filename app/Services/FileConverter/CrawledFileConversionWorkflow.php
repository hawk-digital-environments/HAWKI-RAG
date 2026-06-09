<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Pipeline\Validation\PipelineDataValidator;
use App\Support\PipelineExitCode;
use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class CrawledFileConversionWorkflow
{
    private ConsoleWorkflowIO $io;

    public function __construct(
        private readonly CrawledFileDiscovery $discovery,
        private readonly Filesystem $files,
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
        $this->io = $io;
        $outputDirArg = $this->argument('outputDir');
        if ($outputDirArg) {
            $outputDir = $this->discovery->resolveOutputDir((string) $outputDirArg);
            if (! is_dir($outputDir)) {
                $this->error("Output dir not found: $outputDir");

                return PipelineExitCode::VALIDATION_FAILURE;
            }
        } else {
            if ($this->automationEnabled() || ! $this->io->isInteractive()) {
                $this->error('Output dir is required in automation or non-interactive mode.');

                return PipelineExitCode::VALIDATION_FAILURE;
            }

            $outputDir = $this->discovery->pickOutputDir($this->io);
            if (! $outputDir) {
                return PipelineExitCode::VALIDATION_FAILURE;
            }
        }

        $jobId = (string) ($this->option('job-id') ?: $this->conversionJobId($outputDir));
        $logger->started('convert', [
            'job_id' => $jobId,
            'output_dir' => $outputDir,
            'extensions' => $this->option('extensions'),
            'scan_all' => (bool) $this->option('scan-all'),
        ]);

        // Find supported files under outputDir (recursive)
        $extensions = $this->parseExtensions((string) $this->option('extensions'));
        $scanAll = (bool) $this->option('scan-all');
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
            $this->warn("No supported files found under $outputDir (extensions: {$extLabel}; scope: {$scopeLabel})");
            $this->writeFailedJson([], 0, 0, 0); // write empty report
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

        $this->info('Found '.count($docPaths).' supported file(s). Converting...');

        $existingMetaCount = 0;
        foreach ($docPaths as $docPath) {
            $destDir = dirname($docPath).'/converted_'.pathinfo($docPath, PATHINFO_FILENAME);
            if (is_file($destDir.'/conversion_meta.json')) {
                $existingMetaCount++;
            }
        }

        $forceReprocess = false;
        if ($existingMetaCount > 0) {
            $this->line("Detected {$existingMetaCount} previously converted document(s) in this directory.");
            $choice = $this->resolveExistingOutputMode((string) $this->option('existing'));

            if ($choice === 'cancel') {
                $this->info('Conversion cancelled by user request.');
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
                $this->warn('Restart selected — existing converted outputs will be re-generated.');
            } else {
                $this->info('Continuing will skip already converted documents when their hashes match.');
            }
        }

        $failed = [];
        $processed = 0;
        $skipped = 0;

        // Read retry config (set these in config/services.php via env)
        $maxRetries = (int) $this->config->get('file_converter.retries', 3);
        $retryDelayMs = (int) $this->config->get('file_converter.retry_delay_ms', 1500);

        $bar = $this->io->output()->createProgressBar(count($docPaths));
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
                    $meta = json_decode(@file_get_contents($metaPath), true);
                    if (is_array($meta) && ($meta['converted_id'] ?? null) === $convertedId) {
                        $meta = $this->normalizeCachedMetadata($meta, $docPath, $destDir, $convertedId, $docTitle, $jobId);
                        $flatContent = is_file($flatPath)
                            ? (string) file_get_contents($flatPath)
                            : $this->loadMarkdownFromMeta($meta, $destDir);
                        $markdownValidation = $validator->validateMarkdownContent($flatContent);
                        $metadataValidation = $validator->validateConversionMetadata($meta);

                        if ($flatContent !== null && $markdownValidation['errors'] === [] && $metadataValidation['errors'] === []) {
                            if (! is_file($flatPath)) {
                                $this->writeFileAtomically($flatPath, $flatContent);
                            }
                            $this->writeFileAtomically($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
                            $this->updateConversionProgress($state, $jobId, $outputDir, count($docPaths), $processed, $skipped, $failed);

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
                $files = $this->convertWithRetry($converter, $docInfo, $maxRetries, $retryDelayMs);
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
                $stagingDir = $this->makeStagingDir($destDir);
                $this->files->makeDirectory($stagingDir, 0755, true, true);
                $written = [];
                foreach ($files as $relative => $content) {
                    $outPath = $stagingDir.'/'.ltrim($relative, '/');
                    $this->files->ensureDirectoryExists(dirname($outPath));
                    $this->files->put($outPath, $content);
                    $written[] = $this->discovery->makePathRelative($outPath, $stagingDir);
                }

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

                $flatContent = $this->pickMarkdownContent($files);
                $flatMarkdownValidation = $validator->validateMarkdownContent($flatContent);
                if ($flatMarkdownValidation['errors'] !== []) {
                    throw new \RuntimeException('Invalid Markdown output: '.implode('; ', $flatMarkdownValidation['errors']));
                }

                $this->replaceDirectory($stagingDir, $destDir);
                $stagingDir = null;

                $metaPayload['output_dir'] = $destDir;
                $metadataValidation = $validator->validateConversionMetadata($metaPayload);
                if ($metadataValidation['errors'] !== []) {
                    throw new \RuntimeException('Invalid conversion metadata after publish: '.implode('; ', $metadataValidation['errors']));
                }

                $this->writeFileAtomically($metaPath, json_encode($metaPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                if ($flatContent !== null) {
                    $this->writeFileAtomically($flatPath, $flatContent);
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
                $this->updateConversionProgress($state, $jobId, $outputDir, count($docPaths), $processed, $skipped, $failed);
            } catch (\Throwable $e) {
                if (is_string($stagingDir) && $this->files->isDirectory($stagingDir)) {
                    $this->files->deleteDirectory($stagingDir);
                }

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
                $this->updateConversionProgress($state, $jobId, $outputDir, count($docPaths), $processed, $skipped, $failed);
            }
        }

        $bar->finish();
        $this->newLine(2);

        // Write failed_conversion.json in public/
        $this->writeFailedJson($failed, $processed, count($docPaths), $skipped);

        // Console summary
        $this->info("Processed docs : {$processed}");
        $this->info("Skipped (cached): {$skipped}");
        $this->info('Failed docs    : '.count($failed));

        if (! empty($failed)) {
            $this->warn('See storage/logs/failed_conversion.json for details.');
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
                'counts' => $this->conversionCounts(count($docPaths), $processed, $skipped, $failed),
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
                'counts' => $this->conversionCounts(count($docPaths), $processed, $skipped, $failed),
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

    private function updateConversionProgress(
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
            'counts' => $this->conversionCounts($total, $processed, $skipped, $failed),
            'errors' => $failed,
            'max_retries' => (int) $this->config->get('file_converter.retries', 3),
        ]);
    }

    private function conversionCounts(int $total, int $processed, int $skipped, array $failed): array
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

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }

    private function conversionJobId(string $outputDir): string
    {
        return 'convert:'.substr(hash('sha256', realpath($outputDir) ?: $outputDir), 0, 16);
    }

    private function resolveExistingOutputMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        $allowed = ['ask', 'continue', 'restart', 'cancel'];
        if (! in_array($mode, $allowed, true)) {
            $this->warn('Invalid --existing value. Continuing and validating cached outputs.');

            return 'continue';
        }

        if ($mode !== 'ask') {
            return $mode;
        }

        if ($this->automationEnabled() || ! $this->io->isInteractive()) {
            $default = $this->configuredExistingOutputMode();
            $this->info("Automation/non-interactive run detected; using existing output mode '{$default}'.");

            return $default;
        }

        return $this->choice(
            'How would you like to proceed?',
            ['continue', 'restart', 'cancel'],
            0
        );
    }

    private function automationEnabled(): bool
    {
        return (bool) $this->config->get('config.pipeline_automation', false);
    }

    private function configuredExistingOutputMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('config.convert_existing_mode', 'continue')));

        return in_array($mode, ['continue', 'restart', 'cancel'], true) ? $mode : 'continue';
    }

    /**
     * Try converting a supported file up to $maxRetries times with a delay.
     * Retries only on likely-transient errors (timeouts / 5xx).
     *
     * @return array<string,string> files map [relativePath => content]
     */
    private function convertWithRetry(
        DocumentConverter $converter,
        SplFileInfo $fileInfo,
        int $maxRetries,
        int $retryDelayMs
    ): array {
        $attempt = 0;
        $lastEx = null;

        while ($attempt <= $maxRetries) {
            try {
                return $converter->requestDocumentToMarkdown($fileInfo);
            } catch (\Throwable $e) {
                $lastEx = $e;
                $msg = (string) $e->getMessage();

                // Decide if this is worth retrying
                $isTimeout = str_contains($msg, 'cURL error 28') || str_contains($msg, 'Operation timed out');
                $is5xx = preg_match('/\bHTTP\/?1\.[01]\s+5\d{2}\b/i', $msg) === 1 || str_contains($msg, ' 5');

                if ($attempt === $maxRetries || (! ($isTimeout || $is5xx))) {
                    break; // give up
                }

                // Backoff
                usleep($retryDelayMs * 1000);
                $attempt++;
            }
        }

        // If we get here, all attempts failed
        throw $lastEx ?? new \RuntimeException('Unknown error during conversion.');
    }

    /**
     * Write failed_conversion.json into public/ with summary stats.
     */
    private function writeFailedJson(array $failed, int $processed, int $total, int $skipped): void
    {
        $payload = [
            'generated_at' => $this->timestamp(),
            'total' => $total,
            'processed' => $processed,
            'skipped' => $skipped,
            'failed' => count($failed),
            'failures' => $failed, // each: { file_local_path, error }
        ];

        $dest = storage_path('logs/failed_conversion.json');

        // Write atomically
        $tmp = $dest.'.tmp';
        $this->files->ensureDirectoryExists(dirname($dest));
        $this->files->put($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $dest);
    }

    private function makeStagingDir(string $destDir): string
    {
        return dirname($destDir)
            .DIRECTORY_SEPARATOR
            .'.'
            .basename($destDir)
            .'.tmp-'
            .(string) Str::uuid();
    }

    private function replaceDirectory(string $sourceDir, string $destDir): void
    {
        if (! $this->files->isDirectory($sourceDir)) {
            throw new \RuntimeException("Staging directory not found: {$sourceDir}");
        }

        if ($this->files->isDirectory($destDir) && ! $this->files->deleteDirectory($destDir)) {
            throw new \RuntimeException("Unable to remove existing conversion output at {$destDir}.");
        }

        if ($this->files->isFile($destDir) && ! $this->files->delete($destDir)) {
            throw new \RuntimeException("Unable to remove file blocking conversion output at {$destDir}.");
        }

        if (! @rename($sourceDir, $destDir)) {
            throw new \RuntimeException("Unable to publish conversion output to {$destDir}.");
        }
    }

    private function writeFileAtomically(string $path, string $content): void
    {
        $this->files->ensureDirectoryExists(dirname($path));
        $tmp = $path.'.tmp-'.(string) Str::uuid();
        $this->files->put($tmp, $content);

        if (! @rename($tmp, $path)) {
            $this->files->delete($tmp);
            throw new \RuntimeException("Unable to write file atomically: {$path}");
        }
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

    /**
     * Pick a reasonable markdown payload from the converter output.
     *
     * @param  array<string,string>  $files
     */
    private function pickMarkdownContent(array $files): ?string
    {
        foreach ($files as $relative => $content) {
            if (str_ends_with(strtolower($relative), '.md')) {
                return $content;
            }
        }

        if ($files === []) {
            return null;
        }

        return implode("\n\n", array_values($files));
    }

    private function loadMarkdownFromMeta(array $meta, string $destDir): ?string
    {
        $files = $meta['files'] ?? [];
        if (! is_array($files)) {
            return null;
        }

        foreach ($files as $relative) {
            if (! is_string($relative)) {
                continue;
            }
            if (! str_ends_with(strtolower($relative), '.md')) {
                continue;
            }
            $path = $destDir.'/'.ltrim($relative, '/');
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        return null;
    }

    private function normalizeCachedMetadata(
        array $meta,
        string $sourcePath,
        string $destDir,
        string $convertedId,
        string $docTitle,
        string $jobId
    ): array {
        $meta['pipeline_job_id'] = $jobId;
        $meta['converted_id'] = $convertedId;
        $meta['doc_id'] = $meta['doc_id'] ?? $convertedId;
        $meta['title'] = $meta['title'] ?? $docTitle;
        $meta['source_file'] = $meta['source_file'] ?? ($meta['source_pdf'] ?? $sourcePath);
        $meta['source_pdf'] = $meta['source_pdf'] ?? $sourcePath;
        $meta['output_dir'] = $meta['output_dir'] ?? $destDir;
        $meta['converted_at'] = $meta['converted_at'] ?? $this->timestamp();

        if (! isset($meta['files']) || ! is_array($meta['files']) || $meta['files'] === []) {
            $meta['files'] = $this->collectCachedOutputFiles($destDir);
        }

        return $meta;
    }

    /**
     * @return array<int,string>
     */
    private function collectCachedOutputFiles(string $destDir): array
    {
        if (! is_dir($destDir)) {
            return [];
        }

        $files = [];
        $finder = Finder::create()
            ->files()
            ->ignoreUnreadableDirs()
            ->in($destDir);

        foreach ($finder as $file) {
            if ($file->getFilename() === 'conversion_meta.json') {
                continue;
            }
            $files[] = $this->discovery->makePathRelative($file->getPathname(), $destDir);
        }
        sort($files);

        return $files;
    }

    private function argument(string $name): mixed
    {
        return $this->io->argument($name);
    }

    private function option(string $name): mixed
    {
        return $this->io->option($name);
    }

    private function choice(string $question, array $choices, mixed $default = null): mixed
    {
        return $this->io->choice($question, $choices, $default);
    }

    private function line(string $message): void
    {
        $this->io->line($message);
    }

    private function info(string $message): void
    {
        $this->io->info($message);
    }

    private function error(string $message): void
    {
        $this->io->error($message);
    }

    private function warn(string $message): void
    {
        $this->io->warn($message);
    }

    private function newLine(int $count = 1): void
    {
        $this->io->newLine($count);
    }
}
