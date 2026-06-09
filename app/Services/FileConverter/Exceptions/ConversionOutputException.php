<?php

declare(strict_types=1);

namespace App\Services\FileConverter\Exceptions;

final class ConversionOutputException extends \RuntimeException implements FileConverterExceptionInterface
{
    public static function invalidFileInput(): self
    {
        return new self('Invalid file input. Expected UploadedFile or SplFileInfo.');
    }

    public static function openDocumentFailed(string $filename): self
    {
        return new self("Unable to open document for conversion: {$filename}");
    }

    public static function documentExtractionFailed(int $status, string $body): self
    {
        return new self(sprintf(
            'Document extraction failed with HTTP %s: %s',
            $status,
            $body,
        ));
    }

    public static function zipOpenFailed(): self
    {
        return new self('Failed to open ZIP file.');
    }

    public static function unableToHash(string $path): self
    {
        return new self("Unable to hash document at {$path}; hash_file returned false.");
    }

    /**
     * @param list<string> $errors
     */
    public static function invalidConverterOutput(array $errors): self
    {
        return new self('Invalid converter output: '.implode('; ', $errors));
    }

    /**
     * @param list<string> $errors
     */
    public static function invalidMetadata(array $errors): self
    {
        return new self('Invalid conversion metadata: '.implode('; ', $errors));
    }

    /**
     * @param list<string> $errors
     */
    public static function invalidPublishedMetadata(array $errors): self
    {
        return new self('Invalid conversion metadata after publish: '.implode('; ', $errors));
    }

    /**
     * @param list<string> $errors
     */
    public static function invalidMarkdown(array $errors): self
    {
        return new self('Invalid Markdown output: '.implode('; ', $errors));
    }

    public static function unknownConversionError(): self
    {
        return new self('Unknown error during conversion.');
    }

    public static function missingStagingDirectory(string $path): self
    {
        return new self("Staging directory not found: {$path}");
    }

    public static function removeExistingOutputFailed(string $path): self
    {
        return new self("Unable to remove existing conversion output at {$path}.");
    }

    public static function removeBlockingFileFailed(string $path): self
    {
        return new self("Unable to remove file blocking conversion output at {$path}.");
    }

    public static function publishFailed(string $sourceDir, string $destDir): self
    {
        return new self("Unable to publish conversion output from {$sourceDir} to {$destDir}.");
    }

    public static function atomicWriteFailed(string $path): self
    {
        return new self("Unable to write file atomically: {$path}");
    }
}
