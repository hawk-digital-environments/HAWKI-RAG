<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\Document;
use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Singleton]
readonly class DocumentRepository
{
    public function findById(string $documentId): ?Document
    {
        return Document::query()->where('id', $documentId)->first();
    }

    public function latestCompleted(): ?Document
    {
        return Document::query()
            ->where('status', Document::STATUS_COMPLETED)
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * @return Collection<int, Document>
     */
    public function list(array $filters, int $limit): Collection
    {
        return $this->filteredQuery($filters)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function relatedJobs(Document $document, ?string $taskId, ?string $jobId, int $limit = 50): Collection
    {
        return PipelineJob::query()
            ->where(function (Builder $inner) use ($document, $taskId, $jobId): void {
                if ($taskId) {
                    $inner->orWhere('task_id', $taskId);
                }

                if ($jobId) {
                    $inner->orWhere('job_id', $jobId)
                        ->orWhere('parent_job_id', $jobId);
                }

                if ($document->checksum_sha256) {
                    $inner->orWhere('content_hash', $document->checksum_sha256);
                }

                if ($document->storage_path) {
                    $inner->orWhere('local_path', $document->storage_path);
                }
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function filteredQuery(array $filters): Builder
    {
        $query = Document::query();
        $datasetId = $this->stringValue($filters['dataset_id'] ?? $filters['datasetId'] ?? null);
        $search = $this->stringValue($filters['q'] ?? $filters['search'] ?? null);

        if ($datasetId) {
            $query->where('dataset_id', $datasetId);
        }

        if ($search) {
            $query->where(function (Builder $inner) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $inner
                    ->where('source_url', 'like', $like)
                    ->orWhere('storage_path', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('original_filename', 'like', $like)
                    ->orWhere('checksum_sha256', 'like', $like);
            });
        }

        return $query;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
