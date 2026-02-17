<?php

namespace App\Services\WebSearchService\Interface;

interface WebSearchInterface
{
    public function search(string $query, int $maxResults = 5): mixed;

}
