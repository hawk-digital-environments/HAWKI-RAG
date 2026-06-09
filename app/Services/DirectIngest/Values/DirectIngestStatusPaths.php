<?php
declare(strict_types=1);

namespace App\Services\DirectIngest\Values;

readonly class DirectIngestStatusPaths
{
    public function __construct(
        public string $statusPath,
        public string $cacheLogPath,
        public string $fullLogPath,
    ) {
    }
}
