<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\FileConverter\Exceptions\ConversionOutputException;
use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\Validation\PipelineDataValidator;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class CachedConversionValidator
{
    public function __construct(
        private ConvertedOutputWriter $outputs,
        private ConversionOutputContent $content,
        private Filesystem $files,
    ) {
    }

    public function isValid(
        string $metaPath,
        string $flatPath,
        string $docPath,
        string $destDir,
        string $convertedId,
        string $docTitle,
        string $jobId,
        PipelineDataValidator $validator,
        PipelineStageLogger $logger,
    ): bool {
        if (! $this->files->isFile($metaPath)) {
            return false;
        }

        $meta = json_decode($this->outputs->readFile($metaPath), true);
        if (! is_array($meta) || ($meta['converted_id'] ?? null) !== $convertedId) {
            return false;
        }

        $meta = $this->content->normalizeCachedMetadata($meta, $docPath, $destDir, $convertedId, $docTitle, $jobId);
        $flatContent = $this->files->isFile($flatPath)
            ? $this->outputs->readFile($flatPath)
            : $this->content->loadMarkdownFromMeta($meta, $destDir);
        $markdownValidation = $validator->validateMarkdownContent($flatContent);
        $metadataValidation = $validator->validateConversionMetadata($meta);

        if ($flatContent !== null && $markdownValidation['errors'] === [] && $metadataValidation['errors'] === []) {
            $this->publishValidCache(
                $metaPath,
                $flatPath,
                $flatContent,
                $meta,
                array_merge($markdownValidation['warnings'], $metadataValidation['warnings']),
                $jobId,
                $convertedId,
                $docPath,
                $docTitle,
                $logger,
            );

            return true;
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

        return false;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function publishValidCache(
        string $metaPath,
        string $flatPath,
        string $flatContent,
        array $meta,
        array $warnings,
        string $jobId,
        string $convertedId,
        string $docPath,
        string $docTitle,
        PipelineStageLogger $logger,
    ): void {
        if (! $this->files->isFile($flatPath)) {
            $this->outputs->writeFileAtomically($flatPath, $flatContent);
        }

        $encoded = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            throw ConversionOutputException::invalidMetadata(['Unable to encode cached conversion metadata.']);
        }

        $this->outputs->writeFileAtomically($metaPath, $encoded);
        if ($warnings !== []) {
            $logger->partial('convert', [
                'job_id' => $jobId,
                'doc_id' => $convertedId,
                'file_path' => $docPath,
                'title' => $docTitle,
                'pipeline_stage' => 'cached_output',
                'warnings' => $warnings,
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
    }
}
