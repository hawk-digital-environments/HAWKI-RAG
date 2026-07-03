<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Exceptions;

final class CorpusNotFoundException extends \RuntimeException implements SpecV2ExceptionInterface
{
    public static function withId(string $corpusId): self
    {
        return new self("Corpus {$corpusId} was not found.");
    }
}
