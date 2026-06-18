<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scrape;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeRequestRules
{
    /**
     * @return array<string, mixed>
     */
    public function scrape(): array
    {
        return [
            'url' => 'required|string|max:2048',
            'label' => 'nullable|string|max:80|regex:/\A[A-Za-z0-9_-]+\z/',
            'maxPages' => 'nullable|integer|min:0|max:10000',
            'outputDir' => 'string|nullable|max:1024',
            'skipImages' => 'nullable|boolean',
            'imageExceptions' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) && ! is_array($value)) {
                        $fail('The '.$attribute.' field must be a string or an array of CSS selectors.');
                    }
                },
            ],
            'dateSelector' => 'string|nullable|max:500',
            'maxConcurrency' => 'nullable|integer|min:1|max:20',
            'maxRpm' => 'nullable|integer|min:1|max:1000',
            'requestDelay' => 'nullable|integer|min:0|max:60000',
            'discoveryMode' => 'nullable|boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function jobId(): array
    {
        return ['jobId' => 'required|string|max:191|regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/'];
    }

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

    /**
     * @return array<string, string>
     */
    public function scrapeResult(): array
    {
        return [
            'jobId' => 'required|string|max:191|regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/',
            'elementId' => 'required|integer|min:1',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function url(): array
    {
        return ['url' => 'required|string|max:2048'];
    }
}
