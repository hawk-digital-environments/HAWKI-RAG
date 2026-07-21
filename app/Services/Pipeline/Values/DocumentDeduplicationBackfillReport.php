<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

final readonly class DocumentDeduplicationBackfillReport
{
    /**
     * @param  array<string, int>  $skipReasons
     */
    public function __construct(
        public bool $applied,
        public int $managedDocumentsExamined,
        public int $verifiedCandidates,
        public int $wouldSeed,
        public int $seeded,
        public int $alreadySeeded,
        public int $conflicts,
        public int $managedDocumentsDeferred,
        public int $unverifiedRegistryRecords,
        public array $skipReasons,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->applied ? 'apply' : 'dry-run',
            'managed_documents_examined' => $this->managedDocumentsExamined,
            'verified_candidates' => $this->verifiedCandidates,
            'would_seed' => $this->wouldSeed,
            'seeded' => $this->seeded,
            'already_seeded' => $this->alreadySeeded,
            'conflicts' => $this->conflicts,
            'managed_documents_deferred' => $this->managedDocumentsDeferred,
            'unverified_registry_records' => $this->unverifiedRegistryRecords,
            'skip_reasons' => $this->skipReasons,
        ];
    }
}
