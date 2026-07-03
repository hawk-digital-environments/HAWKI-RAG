<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Exceptions;

final class HeapNotFoundException extends \RuntimeException implements SpecV2ExceptionInterface
{
    public static function withId(string $heapId): self
    {
        return new self("Heap {$heapId} was not found.");
    }
}
