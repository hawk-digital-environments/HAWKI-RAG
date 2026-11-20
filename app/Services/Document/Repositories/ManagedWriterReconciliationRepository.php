<?php

declare(strict_types=1);

namespace App\Services\Document\Repositories;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;

/** Stable snapshots; apply serializes with document → job → source writers. */
#[Singleton]
readonly class ManagedWriterReconciliationRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function snapshot(string $sourceId, bool $lock = false): array
    {
        $source = $this->row('ingestion_sources', 'source_id', $sourceId);
        $metadata = json_decode($source['metadata'] ?? '{}', true);
        $request = $metadata['request']['metadata'] ?? $metadata['request'] ?? [];
        $owner = $request['managed_document_id'] ?? $request['assistant_document_id'] ?? $request['document_id'] ?? null;
        $documents = $this->database->table('managed_documents')->where(function ($query) use ($sourceId, $owner): void {
            $query->where('latest_source_id', $sourceId);
            if (is_string($owner)) {
                $query->orWhere('document_id', $owner);
            }
            $query->orWhereIn('document_id', $this->database->table('managed_document_outputs')->select('document_id')->where('source_id', $sourceId));
        })->orderBy('document_id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->map(fn ($row) => (array) $row)->all();
        $jobs = $this->database->table('pipeline_jobs')->where('source_id', $sourceId)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->map(fn ($row) => (array) $row)->all();
        $source = $this->row('ingestion_sources', 'source_id', $sourceId, $lock);
        $task = $source ? $this->row('pipeline_tasks', 'task_id', $source['task_id'], $lock) : null;
        $outputs = $this->database->table('managed_document_outputs')->where(function ($query) use ($sourceId, $documents, $jobs): void {
            $query->where('source_id', $sourceId)->orWhereIn('document_id', array_column($documents, 'document_id'))
                ->orWhereIn('job_id', array_column($jobs, 'job_id'));
        })->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->map(fn ($row) => (array) $row)->all();
        $operations = $this->database->table('managed_document_deletions')->whereIn('document_id', array_column($documents, 'document_id'))
            ->orderBy('operation_id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->map(fn ($row) => (array) $row)->all();

        return compact('source', 'owner', 'documents', 'jobs', 'task', 'outputs', 'operations');
    }

    private function row(string $table, string $column, ?string $identity, bool $lock = false): ?array
    {
        $row = $this->database->table($table)->where($column, $identity)->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $row ? (array) $row : null;
    }
}
