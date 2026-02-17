<?php

namespace App\Services\WebSearchService;

use App\Services\WebSearchService\Implementations\BraveSearch;
use App\Services\WebSearchService\Implementations\TavilySearch;
use App\Services\WebSearchService\Interface\WebSearchInterface;
use InvalidArgumentException;

class WebSearchEngineFactory
{

    public static function make(): WebSearchInterface
    {
        return match(config('web_search.default')) {
            'brave' => new BraveSearch(),
            'tavily' => new TavilySearch(),
            default => throw new InvalidArgumentException(
                'Invalid Default WebSearch Engine'
            )
        };

    }
}
