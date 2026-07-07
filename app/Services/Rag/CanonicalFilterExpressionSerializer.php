<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Services\Rag\Values\FilterExpression;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class CanonicalFilterExpressionSerializer
{
    /**
     * @return array<mixed>
     */
    public function serialize(FilterExpression $expression): array
    {
        if ($expression->isEmpty()) {
            return [];
        }

        return $this->node($expression);
    }

    /**
     * @return array<mixed>
     */
    private function node(FilterExpression $expression): array
    {
        if ($expression->isLeaf()) {
            return [
                (string) $expression->field,
                $this->normalizeValue($expression->value),
            ];
        }

        $children = array_values(array_filter(array_map(
            fn (FilterExpression $child): array => $this->node($child),
            $expression->children,
        )));

        if ($children === []) {
            return [];
        }

        return match ($expression->operator) {
            'NOT' => ['NOT', $children[0]],
            'OR' => ['OR', $children],
            default => ['AND', $children],
        };
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_values(array_map(fn (mixed $candidate): mixed => $this->normalizeValue($candidate), $value));
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }
}
