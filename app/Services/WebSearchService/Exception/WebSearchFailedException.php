<?php

namespace App\Services\WebSearchService\Exception;

class WebSearchFailedException extends \RuntimeException
{
    public function __construct(string $reason, ?\Throwable $previous = null){
        parent::__construct("WebSearch failed: $reason", 500, $previous);
    }
}
