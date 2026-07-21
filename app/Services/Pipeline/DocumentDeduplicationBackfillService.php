<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\DocumentDeduplicationState;
use App\Models\ManagedDocument;
use App\Services\Pipeline\Repositories\DocumentDeduplicationBackfillRepository;
use App\Services\Pipeline\Values\DocumentDeduplicationBackfillCandidate;
use App\Services\Pipeline\Values\DocumentDeduplicationBackfillReport;
use App\Services\Pipeline\Values\HistoricalManagedDocument;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class DocumentDeduplicationBackfillService
{
    public function __construct(
        private DocumentDeduplicationBackfillRepository $repository,
        private DocumentDeduplicationVectorEvidenceVerifier $vectorEvidence,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function run(
        bool $apply,
        ?string $datasetId = null,
        ?string $scopeKey = null,
        int $chunkSize = 200,
    ): DocumentDeduplicationBackfillReport {
        $datasetId = $this->optionalString($datasetId);
        $scopeKey = $this->optionalString($scopeKey);
        $checkedAt = $this->clock->now();
        $counters = [
            'examined' => 0,
            'verified' => 0,
            'would_seed' => 0,
            'seeded' => 0,
            'already_seeded' => 0,
            'conflicts' => 0,
            'deferred' => 0,
        ];
        $skipReasons = [];
        $candidateBatch = [];

        foreach ($this->repository->historicalManagedDocuments($datasetId, $scopeKey, $chunkSize) as $document) {
            $counters['examined']++;
            [$candidate, $reason] = $this->validatedCandidate($document, $scopeKey);

            if ($candidate === null) {
                $counters['deferred']++;
                $this->incrementReason($skipReasons, $reason ?? 'unverified_managed_document');
                $this->logOutcome(
                    apply: $apply,
                    documentId: $document->documentId,
                    outcome: 'deferred',
                    reason: $reason ?? 'unverified_managed_document',
                );

                continue;
            }

            $vectorFailure = $this->vectorEvidence->failureReason($candidate);
            if ($vectorFailure !== null) {
                $counters['deferred']++;
                $this->incrementReason($skipReasons, $vectorFailure);
                $this->logCandidateOutcome($candidate, $apply, 'deferred', $vectorFailure);

                continue;
            }

            $counters['verified']++;
            $candidateBatch[] = $candidate;
            if (count($candidateBatch) >= $chunkSize) {
                $this->processCandidates($candidateBatch, $apply, $checkedAt, $counters, $skipReasons);
                $candidateBatch = [];
            }
        }

        if ($candidateBatch !== []) {
            $this->processCandidates($candidateBatch, $apply, $checkedAt, $counters, $skipReasons);
        }

        $unverifiedRegistryRecords = $this->repository->unverifiedRegistryRecordCount($datasetId, $scopeKey);
        if ($unverifiedRegistryRecords > 0) {
            $skipReasons['registry_source_bytes_unavailable'] = $unverifiedRegistryRecords;
        }

        $report = new DocumentDeduplicationBackfillReport(
            applied: $apply,
            managedDocumentsExamined: $counters['examined'],
            verifiedCandidates: $counters['verified'],
            wouldSeed: $counters['would_seed'],
            seeded: $counters['seeded'],
            alreadySeeded: $counters['already_seeded'],
            conflicts: $counters['conflicts'],
            managedDocumentsDeferred: $counters['deferred'],
            unverifiedRegistryRecords: $unverifiedRegistryRecords,
            skipReasons: $skipReasons,
        );

        $this->logger->info('Document deduplication historical backfill completed.', $report->toArray());

        return $report;
    }

    /**
     * @return array{0:?DocumentDeduplicationBackfillCandidate,1:?string}
     */
    private function validatedCandidate(
        HistoricalManagedDocument $document,
        ?string $requestedScope,
    ): array {
        if ($document->deletedAt !== null) {
            return [null, 'managed_document_deleted'];
        }

        if ($document->sourceType !== 'upload') {
            return [null, 'unsupported_source_type'];
        }

        if ($document->status !== ManagedDocument::STATUS_INDEXED) {
            return [null, 'managed_document_not_indexed'];
        }

        if ($document->sourceChecksum === null) {
            return [null, 'invalid_source_checksum'];
        }

        if ($document->latestSourceId === null) {
            return [null, 'missing_latest_source_id'];
        }

        if ($document->latestTaskId === null) {
            return [null, 'missing_latest_task_id'];
        }

        if ($document->latestJobId === null) {
            return [null, 'missing_latest_job_id'];
        }

        [$sourceHashEvidence, $sourceHashFailure] = $this->serverSourceHashEvidence($document);
        if ($sourceHashFailure !== null) {
            return [null, $sourceHashFailure];
        }

        if ($document->indexedAt === null) {
            return [null, 'missing_managed_indexed_at'];
        }

        if ($document->outputs === []) {
            return [null, 'no_active_outputs'];
        }

        $scopes = [];
        $outputContentHashes = [];
        foreach ($document->outputs as $output) {
            if ($output->bridgeDocumentId === null) {
                return [null, 'missing_bridge_document_id'];
            }

            if ($output->qdrantCollection === null) {
                return [null, 'missing_output_scope'];
            }
            $scopes[] = $output->qdrantCollection;

            if ($output->status !== 'indexed' || $output->indexedAt === null) {
                return [null, 'output_not_indexed'];
            }

            if ($output->chunkCount < 1) {
                return [null, 'output_has_no_chunks'];
            }

            if ($output->contentHash === null) {
                return [null, 'invalid_output_content_hash'];
            }
            $outputContentHashes[$output->bridgeDocumentId] = $output->contentHash;

            if ($output->sourceId !== $document->latestSourceId) {
                return [null, 'output_source_mismatch'];
            }

            if ($output->taskId !== $document->latestTaskId) {
                return [null, 'output_task_mismatch'];
            }

            if ($output->jobId !== $document->latestJobId) {
                return [null, 'output_job_mismatch'];
            }
        }

        $scopes = array_values(array_unique($scopes));
        if (count($scopes) !== 1) {
            return [null, 'multiple_output_scopes'];
        }

        $scopeKey = $scopes[0];
        if ($document->datasetQdrantCollection === null) {
            return [null, 'missing_dataset_scope'];
        }

        if ($scopeKey !== $document->datasetQdrantCollection) {
            return [null, 'dataset_output_scope_mismatch'];
        }

        if ($requestedScope !== null && $scopeKey !== $requestedScope) {
            return [null, 'output_scope_mismatch'];
        }

        return [
            new DocumentDeduplicationBackfillCandidate(
                scopeKey: $scopeKey,
                documentId: $document->documentId,
                contentHash: $document->sourceChecksum,
                sourceId: $document->latestSourceId,
                taskId: $document->latestTaskId,
                jobId: $document->latestJobId,
                completedAt: $document->indexedAt,
                sourceHashEvidence: $sourceHashEvidence,
                outputContentHashes: $outputContentHashes,
            ),
            null,
        ];
    }

    /**
     * @return array{0:list<string>,1:?string}
     */
    private function serverSourceHashEvidence(HistoricalManagedDocument $document): array
    {
        $claims = [];

        if ($document->jobSourceHashClaim !== null) {
            if (
                $document->jobTaskId !== $document->latestTaskId
                || $document->jobSourceId !== $document->latestSourceId
            ) {
                return [[], 'server_source_evidence_link_mismatch'];
            }
            $claims['pipeline_jobs.content_hash'] = $document->jobSourceHashClaim;
        }

        if ($document->sourceUploadHashClaim !== null) {
            if (
                $document->sourceTaskId !== $document->latestTaskId
                || $document->sourceDatasetId !== $document->datasetId
            ) {
                return [[], 'server_source_evidence_link_mismatch'];
            }
            $claims['ingestion_sources.metadata.upload.content_hash'] = $document->sourceUploadHashClaim;
        }

        if ($document->taskUploadHashClaim !== null) {
            if ($document->taskDatasetId !== $document->datasetId) {
                return [[], 'server_source_evidence_link_mismatch'];
            }
            $claims['pipeline_tasks.metadata.upload.content_hash'] = $document->taskUploadHashClaim;
        }

        if ($claims === []) {
            return [[], 'server_source_checksum_unavailable'];
        }

        foreach ($claims as $claim) {
            $normalizedClaim = $this->normalizedHash($claim);
            if ($normalizedClaim === null || $normalizedClaim !== $document->sourceChecksum) {
                return [[], 'server_source_checksum_mismatch'];
            }
        }

        return [array_keys($claims), null];
    }

    /**
     * @param  list<DocumentDeduplicationBackfillCandidate>  $candidates
     * @param  array<string, int>  $counters
     * @param  array<string, int>  $skipReasons
     */
    private function processCandidates(
        array $candidates,
        bool $apply,
        \DateTimeImmutable $checkedAt,
        array &$counters,
        array &$skipReasons,
    ): void {
        $existingStates = $this->repository->statesFor($candidates);

        foreach ($candidates as $candidate) {
            $existingState = $existingStates[$candidate->key()] ?? null;
            if ($existingState !== null) {
                $this->recordExistingState($candidate, $existingState, $apply, $counters, $skipReasons);

                continue;
            }

            if (! $apply) {
                $counters['would_seed']++;
                $this->logCandidateOutcome($candidate, false, 'would_seed');

                continue;
            }

            if ($this->repository->seedIfAbsent($candidate, $checkedAt)) {
                $counters['seeded']++;
                $this->logCandidateOutcome($candidate, true, 'seeded');

                continue;
            }

            $racingState = $this->repository->statesFor([$candidate])[$candidate->key()] ?? null;
            if ($racingState !== null) {
                $this->recordExistingState($candidate, $racingState, true, $counters, $skipReasons);

                continue;
            }

            $counters['conflicts']++;
            $this->incrementReason($skipReasons, 'insert_race_unresolved');
            $this->logCandidateOutcome($candidate, true, 'conflict', 'insert_race_unresolved');
        }
    }

    /**
     * @param  array{status:string,completed_content_hash:?string}  $state
     * @param  array<string, int>  $counters
     * @param  array<string, int>  $skipReasons
     */
    private function recordExistingState(
        DocumentDeduplicationBackfillCandidate $candidate,
        array $state,
        bool $apply,
        array &$counters,
        array &$skipReasons,
    ): void {
        if (
            $state['status'] === DocumentDeduplicationState::STATUS_COMPLETED
            && $state['completed_content_hash'] === $candidate->contentHash
        ) {
            $counters['already_seeded']++;
            $this->logCandidateOutcome($candidate, $apply, 'already_seeded', 'matching_completed_state');

            return;
        }

        $counters['conflicts']++;
        $this->incrementReason($skipReasons, 'existing_state_preserved');
        $this->logCandidateOutcome($candidate, $apply, 'conflict', 'existing_state_preserved');
    }

    /**
     * @param  array<string, int>  $skipReasons
     */
    private function incrementReason(array &$skipReasons, string $reason): void
    {
        $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
    }

    private function logCandidateOutcome(
        DocumentDeduplicationBackfillCandidate $candidate,
        bool $apply,
        string $outcome,
        ?string $reason = null,
    ): void {
        $this->logOutcome(
            apply: $apply,
            documentId: $candidate->documentId,
            outcome: $outcome,
            reason: $reason,
            scopeKey: $candidate->scopeKey,
            contentHash: $candidate->contentHash,
        );
    }

    private function logOutcome(
        bool $apply,
        string $documentId,
        string $outcome,
        ?string $reason = null,
        ?string $scopeKey = null,
        ?string $contentHash = null,
    ): void {
        $this->logger->info('Document deduplication historical backfill outcome.', array_filter([
            'mode' => $apply ? 'apply' : 'dry-run',
            'scope_key' => $scopeKey,
            'doc_id' => $documentId,
            'content_hash' => $contentHash,
            'outcome' => $outcome,
            'reason' => $reason,
            'skip_action' => in_array($outcome, ['deferred', 'already_seeded', 'conflict'], true)
                ? 'preserve_existing_and_skip_backfill'
                : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function optionalString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizedHash(string $value): ?string
    {
        $value = strtolower(trim($value));

        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1 ? $value : null;
    }
}
