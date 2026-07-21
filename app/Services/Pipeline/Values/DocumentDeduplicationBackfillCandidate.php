<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

final readonly class DocumentDeduplicationBackfillCandidate
{
    /**
     * @param  list<string>  $sourceHashEvidence
     * @param  array<string, string>  $outputContentHashes  Bridge document ID to converted-content SHA-256.
     */
    public function __construct(
        public string $scopeKey,
        public string $documentId,
        public string $contentHash,
        public string $sourceId,
        public string $taskId,
        public string $jobId,
        public string $completedAt,
        public array $sourceHashEvidence,
        public array $outputContentHashes,
    ) {
        if (
            trim($this->scopeKey) === ''
            || trim($this->documentId) === ''
            || trim($this->sourceId) === ''
            || trim($this->taskId) === ''
            || trim($this->jobId) === ''
        ) {
            throw new \InvalidArgumentException('A deduplication backfill candidate requires complete identity evidence.');
        }

        if (preg_match('/\A[a-f0-9]{64}\z/', $this->contentHash) !== 1) {
            throw new \InvalidArgumentException('A deduplication backfill candidate requires a valid SHA-256 hash.');
        }

        if ($this->sourceHashEvidence === []) {
            throw new \InvalidArgumentException('A deduplication backfill candidate requires server-owned source hash evidence.');
        }

        if ($this->outputContentHashes === []) {
            throw new \InvalidArgumentException('A deduplication backfill candidate requires at least one indexed output.');
        }

        foreach ($this->outputContentHashes as $bridgeDocumentId => $contentHash) {
            if (
                trim($bridgeDocumentId) === ''
                || preg_match('/\A[a-f0-9]{64}\z/', $contentHash) !== 1
            ) {
                throw new \InvalidArgumentException('A deduplication backfill candidate contains invalid output evidence.');
            }
        }
    }

    public function key(): string
    {
        return $this->scopeKey."\0".$this->documentId;
    }

    public function outputCount(): int
    {
        return count($this->outputContentHashes);
    }
}
