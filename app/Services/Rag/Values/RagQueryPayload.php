<?php

declare(strict_types=1);

namespace App\Services\Rag\Values;

use App\Services\Authorization\Values\RetrievalAuthorizationContext;

readonly class RagQueryPayload
{
    /**
     * @param list<string>|null $preferredTags
     */
    public function __construct(
        public string $query,
        public int $topK = 5,
        public bool $isOptimized = false,
        public bool $generate = true,
        public bool $fastMode = false,
        public bool $smartLookup = false,
        public ?array $preferredTags = null,
        public ?RetrievalAuthorizationContext $authContext = null,
    ) {}

    /**
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input, ?RetrievalAuthorizationContext $authContext = null): self
    {
        return new self(
            query: (string) $input['query'],
            topK: (int) ($input['top_k'] ?? 5),
            isOptimized: (bool) ($input['is_optimized'] ?? false),
            generate: (bool) ($input['generate'] ?? true),
            fastMode: (bool) ($input['fast_mode'] ?? false),
            smartLookup: (bool) ($input['smart_lookup'] ?? false),
            preferredTags: self::preferredTags($input['preferred_tags'] ?? null),
            authContext: $authContext,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'query' => $this->query,
            'top_k' => $this->topK,
            'is_optimized' => $this->isOptimized,
            'generate' => $this->generate,
            'fast_mode' => $this->fastMode,
            'smart_lookup' => $this->smartLookup,
        ];

        if ($this->preferredTags !== null && $this->preferredTags !== []) {
            $payload['preferred_tags'] = $this->preferredTags;
        }

        if ($this->authContext !== null) {
            $payload['auth_context'] = $this->authContext->toArray();
        }

        return $payload;
    }

    /**
     * @return list<string>|null
     */
    private static function preferredTags(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $tags = array_values(array_filter(
            array_map(static fn (mixed $tag): ?string => is_string($tag) && trim($tag) !== '' ? trim($tag) : null, $value),
            static fn (?string $tag): bool => $tag !== null,
        ));

        return $tags === [] ? null : $tags;
    }
}
