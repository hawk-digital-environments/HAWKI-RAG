<?php

declare(strict_types=1);

namespace App\Services\Graph;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class GraphCypherSearch
{
    public function __construct(private Neo4jClient $neo4j)
    {
    }

    public function fulltextIndexName(): ?string
    {
        $records = $this->neo4j->run('SHOW INDEXES YIELD name, type, entityType, labelsOrTypes, properties RETURN name, type, entityType, labelsOrTypes, properties', [], false);
        foreach ($records as $record) {
            if (($record['type'] ?? null) !== 'FULLTEXT' || ($record['entityType'] ?? null) !== 'NODE') {
                continue;
            }
            $labels = $record['labelsOrTypes'] ?? [];
            $properties = $record['properties'] ?? [];
            if (in_array('Entity', $labels, true) && (in_array('name', $properties, true) || in_array('entity_id', $properties, true))) {
                return (string) $record['name'];
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function vectorIndexes(): array
    {
        return array_values(array_filter(
            $this->neo4j->run('SHOW INDEXES YIELD name, type, entityType, labelsOrTypes, properties RETURN name, type, entityType, labelsOrTypes, properties', [], false),
            static fn (array $record): bool => ($record['type'] ?? null) === 'VECTOR' && ($record['entityType'] ?? null) === 'NODE'
        ));
    }

    public function fulltextQuery(string $query): string
    {
        $terms = preg_split('/\s+/', trim($query)) ?: [];
        $terms = array_values(array_filter(array_map(
            static fn (string $term): string => preg_replace('/[^[:alnum:]_\-]+/u', '', $term) ?? '',
            $terms
        )));

        return $terms === [] ? $query : implode(' AND ', array_map(static fn (string $term): string => $term.'*', $terms));
    }
}
