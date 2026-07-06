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

        $operators = $this->operatorKeys($node);
        if (count($operators) > 1) {
            throw ValidationException::withMessages([
                'filters' => ['A filter node may only contain one boolean operator.'],
            ]);
        }

        if ($operators !== []) {
            $operator = $operators[0];
            return match ($operator) {
                'AND', 'OR' => $this->parseGroupOperator($operator, $node[$this->matchingKey($node, $operator)]),
                'NOT' => $this->parseNotOperator($node[$this->matchingKey($node, $operator)]),
            };
        }

        if ($this->looksLikeLegacyFilterBody($node)) {
            return $this->parseLegacyFilterBody($node);
        }

        $children = [];
        foreach ($node as $field => $value) {
            if (! is_string($field)) {
                throw ValidationException::withMessages([
                    'filters' => ['Filter field names must be strings.'],
                ]);
            }

            $children[] = $this->parseLeaf($field, $value);
        }

        return count($children) === 1 ? $children[0] : FilterExpression::group('AND', $children);
    }

    private function parseLeaf(string $field, mixed $value): FilterExpression
    {
        $normalizedField = $this->normalizeField($field);

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

        if (! $this->isSystemField($normalizedField) && ReservedMetadataKeySet::contains($normalizedField)) {
            throw ValidationException::withMessages([
                'filters' => ["The metadata field {$field} is reserved."],
            ]);
        }

        return FilterExpression::leaf($normalizedField, $value);
    }

    private function parseGroupOperator(string $operator, mixed $value): FilterExpression
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages([
                'filters' => ["The {$operator} operator requires an array of child filters."],
            ]);
        }

        $children = [];
        foreach ($value as $child) {
            if (! is_array($child)) {
                throw ValidationException::withMessages([
                    'filters' => ["The {$operator} operator only accepts object children."],
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
                'filters' => ['The NOT operator requires a single child filter object.'],
            ]);
        }

        return FilterExpression::group('NOT', [$this->parseNode($value)]);
    }

    /**
     * @param array<mixed> $filter
     */
    private function parseLegacyFilterBody(array $filter): FilterExpression
    {
        $children = [];

        foreach ($filter['must'] ?? [] as $must) {
            $children[] = $this->parseLegacyNode($must);
        }

        if (isset($filter['should'])) {
            $children[] = FilterExpression::group('OR', array_map(
                fn (array $item): FilterExpression => $this->parseLegacyNode($item),
                array_values(array_filter($filter['should'], 'is_array')),
            ));
        }

        foreach ($filter['must_not'] ?? [] as $mustNot) {
            $children[] = FilterExpression::group('NOT', [$this->parseLegacyNode($mustNot)]);
        }

        return $children === [] ? FilterExpression::empty() : FilterExpression::group('AND', $children);
    }

    /**
     * @param array<mixed> $node
     */
    private function parseLegacyNode(array $node): FilterExpression
    {
        if ($this->looksLikeLegacyFilterBody($node)) {
            return $this->parseLegacyFilterBody($node);
        }

        $field = $node['key'] ?? null;
        if (! is_string($field) || trim($field) === '') {
            throw ValidationException::withMessages([
                'filters' => ['Legacy filter nodes require a key.'],
            ]);
        }

        if (isset($node['match']) && is_array($node['match']) && array_key_exists('value', $node['match'])) {
            return $this->parseLeaf($field, $node['match']['value']);
        }

        throw ValidationException::withMessages([
            'filters' => ['Legacy filter nodes currently support match.value only.'],
        ]);
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

    /**
     * @param array<mixed> $node
     */
    private function looksLikeLegacyFilterBody(array $node): bool
    {
        return array_key_exists('must', $node)
            || array_key_exists('should', $node)
            || array_key_exists('must_not', $node);
    }

    private function normalizeField(string $field): string
    {
        $trimmed = trim($field);

        if (str_starts_with($trimmed, 'metadata.')) {
            return substr($trimmed, strlen('metadata.'));
        }

        if ($trimmed === 'doc_id') {
            return 'document_id';
        }

        return $trimmed;
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
