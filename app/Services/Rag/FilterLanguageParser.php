<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Services\Rag\Values\FilterExpression;
use App\Services\SpecV2\Values\ReservedMetadataKeySet;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Validation\ValidationException;

#[Singleton]
readonly class FilterLanguageParser
{
    public function parse(mixed $input): FilterExpression
    {
        if ($input === null || $input === []) {
            return FilterExpression::empty();
        }

        if (! is_array($input)) {
            throw ValidationException::withMessages([
                'filters' => ['The filters field must be an object or array expression.'],
            ]);
        }

        return $this->parseNode($input);
    }

    /**
     * @param array<mixed> $node
     */
    private function parseNode(array $node): FilterExpression
    {
        if ($node === []) {
            return FilterExpression::empty();
        }

        if ($this->isLeafNode($node)) {
            return $this->parseLeaf((string) $node[0], $node[1]);
        }

        if (array_is_list($node)) {
            return $this->parseImplicitAnd($node);
        }

        $operators = $this->operatorKeys($node);
        if (count($operators) !== 1 || count($node) !== 1) {
            throw ValidationException::withMessages([
                'filters' => ['Filter objects may only contain one boolean operator. Use ["field", value] for leaf filters.'],
            ]);
        }

        $operator = $operators[0];

        return match ($operator) {
            'AND', 'OR' => $this->parseGroupOperator($operator, $node[$this->matchingKey($node, $operator)]),
            'NOT' => $this->parseNotOperator($node[$this->matchingKey($node, $operator)]),
        };
    }

    private function parseLeaf(string $field, mixed $value): FilterExpression
    {
        $normalizedField = $this->normalizeField($field);

        if ($normalizedField === '') {
            throw ValidationException::withMessages([
                'filters' => ['Filter field names must be non-empty strings.'],
            ]);
        }

        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'filters' => ["The filter field {$field} requires a non-empty value."],
            ]);
        }

        if (is_array($value) && $value === []) {
            throw ValidationException::withMessages([
                'filters' => ["The filter field {$field} requires at least one value."],
            ]);
        }

        if (is_array($value)) {
            foreach ($value as $candidate) {
                if (is_array($candidate) || $candidate === null || $candidate === '') {
                    throw ValidationException::withMessages([
                        'filters' => ["The filter field {$field} contains an invalid empty or nested value."],
                    ]);
                }
            }
        }

        if (! $this->isSystemField($normalizedField) && ReservedMetadataKeySet::contains($normalizedField)) {
            throw ValidationException::withMessages([
                'filters' => ["The metadata field {$field} is reserved."],
            ]);
        }

        return FilterExpression::leaf($normalizedField, $value);
    }

    private function parseGroupOperator(string $operator, mixed $value): FilterExpression
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw ValidationException::withMessages([
                'filters' => ["The {$operator} operator requires a non-empty array of child filter expressions."],
            ]);
        }

        $children = [];
        foreach ($value as $child) {
            if (! is_array($child) || $child === []) {
                throw ValidationException::withMessages([
                    'filters' => ["The {$operator} operator only accepts child filter expressions."],
                ]);
            }

            $children[] = $this->parseNode($child);
        }

        return FilterExpression::group($operator, $children);
    }

    private function parseNotOperator(mixed $value): FilterExpression
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages([
                'filters' => ['The NOT operator requires a single child filter expression.'],
            ]);
        }

        if (array_is_list($value) && ! $this->isLeafNode($value)) {
            throw ValidationException::withMessages([
                'filters' => ['The NOT operator requires a single child filter expression, not a list of siblings.'],
            ]);
        }

        return FilterExpression::group('NOT', [$this->parseNode($value)]);
    }

    /**
     * @param list<mixed> $node
     */
    private function parseImplicitAnd(array $node): FilterExpression
    {
        $children = [];

        foreach ($node as $child) {
            if (! is_array($child) || $child === []) {
                throw ValidationException::withMessages([
                    'filters' => ['Implicit AND filters only accept child filter expressions.'],
                ]);
            }

            $children[] = $this->parseNode($child);
        }

        return count($children) === 1 ? $children[0] : FilterExpression::group('AND', $children);
    }

    /**
     * @param array<mixed> $node
     */
    private function isLeafNode(array $node): bool
    {
        return array_is_list($node)
            && count($node) === 2
            && is_string($node[0] ?? null);
    }

    /**
     * @param array<mixed> $node
     * @return list<string>
     */
    private function operatorKeys(array $node): array
    {
        $operators = [];
        foreach (array_keys($node) as $key) {
            if (! is_string($key)) {
                continue;
            }

            $upper = strtoupper(trim($key));
            if (in_array($upper, ['AND', 'OR', 'NOT'], true)) {
                $operators[] = $upper;
            }
        }

        return array_values(array_unique($operators));
    }

    /**
     * @param array<mixed> $node
     */
    private function matchingKey(array $node, string $operator): string
    {
        foreach (array_keys($node) as $key) {
            if (is_string($key) && strtoupper(trim($key)) === $operator) {
                return $key;
            }
        }

        return $operator;
    }

    private function normalizeField(string $field): string
    {
        return trim($field);
    }

    private function isSystemField(string $field): bool
    {
        return in_array(strtolower($field), [
            'heap',
            'document_id',
            'owner_app',
            'visibility',
            'protected',
        ], true);
    }
}
