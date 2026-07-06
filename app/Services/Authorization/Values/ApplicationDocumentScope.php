<?php

declare(strict_types=1);

namespace App\Services\Authorization\Values;

readonly class ApplicationDocumentScope
{
    /**
     * @param array<string, mixed> $repositoryFilters
     * @param list<string>|null $documentIds
     */
    private function __construct(
        public bool $unrestricted,
        public array $repositoryFilters,
        public ?array $documentIds,
    ) {}

    public static function unrestricted(): self
    {
        return new self(true, [], null);
    }

    /**
     * @param array<string, mixed> $repositoryFilters
     * @param list<string> $documentIds
     */
    public static function constrained(array $repositoryFilters, array $documentIds): self
    {
        return new self(false, $repositoryFilters, array_values(array_unique($documentIds)));
    }

    public static function none(): self
    {
        return new self(false, ['document_ids' => []], []);
    }

    public function matchesNothing(): bool
    {
        return ! $this->unrestricted && $this->documentIds === [];
    }
}
