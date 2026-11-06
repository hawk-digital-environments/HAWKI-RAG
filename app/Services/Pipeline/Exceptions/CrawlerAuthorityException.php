<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Exceptions;

final class CrawlerAuthorityException extends \RuntimeException implements PipelineExceptionInterface
{
    public static function pythonOwned(): self
    {
        return new self('Laravel URL orchestration is disabled. Use the authenticated Python URL ingestion boundary.');
    }
}
