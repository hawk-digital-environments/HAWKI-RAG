<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\Dataset;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Dataset\Exceptions\DatasetBackendUnavailableException;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * A read-only preflight over Laravel's frozen legacy dataset state.
 *
 * Python never reads Laravel's `public` schema. Before the BFF asks the Python
 * authority to delete a dataset, Laravel alone checks whether non-terminal
 * legacy artifacts still exist for that identity. Any artifact means the
 * legacy state has not been retired, and the BFF refuses the deletion instead
 * of letting Python delete physical targets that Laravel may still own.
 *
 * This class only executes SELECT statements. It never writes, never repairs,
 * and never returns a physical target, a token or a driver message.
 */
final readonly class LegacyDatasetStatePreflight
{
    private const ALLOWED_ARTIFACTS = [
        'dataset',
        'grants',
        'tokens',
        'managed_documents',
        'managed_document_outputs',
        'ingestion_sources',
        'pipeline_tasks',
        'pipeline_jobs',
    ];

    public function __construct(
        private DatabaseManager $database,
        private LoggerInterface $logger,
    ) {}

    /**
     * Return the legacy artifact categories that still block deletion.
     *
     * @return list<string>
     */
    public function blockingArtifacts(string $datasetId): array
    {
        try {
            $blocking = $this->inspect($datasetId);
        } catch (\Throwable $error) {
            // A preflight that cannot answer is never an allow.
            $this->logger->error('Legacy dataset state preflight failed.', [
                'dataset_id' => $datasetId,
                'exception' => $error,
            ]);

            throw DatasetBackendUnavailableException::upstreamUnavailable();
        }

        return array_values(array_intersect(self::ALLOWED_ARTIFACTS, $blocking));
    }

    /**
     * @return list<string>
     */
    private function inspect(string $datasetId): array
    {
        $connection = $this->database->connection();
        $blocking = [];

        if ($connection->table('datasets')
            ->where('dataset_id', $datasetId)
            ->whereIn('status', [Dataset::STATUS_ACTIVE, Dataset::STATUS_ARCHIVED])
            ->exists()) {
            $blocking[] = 'dataset';
        }
        if ($connection->table('dataset_grants')->where('dataset_id', $datasetId)->exists()) {
            $blocking[] = 'grants';
        }
        if ($connection->table('dataset_grant_tokens')->where('dataset_id', $datasetId)->exists()) {
            $blocking[] = 'tokens';
        }
        if ($connection->table('managed_documents')
            ->where('dataset_id', $datasetId)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'deleted')
            ->exists()) {
            $blocking[] = 'managed_documents';
        }
        if ($connection->table('managed_document_outputs')
            ->join('managed_documents', 'managed_documents.document_id', '=', 'managed_document_outputs.document_id')
            ->where('managed_documents.dataset_id', $datasetId)
            ->whereNull('managed_document_outputs.deleted_at')
            ->where(function ($query): void {
                $query->where('managed_document_outputs.active', true)
                    ->orWhereNotNull('managed_document_outputs.indexed_at');
            })
            ->exists()) {
            $blocking[] = 'managed_document_outputs';
        }
        if ($connection->table('ingestion_sources')->where('dataset_id', $datasetId)->exists()) {
            $blocking[] = 'ingestion_sources';
        }
        if ($connection->table('pipeline_tasks')
            ->where('dataset_id', $datasetId)
            ->whereNotIn('status', [PipelineTask::STATUS_COMPLETED, PipelineTask::STATUS_FAILED])
            ->exists()) {
            $blocking[] = 'pipeline_tasks';
        }
        if ($connection->table('pipeline_jobs')
            ->whereIn('task_id', $connection->table('pipeline_tasks')
                ->select('task_id')
                ->where('dataset_id', $datasetId))
            ->whereNotIn('status', [
                PipelineJob::STATUS_COMPLETED,
                PipelineJob::STATUS_FAILED,
                PipelineJob::STATUS_SKIPPED,
            ])
            ->exists()) {
            $blocking[] = 'pipeline_jobs';
        }

        return $blocking;
    }
}
