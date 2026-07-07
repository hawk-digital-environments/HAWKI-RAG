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

        if ($this->isOperatorNode($node)) {
            return $this->parseOperatorNode($node);
        }

        if ($this->isLeafNode($node)) {
            return $this->parseLeaf((string) $node[0], $node[1]);
        }

        if (array_is_list($node)) {
            return $this->parseImplicitAnd($node);
        }

        throw ValidationException::withMessages([
            'filters' => ['Boolean filter operators must use array syntax such as ["AND", [...]], ["OR", [...]], or ["NOT", expression].'],
        ]);
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

    /**
     * @param array<mixed> $node
     */
    private function parseOperatorNode(array $node): FilterExpression
    {
        $operator = strtoupper(trim((string) $node[0]));

        if (count($node) !== 2) {
            throw ValidationException::withMessages([
                'filters' => ["The {$operator} operator requires exactly one value."],
            ]);
        }

        return match ($operator) {
            'AND', 'OR' => $this->parseGroupOperator($operator, $node[1]),
            'NOT' => $this->parseNotOperator($node[1]),
            default => throw ValidationException::withMessages([
                'filters' => ['Unknown boolean filter operator.'],
            ]),
        };
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

        if (array_is_list($value) && ! $this->isLeafNode($value) && ! $this->isOperatorNode($value)) {
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
            && is_string($node[0] ?? null)
            && ! in_array(strtoupper(trim((string) $node[0])), ['AND', 'OR', 'NOT'], true);
    }

    /**
     * @param array<mixed> $node
     */
    private function isOperatorNode(array $node): bool
    {
        return array_is_list($node)
            && isset($node[0])
            && is_string($node[0])
            && in_array(strtoupper(trim($node[0])), ['AND', 'OR', 'NOT'], true);
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
