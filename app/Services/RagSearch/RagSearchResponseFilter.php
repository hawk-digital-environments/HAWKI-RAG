<?php

declare(strict_types=1);

namespace App\Services\RagSearch;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class RagSearchResponseFilter
{
    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public function filter(array $response): array
    {
        $hits = is_array($response['hits'] ?? null) ? $response['hits'] : [];
        $kgRelations = is_array($response['kg'] ?? null) ? $response['kg'] : [];
        $rewriteTerms = $response['retrieval']['rewrite']['entity_terms'] ?? [];

        return [
            'results' => $this->hits($hits),
            'kg' => $this->kg($kgRelations),
            'rewrite_terms' => $this->entityTerms($rewriteTerms),
        ];
    }

    /**
     * @param list<array<string, mixed>> $hits
     * @return list<array<string, mixed>>
     */
    private function hits(array $hits): array
    {
        $results = [];
        foreach ($hits as $hit) {
            $payload = is_array($hit['payload'] ?? null) ? $hit['payload'] : [];
            $filteredHit = array_filter([
                'metadata' => array_filter([
                    'language' => $payload['lang'] ?? null,
                    'title' => $payload['title'] ?? null,
                    'url' => $payload['page_url'] ?? null,
                    'timestamp' => $payload['updated_at'] ?? null,
                    'tags' => ! empty($payload['tags']) && is_array($payload['tags']) ? implode(',', $payload['tags']) : null,
                    'collection' => $hit['collection'] ?? null,
                ]),
                'content' => $payload['content'] ?? null,
                'component_type' => $payload['component_type'] ?? null,
                'subject' => $payload['subject'] ?? null,
                'relation' => $payload['relation'] ?? null,
                'object' => $payload['object'] ?? null,
            ]);

            if ($filteredHit !== []) {
                $results[] = $filteredHit;
            }
        }

        return $results;
    }

    /**
     * @param list<array<string, mixed>> $relations
     * @return list<array{subject: mixed, relation: mixed, object: mixed}>
     */
    private function kg(array $relations): array
    {
        $kg = [];
        foreach ($relations as $fact) {
            $subject = $fact['subject'] ?? null;
            $relation = $fact['relation'] ?? null;
            $object = $fact['object'] ?? null;
            if ($subject && $relation && $object) {
                $kg[] = [
                    'subject' => $subject,
                    'relation' => $relation,
                    'object' => $object,
                ];
            }
        }

        return $kg;
    }

    /**
     * @return list<string>
     */
    private function entityTerms(mixed $terms): array
    {
        return array_values(array_unique(array_filter(
            is_array($terms) ? $terms : [],
            static fn (mixed $term): bool => is_string($term) && trim($term) !== '',
        )));
    }
}
