<?php

declare(strict_types=1);

namespace App\Services\Rag\Values;

readonly class FilterExpression
{
    private const TYPE_GROUP = 'group';
    private const TYPE_LEAF = 'leaf';

    /**
     * @param list<self> $children
     */
    private function __construct(
        public string $type,
        public ?string $operator = null,
        public ?string $field = null,
        public mixed $value = null,
        public array $children = [],
    ) {}

    public static function empty(): self
    {
        return self::group('AND', []);
    }

    /**
     * @param list<self> $children
     */
    public static function group(string $operator, array $children): self
    {
        return new self(self::TYPE_GROUP, strtoupper($operator), null, null, $children);
    }

    public static function leaf(string $field, mixed $value): self
    {
        return new self(self::TYPE_LEAF, null, $field, $value, []);
    }

    public function isEmpty(): bool
    {
        return $this->type === self::TYPE_GROUP
            && $this->operator === 'AND'
            && $this->children === [];
    }

    public function isLeaf(): bool
    {
        return $this->type === self::TYPE_LEAF;
    }
}
