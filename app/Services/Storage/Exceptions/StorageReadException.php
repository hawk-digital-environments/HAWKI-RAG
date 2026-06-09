<?php

declare(strict_types=1);

namespace App\Services\Storage\Exceptions;

class StorageReadException extends \RuntimeException implements StorageExceptionInterface
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function jobReportNotFound(string $path): self
    {
        return new self("Job report file not found: {$path}");
    }

    public static function jobReportUnreadable(string $path): self
    {
        return new self("Failed to read job report: {$path}");
    }

    public static function invalidJobReportJson(string $path, string $error): self
    {
        return new self("Invalid JSON in job report: {$path} - {$error}");
    }

    public static function urlChunksFolderNotFound(string $path): self
    {
        return new self("URL chunks folder not found: {$path}");
    }

    public static function urlChunksEmpty(string $path): self
    {
        return new self("No URL chunk files found in: {$path}");
    }

    public static function invalidUrlChunkJson(string $path, string $error): self
    {
        return new self("Invalid JSON in URL chunk: {$path} - {$error}");
    }

    public static function elementDataNotFound(string $path): self
    {
        return new self("Element data file not found: {$path}");
    }

    public static function invalidElementDataJson(string $path, string $error): self
    {
        return new self("Invalid JSON in element data: {$path} - {$error}");
    }
}
