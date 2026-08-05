<?php

declare(strict_types=1);

namespace App\Services\Graph\Exceptions;

final class GraphCacheException extends \RuntimeException implements GraphExceptionInterface
{
    public static function bridgeClearFailed(int $status): self
    {
        return new self("Python RAG bridge failed to clear graph cache with HTTP {$status}.");
    }

    public static function bridgeClearRequestFailed(\Throwable $previous): self
    {
        return new self('Python RAG bridge cache clear request failed: '.$previous->getMessage(), 0, $previous);
    }

}
