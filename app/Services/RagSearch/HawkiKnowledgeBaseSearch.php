<?php

declare(strict_types=1);

namespace App\Services\RagSearch;

use App\Models\User;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class HawkiKnowledgeBaseSearch
{
    /** @var array<string, string> Dataset ID => required Qdrant collection. */
    public const DATASETS = [
        'FULL_EMB_HAWK' => 'FULL_EMB_HAWK',
        'FULL_PROJ_HAWK' => 'FULL_PROJ_HAWK',
    ];

    public function __construct(private RagSearcher $searcher) {}

    /**
     * @return array{results: list<array<string, mixed>>, kg: array, rewrite_terms: list<string>, collections: list<string>}
     */
    public function search(User $user, string $query, int $topK = 5): array
    {
        $searches = [];
        foreach (self::DATASETS as $datasetId => $collection) {
            // Authorize both datasets and pin their physical collections before
            // sending any request, including when a dataset mapping has changed.
            $searches[$collection] = $this->searcher
                ->withQuery($query)
                ->withTopK($topK)
                ->forDataset($user, $datasetId, requiredCollection: $collection);
        }

        $results = [];
        $terms = [];
        foreach ($searches as $collection => $search) {
            $response = $search->execute();
            foreach (array_slice($response['results'], 0, $topK) as $result) {
                $result['metadata']['collection'] = $collection;
                $results[] = $result;
            }
            $terms = array_merge($terms, $response['rewrite_terms']);
        }

        // Results retain each collection's ranking; scores from separate
        // searches are not treated as one globally ranked result set.
        return [
            'results' => $results,
            'kg' => [],
            'rewrite_terms' => array_values(array_unique($terms)),
            'collections' => array_values(self::DATASETS),
        ];
    }
}
