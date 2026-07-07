<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Services\Rag\Values\FilterExpression;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class QdrantFilterExpressionSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(FilterExpression $expression): array
    {
        if ($expression->isEmpty()) {
            return [];
        }

        return $this->clause($expression);
    }

    /**
     * @return array<string, mixed>
     */
    private function clause(FilterExpression $expression): array
    {
        if ($expression->isLeaf()) {
            return $this->leafClause((string) $expression->field, $expression->value);
        }

        return match ($expression->operator) {
            'OR' => $this->booleanClause('should', $expression->children),
            'NOT' => $this->booleanClause('must_not', $expression->children),
            default => $this->booleanClause('must', $expression->children),
        };
    }

    /**
     * @param list<FilterExpression> $children
     * @return array<string, mixed>
     */
    private function booleanClause(string $operator, array $children): array
    {
        $clauses = array_values(array_filter(array_map(
            fn (FilterExpression $child): array => $this->clause($child),
            $children,
        )));

        return $clauses === [] ? [] : [$operator => $clauses];
    }

    /**
     * @return array<string, mixed>
     */
    private function leafClause(string $field, mixed $value): array
    {
        if (is_array($value)) {
            $clauses = [];

            foreach ($value as $candidate) {
                foreach ($this->conditionsForField($field, $candidate) as $condition) {
                    $clauses[] = $condition;
                }
            }

            return $clauses === [] ? [] : ['should' => $clauses];
        }

        $conditions = $this->conditionsForField($field, $value);
        if ($conditions === []) {
            return [];
        }

        return count($conditions) === 1 ? $conditions[0] : ['should' => $conditions];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conditionsForField(string $field, mixed $value): array
    {
        $normalizedValue = $this->normalizeValue($value);

        if ($field === 'document_id') {
            return [
                $this->matchCondition('document_id', $normalizedValue),
                $this->matchCondition('doc_id', $normalizedValue),
            ];
        }

        return [$this->matchCondition($field, $normalizedValue)];
    }

    /**
     * @return array<string, mixed>
     */
    private function matchCondition(string $field, mixed $value): array
    {
        return [
            'key' => $field,
            'match' => ['value' => $value],
        ];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return (string) $value;
    }
}
