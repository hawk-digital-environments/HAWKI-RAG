<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Commands;

readonly class PipelineDemoCommandInput
{
    /**
     * @param list<string> $urls
     */
    public function __construct(
        public string $heapId,
        public int $limit,
        public bool $graph,
        public bool $dryRun,
        public bool $force,
        public array $urls,
    ) {
    }
}
