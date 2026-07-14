<?php

declare(strict_types=1);

namespace App\Services\RagSearch\Exceptions;

class RagSearcherFailedException extends \RuntimeException implements RagSearchExceptionInterface
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function missingQuery(): self
    {
        return new self('Failed to extract RAG responses because no query was provided.');
    }

    public static function missingAuthorizedDatasetScope(): self
    {
        return new self('Failed to execute RAG search because no authorized dataset scope was provided.');
    }

    public static function backendRequestFailed(string $query, string $baseUrl): self
    {
        return new self(sprintf('Failed to extract RAG responses for "%s" because the backend at %s returned an unsuccessful response.', $query, $baseUrl));
    }

    public static function invalidTopK(int $topK): self
    {
        return new self("Failed to configure RAG search because topK must be a positive integer; {$topK} was provided.");
    }

    public static function connectionFailed(string $query, \Throwable $previous): self
    {
        return new self(sprintf('Failed to extract RAG responses for "%s".', $query), $previous);
    }
}
