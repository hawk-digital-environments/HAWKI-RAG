<?php

declare(strict_types=1);

namespace App\Services\WebSearch\Exceptions;

class WebSearchFailedException extends \RuntimeException
{
    public function __construct(string $reason, ?\Throwable $previous = null){
        parent::__construct("WebSearch failed: $reason", 500, $previous);
    }
}
