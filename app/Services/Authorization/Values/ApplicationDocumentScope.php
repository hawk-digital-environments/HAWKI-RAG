<?php

declare(strict_types=1);

namespace App\Services\Authorization\Values;

use App\Services\Rag\Values\FilterExpression;

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
        public FilterExpression $searchExpression,
    ) {}

    public static function unrestricted(): self
    {
        return new self(true, [], null, FilterExpression::empty());
    }

    /**
     * @param array<string, mixed> $repositoryFilters
     * @param list<string> $documentIds
     */
    public static function constrained(array $repositoryFilters, array $documentIds, FilterExpression $searchExpression): self
    {
        return new self(false, $repositoryFilters, array_values(array_unique($documentIds)), $searchExpression);
    }

    public static function none(): self
    {
        return new self(false, ['document_ids' => []], [], FilterExpression::leaf('document_id', '__rawki_no_match__'));
    }

    public function matchesNothing(): bool
    {
        return ! $this->unrestricted && $this->documentIds === [];
    }
}
