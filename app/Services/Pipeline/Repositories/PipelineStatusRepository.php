<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\JobProcessingState;
use App\Models\ScrapeProcess;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

#[Singleton]
readonly class PipelineStatusRepository
{
    public function hasScrapeJobsTable(): bool
    {
        return Schema::hasTable('scrape_jobs');
    }

    public function hasScrapeStatisticsTable(): bool
    {
        return Schema::hasTable('scrape_statistics');
    }

    public function hasScrapedElementsTable(): bool
    {
        return Schema::hasTable('scraped_elements');
    }

    public function hasIngestStateTable(): bool
    {
        return Schema::hasTable('job_processing_state');
    }

    public function findScrapeProcess(string $jobId): ?ScrapeProcess
    {
        if (! $this->hasScrapeJobsTable()) {
            return null;
        }

        $query = ScrapeProcess::query();
        if ($this->hasScrapeStatisticsTable()) {
            $query->with(['stats']);
        }

        return $query->where('job_id', $jobId)->first();
    }

    /**
     * @return Collection<int, JobProcessingState>
     */
    public function ingestStatesForJobOrDataset(string $jobId, ?string $datasetPath): Collection
    {
        if (! $this->hasIngestStateTable()) {
            return collect();
        }

        $paths = $this->datasetPathCandidates($datasetPath);

        return JobProcessingState::query()
            ->where('stage', JobProcessingState::STAGE_RAG_INGESTION)
            ->where(function ($query) use ($jobId, $paths): void {
                $query->where('job_id', $jobId);

                foreach ($paths as $path) {
                    $like = addcslashes($path, '\\%_').'%';
                    $query->orWhere('input_path', 'like', $like)
                        ->orWhere('output_path', 'like', $like);
                }
            })
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @return list<string>
     */
    private function datasetPathCandidates(?string $datasetPath): array
    {
        $resolved = $datasetPath ? realpath($datasetPath) : false;

        return array_values(array_filter([
            is_string($datasetPath) && $datasetPath !== '' ? $datasetPath : null,
            is_string($resolved) ? $resolved : null,
        ]));
    }
}
