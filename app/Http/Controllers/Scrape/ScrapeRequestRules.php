<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scrape;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeRequestRules
{
    /**
     * @return array<string, string>
     */
    public function crawlerTask(): array
    {
        return [
            'taskId' => 'required|string|max:191|regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/',
            'options' => 'nullable|array',
        ];
    }
}
