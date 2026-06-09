<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\JobProcessingState;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Models\ScrapeProcess;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineProofRepository
{
    public function __construct(private PipelineSchemaInspector $schema)
    {
    }

    public function datasetPathForJob(string $jobId): ?string
    {
        if (! $this->schema->hasTable('pipeline_jobs')) {
            return null;
        }

        $path = PipelineJob::query()->where('job_id', $jobId)->value('dataset_path');

        return is_scalar($path) && trim((string) $path) !== '' ? trim((string) $path) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function databaseState(string $jobId, ?string $datasetPath): array
    {
        $state = [
            'pipelineJob' => null,
            'pipelineStageStates' => [],
            'jobProcessingState' => [],
            'scrapeProcess' => null,
            'tables' => [
                'pipeline_jobs' => $this->schema->hasTable('pipeline_jobs'),
                'pipeline_stage_states' => $this->schema->hasTable('pipeline_stage_states'),
                'job_processing_state' => $this->schema->hasTable('job_processing_state'),
                'scrape_jobs' => $this->schema->hasTable('scrape_jobs'),
            ],
        ];

        if ($this->schema->hasTable('pipeline_jobs')) {
            $state['pipelineJob'] = PipelineJob::query()
                ->where('job_id', $jobId)
                ->first()
                ?->toArray();
        }

        if ($this->schema->hasTable('pipeline_stage_states')) {
            $state['pipelineStageStates'] = PipelineStageState::query()
                ->where('job_id', $jobId)
                ->orderBy('id')
                ->get()
                ->map(fn (PipelineStageState $row) => $row->toArray())
                ->all();
        }

        if ($this->schema->hasTable('job_processing_state')) {
            $paths = $this->pathVariants($datasetPath);
            $state['jobProcessingState'] = JobProcessingState::query()
                ->where(function ($query) use ($jobId, $paths): void {
                    $query->where('job_id', $jobId);
                    foreach ($paths as $path) {
                        $like = addcslashes($path, '\\%_').'%';
                        $query->orWhere('input_path', 'like', $like)
                            ->orWhere('output_path', 'like', $like);
                    }
                })
                ->orderBy('updated_at')
                ->get()
                ->map(fn (JobProcessingState $row) => $row->toArray())
                ->all();
        }

        if ($this->schema->hasTable('scrape_jobs')) {
            $query = ScrapeProcess::query()->where('job_id', $jobId);
            if ($this->schema->hasTable('scrape_statistics')) {
                $query->with('stats');
            }

            $state['scrapeProcess'] = $query->first()?->toArray();
        }

        return $state;
    }

    /**
     * @return list<string>
     */
    private function pathVariants(?string $path): array
    {
        if ($path === null || trim($path) === '') {
            return [];
        }

        $paths = [trim($path)];
        $real = realpath($path);
        if (is_string($real) && $real !== $paths[0]) {
            $paths[] = $real;
        }

        return array_values(array_unique($paths));
    }
}
