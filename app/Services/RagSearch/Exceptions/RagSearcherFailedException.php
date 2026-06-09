<?php

declare(strict_types=1);

namespace App\Services\RagSearch\Exceptions;

class RagSearcherFailedException extends \RuntimeException
{
    public function __construct(string $query, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Failed to extract responses for "%s"', $query), 0, $previous);
    }
}
