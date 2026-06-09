<?php

declare(strict_types=1);

namespace App\Services\Scrape\Repositories;

use App\Models\ScrapeStatistics;

readonly class ScrapeStatisticsRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): ScrapeStatistics
    {
        return ScrapeStatistics::query()->create($attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function updateOrCreateForJob(string $jobId, array $attributes): ScrapeStatistics
    {
        return ScrapeStatistics::query()->updateOrCreate(['job_id' => $jobId], $attributes);
    }
}
