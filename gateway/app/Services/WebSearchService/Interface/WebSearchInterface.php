<?php

namespace App\Services\WebSearchService\Interface;

use Illuminate\Contracts\JsonSchema\JsonSchema;

interface WebSearchInterface
{
    public function search(string $query, int $maxResults = 5): array;

    public function getResponseSchema(JsonSchema $schema): array;

}
