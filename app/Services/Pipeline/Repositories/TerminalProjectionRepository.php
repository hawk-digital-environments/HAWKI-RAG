<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\PipelineStageState;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

#[Singleton]
readonly class TerminalProjectionRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function jobExists(string $jobId): bool
    {
        return $this->database->table('pipeline_jobs')->where('job_id', $jobId)->exists();
    }

    /** @return list<int> */
    public function runningStageIds(?string $jobId): array
    {
        return $this->database->table('pipeline_stage_states')->where('status', 'running')
            ->when($jobId !== null, fn ($query) => $query->where('job_id', $jobId))
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** Locks use the document → job → source order shared by managed writers. */
    public function snapshot(int $id, bool $lock = false): array
    {
        $stage = $this->row('pipeline_stage_states', 'id', $id);
        $job = $stage ? $this->row('pipeline_jobs', 'id', $stage['pipeline_job_id']) : null;
        $source = $job && $job['source_id'] ? $this->row('ingestion_sources', 'source_id', $job['source_id']) : null;
        // Latest pointers may advance; historical output edges retain ownership.
        $metadata = json_decode($source['metadata'] ?? '{}', true);
        $request = is_array($metadata) ? ($metadata['request']['metadata'] ?? $metadata['request'] ?? []) : [];
        $owner = is_array($request) ? ($request['managed_document_id'] ?? $request['assistant_document_id'] ?? $request['document_id'] ?? null) : null;
        $outputOwners = $this->outputs($job, $source)->select('document_id');
        $documents = $this->database->table('managed_documents')
            ->where(function ($query) use ($job, $source, $owner, $outputOwners): void {
                $query->where('latest_source_id', $source['source_id'] ?? '__missing__');
                if ($job) {
                    $query->orWhere('latest_job_id', $job['job_id']);
                    if ($job['task_id']) {
                        $query->orWhere('latest_task_id', $job['task_id']);
                    }
                }
                $query->orWhereIn('document_id', $outputOwners);
                if (is_string($owner) && str_starts_with($owner, 'adoc')) {
                    $query->orWhere('document_id', $owner);
                }
            })->orderBy('document_id')->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()->map(fn ($row): array => (array) $row)->all();
        if ($lock) {
            $job = $job ? $this->row('pipeline_jobs', 'id', $job['id'], true) : null;
            $source = $job && $job['source_id'] ? $this->row('ingestion_sources', 'source_id', $job['source_id'], true) : null;
            $stage = $this->row('pipeline_stage_states', 'id', $id, true);
        }
        $task = $job && $job['task_id'] ? $this->row('pipeline_tasks', 'task_id', $job['task_id'], $lock) : null;
        $relatedJobs = $source ? $this->database->table('pipeline_jobs')->where('source_id', $source['source_id'])
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()->map(fn ($row): array => (array) $row)->all() : [];
        // Read the actual edges again after job/source locks. Include them in the
        // snapshot comparison so additions, removals and reassignment all refuse.
        $outputs = $this->outputs($job, $source)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()->map(fn ($row): array => (array) $row)->all();
        $operations = $this->database->table('managed_document_deletions')
            ->whereIn('document_id', array_column($documents, 'document_id'))
            ->orderBy('operation_id')->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()->map(fn ($row): array => (array) $row)->all();

        return compact('stage', 'job', 'source', 'task', 'documents', 'relatedJobs', 'outputs', 'operations', 'owner');
    }

    private function outputs(?array $job, ?array $source): Builder
    {
        return $this->database->table('managed_document_outputs')->where(function ($query) use ($job, $source): void {
            $query->where('source_id', $source['source_id'] ?? '__missing__');
            if ($job) {
                $query->orWhere('job_id', $job['job_id']);
                if ($job['task_id']) {
                    $query->orWhere('task_id', $job['task_id']);
                }
            }
        });
    }

    public function stage(int $id): PipelineStageState
    {
        return PipelineStageState::query()->findOrFail($id);
    }

    private function row(string $table, string $column, string|int|null $value, bool $lock = false): ?array
    {
        $row = $this->database->table($table)->where($column, $value)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $row ? (array) $row : null;
    }
}
