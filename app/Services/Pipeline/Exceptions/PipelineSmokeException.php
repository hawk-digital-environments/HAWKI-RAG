<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Exceptions;

class PipelineSmokeException extends \RuntimeException implements PipelineExceptionInterface
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function zipArchiveMissing(): self
    {
        return new self('PHP ZipArchive extension is required to create the smoke DOCX fixture. Enable ext-zip before running pipeline:smoke-test.');
    }

    public static function fixtureCreationFailed(string $path): self
    {
        return new self("Could not create DOCX fixture at {$path}. Check that the smoke fixture directory is writable.");
    }

    public static function invalidGraphOption(): self
    {
        return new self('The --graph option must be true, false, or auto.');
    }

    public static function missingScrapeJob(): self
    {
        return new self('No scrape job was created for the smoke task.');
    }

    public static function unexpectedScrapeJobStatus(string $jobId, string $status): self
    {
        return new self("Scrape job {$jobId} has unexpected status {$status}.");
    }

    public static function missingScrapeRequestedEvent(): self
    {
        return new self('No scrape.requested pipeline event was recorded for the scrape job.');
    }

    public static function convertDidNotComplete(string $jobId): self
    {
        return new self("Convert job {$jobId} did not complete.");
    }

    public static function convertMarkdownMissing(string $jobId): self
    {
        return new self("Convert job {$jobId} did not produce readable Markdown.");
    }

    public static function ingestionMissingDocument(): self
    {
        return new self('Ingestion completed without creating a completed document record.');
    }

    public static function bridgeResponseNotOk(): self
    {
        return new self('Document bridge_response is not ok.');
    }

    public static function documentMissingIngestJob(): self
    {
        return new self('Document is missing the related ingestion job id.');
    }

    public static function qdrantHttpFailed(int $status, string $collection): self
    {
        return new self("Qdrant returned HTTP {$status} for collection {$collection}.");
    }

    public static function qdrantPointMissing(string $jobId, string $taskId, string $collection): self
    {
        return new self("No Qdrant point found for job {$jobId} or task {$taskId} in collection {$collection}.");
    }

    public static function neo4jHttpFailed(int $status): self
    {
        return new self("Neo4j returned HTTP {$status}.");
    }

    public static function neo4jReturnedErrors(string $errors): self
    {
        return new self("Neo4j returned errors: {$errors}");
    }

    public static function neo4jRecordsMissing(string $documentJobId): self
    {
        return new self("No Neo4j graph records found for smoke document {$documentJobId}.");
    }
}
