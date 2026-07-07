<?php

declare(strict_types=1);

namespace App\Services\Rag\Values;

readonly class RagQueryPayload
{
    public function __construct(
        public string $query,
        public int $limit = 5,
        public ?array $filters = null,
    ) {}

    /**
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        return new self(
            query: (string) $input['query'],
            limit: (int) ($input['limit'] ?? 5),
            filters: is_array($input['filters'] ?? null) ? $input['filters'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'limit' => $this->limit,
            'filters' => $this->filters ?? [],
        ];
    }
}
