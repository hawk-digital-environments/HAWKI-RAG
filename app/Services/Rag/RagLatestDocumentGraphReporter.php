<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Services\Document\DocumentLatestGraphReportService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class RagLatestDocumentGraphReporter
{
    public function __construct(
        private DocumentLatestGraphReportService $documents,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function report(): ?array
    {
        return $this->documents->report();
    }
}
