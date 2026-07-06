<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Services\Authorization\Values\ApplicationDocumentScope;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class GatewaySearchFilterService
{
    private const NO_MATCH_DOCUMENT_ID = '__rawki_no_match__';

    public function __construct(
        private ApplicationScopeResolver $scopes,
    ) {}

    /**
     * @param array<string, mixed> $clientFilters
     * @return array<string, mixed>
     */
    public function build(array $clientFilters, ApiActor $actor, ?string $requestedUserIdentifier = null): array
    {
        $clientFilter = $this->normalizeClientFilters($clientFilters);
        $scope = $this->scopes->resolve($actor, $requestedUserIdentifier);

        if ($scope->unrestricted) {
            return $clientFilter;
        }

        $documentFilter = $this->documentAllowFilter($scope);

        if ($clientFilter === []) {
            return $documentFilter;
        }

        return ['must' => [$documentFilter, $clientFilter]];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeClientFilters(array $filters): array
    {
        if ($filters === []) {
            return [];
        }

        if ($this->looksLikeFilterBody($filters)) {
            return $this->normalizeFilterBody($filters);
        }

        $must = [];

        foreach ($filters as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $field = $this->normalizeFilterKey($key);
            if (is_array($value)) {
                $matches = [];
                foreach ($value as $candidate) {
                    $normalizedValue = $this->normalizeFilterValue($candidate);
                    if ($normalizedValue !== null) {
                        $matches[] = ['key' => $field, 'match' => ['value' => $normalizedValue]];
                    }
                }

                if ($matches === []) {
                    continue;
                }

                $must[] = count($matches) === 1 ? $matches[0] : ['should' => $matches];
                continue;
            }

            $normalizedValue = $this->normalizeFilterValue($value);
            if ($normalizedValue === null) {
                continue;
            }

            $must[] = ['key' => $field, 'match' => ['value' => $normalizedValue]];
        }

        return $must === [] ? [] : ['must' => $must];
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private function normalizeFilterBody(array $filter): array
    {
        $normalized = [];

        foreach (['must', 'should', 'must_not'] as $clause) {
            $items = $filter[$clause] ?? null;
            if (! is_array($items)) {
                continue;
            }

            $normalizedItems = [];
            foreach ($items as $item) {
                $normalizedItem = $this->normalizeFilterNode($item);
                if ($normalizedItem !== null) {
                    $normalizedItems[] = $normalizedItem;
                }
            }

            if ($normalizedItems !== []) {
                $normalized[$clause] = $normalizedItems;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeFilterNode(mixed $node): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        if ($this->looksLikeFilterBody($node)) {
            return $this->normalizeFilterBody($node);
        }

        $key = $node['key'] ?? null;
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        $normalized = ['key' => $this->normalizeFilterKey($key)];

        if (isset($node['match']) && is_array($node['match']) && array_key_exists('value', $node['match'])) {
            $value = $this->normalizeFilterValue($node['match']['value']);
            if ($value !== null) {
                $normalized['match'] = ['value' => $value];

                return $normalized;
            }
        }

        if (isset($node['match_any']) && is_array($node['match_any'])) {
            $values = array_values(array_filter(array_map(
                fn (mixed $value): string|int|float|bool|null => $this->normalizeFilterValue($value),
                $node['match_any'],
            ), static fn (mixed $value): bool => $value !== null));

            if ($values !== []) {
                $normalized['match_any'] = $values;

                return $normalized;
            }
        }

        if (isset($node['range']) && is_array($node['range'])) {
            $range = array_filter($node['range'], static fn (mixed $value): bool => is_scalar($value) || $value === null);
            if ($range !== []) {
                $normalized['range'] = $range;

                return $normalized;
            }
        }

        return null;
    }

    private function looksLikeFilterBody(array $filters): bool
    {
        foreach (['must', 'should', 'must_not'] as $clause) {
            if (array_key_exists($clause, $filters)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeFilterKey(string $key): string
    {
        $trimmed = trim($key);

        if (str_starts_with($trimmed, 'metadata.')) {
            return $trimmed;
        }

        return 'metadata.'.$trimmed;
    }

    private function normalizeFilterValue(mixed $value): string|int|float|bool|null
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentAllowFilter(ApplicationDocumentScope $scope): array
    {
        $documentIds = $scope->documentIds ?? [];

        if ($documentIds === []) {
            return [
                'should' => [[
                    'key' => 'doc_id',
                    'match' => ['value' => self::NO_MATCH_DOCUMENT_ID],
                ]],
            ];
        }

        return [
            'should' => array_map(
                static fn (string $documentId): array => [
                    'key' => 'doc_id',
                    'match' => ['value' => $documentId],
                ],
                $documentIds,
            ),
        ];
    }
}
