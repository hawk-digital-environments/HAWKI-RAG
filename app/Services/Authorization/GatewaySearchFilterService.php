<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Document;
use App\Services\Authorization\Values\ApplicationDocumentScope;
use App\Services\Rag\DocumentFilterEvaluator;
use App\Services\Rag\FilterLanguageParser;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class GatewaySearchFilterService
{
    private const NO_MATCH_DOCUMENT_ID = '__rawki_no_match__';

    public function __construct(
        private ApplicationReadPolicy $policy,
        private FilterLanguageParser $filters,
        private DocumentFilterEvaluator $evaluator,
    ) {}

    /**
     * @param array<string, mixed> $clientFilters
     * @return array<string, mixed>
     */
    public function build(array $clientFilters, ApiActor $actor, ?string $requestedUserIdentifier = null): array
    {
        $scope = $this->policy->documentScope($actor, $requestedUserIdentifier);
        $expression = $this->filters->parse($clientFilters);

        if ($scope->unrestricted && $expression->isEmpty()) {
            return [];
        }

        if ($expression->isEmpty()) {
            return $this->documentAllowFilter($scope->documentIds ?? []);
        }

        return $this->documentAllowFilter($this->filteredDocumentIds($scope, $expression));
    }

    /**
     * @return list<string>
     */
    private function filteredDocumentIds(ApplicationDocumentScope $scope, \App\Services\Rag\Values\FilterExpression $expression): array
    {
        $query = Document::query()
            ->select('documents.id')
            ->distinct()
            ->join('datasets as heaps', 'heaps.dataset_id', '=', 'documents.dataset_id');

        if (! $scope->unrestricted) {
            $documentIds = $scope->documentIds ?? [];
            if ($documentIds === []) {
                return [];
            }

            $query->whereIn('documents.id', $documentIds);
        }

        $this->evaluator->apply($query, $expression);

        return $query->pluck('documents.id')
            ->filter(fn (mixed $documentId): bool => is_string($documentId) && trim($documentId) !== '')
            ->values()
            ->all();
    }

    /**
     * @param list<string> $documentIds
     * @return array<string, mixed>
     */
    private function documentAllowFilter(array $documentIds): array
    {
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
                array_values(array_unique($documentIds)),
            ),
        ];
    }
}
