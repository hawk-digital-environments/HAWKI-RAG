<?php

declare(strict_types=1);

namespace App\Services\Authorization\Values;

use Illuminate\Support\Carbon;

readonly class LmsDocumentRelation
{
    public function __construct(
        public string $provider,
        public string $courseId,
        public string $documentId,
        public ?Carbon $sourceUpdatedAt = null,
    ) {}
}
