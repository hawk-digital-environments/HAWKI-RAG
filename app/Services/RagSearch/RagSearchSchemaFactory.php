<?php

declare(strict_types=1);

namespace App\Services\RagSearch;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\JsonSchema\JsonSchema;

#[Singleton]
readonly class RagSearchSchemaFactory
{
    /**
     * @return array<string, mixed>
     */
    public function make(JsonSchema $schema): array
    {
        $relationFields = [
            'subject' => $schema->string()->description('Subject node of the relation'),
            'relation' => $schema->string()->description('Predicate/edge of the relation'),
            'object' => $schema->string()->description('Object node of the relation'),
        ];

        return [
            'results' => $schema->array()->items($schema->object([
                'metadata' => $schema->object([
                    'language' => $schema->string()->description('The two char language code of the content in this result'),
                    'title' => $schema->string()->description('The title of the search result. Normally this is the page or document title'),
                    'url' => $schema->string()->description('The url of the search result'),
                    'timestamp' => $schema->string()->description('The timestamp of the search result'),
                    'tags' => $schema->string()->description('A comma separated list of tags to quantify the search result'),
                    'collection' => $schema->string()->description('The name of the knowledge pool where the content was extracted from'),
                ])->description('Additional metadata about the result'),
                'content' => $schema->string()->description('The content of the search result. This is a string in markdown syntax'),
                'component_type' => $schema->string()->description('Chunk type. "relation" denotes a graph triple; otherwise a content chunk.'),
                ...$relationFields,
            ]))->description('The list of all search results found for your query.'),
            'kg' => $schema->array()->items($schema->object($relationFields))->description('Knowledge-graph relations returned by the backend'),
            'rewrite_terms' => $schema->array()->items(
                $schema->string()->description('Entity term produced by backend query rewrite'),
            )->description('Entity terms extracted by the backend during query rewrite'),
        ];
    }
}
