<?php

declare(strict_types=1);

namespace App\Services\Scrape\Repositories;

use App\Models\ScrapedElement;

readonly class ScrapedElementRepository
{
    /**
     * @return array<int, string>
     */
    public function pageUrlHashesForJob(string $jobId): array
    {
        return ScrapedElement::query()
            ->where('job_id', $jobId)
            ->pluck('page_url_hash')
            ->toArray();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): ScrapedElement
    {
        return ScrapedElement::query()->create($attributes);
    }
}
