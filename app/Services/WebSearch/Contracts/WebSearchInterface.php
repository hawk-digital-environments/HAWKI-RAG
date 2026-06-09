<?php

declare(strict_types=1);

namespace App\Services\WebSearch\Contracts;

use Illuminate\Contracts\JsonSchema\JsonSchema;

interface WebSearchInterface
{
    public function search(string $query, int $maxResults = 5): array;

    public function getResponseSchema(JsonSchema $schema): array;

}
