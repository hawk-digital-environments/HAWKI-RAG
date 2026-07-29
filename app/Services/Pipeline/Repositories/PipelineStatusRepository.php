<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\ScrapeProcess;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineStatusRepository
{
    public function __construct(private PipelineSchemaInspector $schema)
    {
    }

    public function hasScrapeJobsTable(): bool
    {
        return $this->schema->hasTable('scrape_jobs');
    }

    public function hasScrapeStatisticsTable(): bool
    {
        return $this->schema->hasTable('scrape_statistics');
    }

    public function hasScrapedElementsTable(): bool
    {
        return $this->schema->hasTable('scraped_elements');
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
}
