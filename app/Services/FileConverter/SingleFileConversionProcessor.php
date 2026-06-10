<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\FileConverter\Exceptions\ConversionOutputException;
use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\Validation\PipelineDataValidator;
use Illuminate\Container\Attributes\Singleton;
use SplFileInfo;

#[Singleton]
readonly class SingleFileConversionProcessor
{
    public function __construct(
        private ConvertedOutputWriter $outputs,
        private ConversionRetryClient $retryClient,
        private ConversionOutputContent $content,
        private CachedConversionValidator $cachedConversions,
        private ConversionMetadataFactory $metadata,
        private DocumentContentHasher $hasher,
    ) {
    }

    public function process(
        string $docPath,
        string $jobId,
        bool $forceReprocess,
        int $maxRetries,
        int $retryDelayMs,
        DocumentConverter $converter,
        PipelineDataValidator $validator,
        PipelineStageLogger $logger,
    ): ConvertedFileProcessingResult {
        $convertedId = null;
        $docTitle = pathinfo($docPath, PATHINFO_FILENAME);
        $stagingDir = null;

        try {
            $docInfo = new SplFileInfo($docPath);
            $flatPath = dirname($docPath).'/'.pathinfo($docInfo->getFilename(), PATHINFO_FILENAME).'_converted.md';
            $convertedId = $this->hasher->sha256($docInfo->getPathname());

            $docTitle = pathinfo($docInfo->getFilename(), PATHINFO_FILENAME);
            $destDir = dirname($docPath).'/converted_'.pathinfo($docInfo->getFilename(), PATHINFO_FILENAME);
            $metaPath = $destDir.'/conversion_meta.json';

            $logger->started('convert', [
                'job_id' => $jobId,
                'doc_id' => $convertedId,
                'file_path' => $docPath,
                'title' => $docTitle,
                'pipeline_stage' => 'document_conversion',
            ]);

            if (! $forceReprocess && $this->cachedConversions->isValid($metaPath, $flatPath, $docPath, $destDir, $convertedId, $docTitle, $jobId, $validator, $logger)) {
                return ConvertedFileProcessingResult::skipped();
            }

            $files = $this->retryClient->convert($converter, $docInfo, $maxRetries, $retryDelayMs);
            $filesValidation = $validator->validateConvertedFiles($files);
            if ($filesValidation['errors'] !== []) {
                throw ConversionOutputException::invalidConverterOutput($filesValidation['errors']);
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

            $stagingDir = $this->outputs->makeStagingDir($destDir);
            $written = $this->outputs->writeConvertedFiles($stagingDir, $files);

            $metaPayload = $this->metadata->make($jobId, $convertedId, $docTitle, $docPath, $stagingDir, $written);
            $metadataValidation = $validator->validateConversionMetadata($metaPayload);
            if ($metadataValidation['errors'] !== []) {
                throw ConversionOutputException::invalidMetadata($metadataValidation['errors']);
            }

            $flatContent = $this->content->pickMarkdownContent($files);
            $flatMarkdownValidation = $validator->validateMarkdownContent($flatContent);
            if ($flatMarkdownValidation['errors'] !== []) {
                throw ConversionOutputException::invalidMarkdown($flatMarkdownValidation['errors']);
            }

            $this->outputs->replaceDirectory($stagingDir, $destDir);
            $stagingDir = null;

            $metaPayload['output_dir'] = $destDir;
            $metadataValidation = $validator->validateConversionMetadata($metaPayload);
            if ($metadataValidation['errors'] !== []) {
                throw ConversionOutputException::invalidPublishedMetadata($metadataValidation['errors']);
            }

            $encodedMeta = json_encode($metaPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (! is_string($encodedMeta)) {
                throw ConversionOutputException::invalidPublishedMetadata(['Unable to encode conversion metadata.']);
            }

            $this->outputs->writeFileAtomically($metaPath, $encodedMeta);
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

            return ConvertedFileProcessingResult::processed();
        } catch (\Throwable $exception) {
            $this->outputs->deleteDirectoryIfExists($stagingDir);
            $logger->failed('convert', [
                'job_id' => $jobId,
                'doc_id' => is_string($convertedId) ? $convertedId : null,
                'file_path' => $docPath,
                'title' => $docTitle,
                'pipeline_stage' => 'document_conversion',
                'error_message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return ConvertedFileProcessingResult::failed([
                'file_local_path' => $docPath,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
