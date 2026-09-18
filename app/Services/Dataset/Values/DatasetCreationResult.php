<?php

declare(strict_types=1);

namespace App\Services\Dataset\Values;

use App\Models\Dataset;

/**
 * Outcome of a dataset creation request: the dataset, whether it was
 * freshly created or an already-existing compatible one, and — for fresh
 * creations only — the one-time ingest-grant bootstrap token issued to
 * the creator.
 */
final readonly class DatasetCreationResult
{
    public function __construct(
        public Dataset $dataset,
        public bool $created,
        public string|null $grantToken,
    ) {}
}
