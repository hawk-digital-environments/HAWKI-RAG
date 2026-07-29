<?php

declare(strict_types=1);

namespace App\Services\Graph\Exceptions;

final class GraphSnapshotException extends \RuntimeException implements GraphExceptionInterface
{
    public static function operationFailed(string $operation, string $path, \Throwable $previous): self
    {
        return new self("Graph snapshot {$operation} failed for {$path}: {$previous->getMessage()}", 0, $previous);
    }

    public static function encodingFailed(string $path): self
    {
        return new self("Unable to encode graph snapshot for {$path}.");
    }
}
