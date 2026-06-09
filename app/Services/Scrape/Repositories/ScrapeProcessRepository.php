<?php

declare(strict_types=1);

namespace App\Services\Scrape\Repositories;

use App\Models\ScrapeProcess;

readonly class ScrapeProcessRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): ScrapeProcess
    {
        return ScrapeProcess::query()->create($attributes);
    }

    public function findByJobId(string $jobId): ?ScrapeProcess
    {
        return ScrapeProcess::query()->where('job_id', $jobId)->first();
    }

    public function findByJobIdOrFail(string $jobId): ScrapeProcess
    {
        return ScrapeProcess::query()->where('job_id', $jobId)->firstOrFail();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allAsArray(): array
    {
        return ScrapeProcess::query()->get()->toArray();
    }

    public function updateStage(string $jobId, string $stage): int
    {
        return ScrapeProcess::query()->where('job_id', $jobId)->update(['stage' => $stage]);
    }

    public function deleteWithRelations(ScrapeProcess $process): void
    {
        $process->elements()->delete();
        $process->stats()->delete();
        $process->delete();
    }
}
