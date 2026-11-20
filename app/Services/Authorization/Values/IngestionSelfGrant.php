<?php

declare(strict_types=1);

namespace App\Services\Authorization\Values;

use App\Models\DatasetGrant;

/**
 * Result of a successful ingest self-grant: the grant and whether it
 * already existed (idempotent replay without a token).
 */
final readonly class IngestionSelfGrant
{
    public function __construct(
        public DatasetGrant $grant,
        public bool $replayed,
    ) {}
}
