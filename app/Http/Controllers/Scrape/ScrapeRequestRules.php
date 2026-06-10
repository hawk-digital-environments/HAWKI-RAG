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
            'url' => 'required|string',
            'label' => 'nullable|string',
            'maxPages' => 'nullable|integer|min:0',
            'outputDir' => 'string|nullable',
            'skipImages' => 'nullable|boolean',
            'imageExceptions' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) && ! is_array($value)) {
                        $fail('The '.$attribute.' field must be a string or an array of CSS selectors.');
                    }
                },
            ],
            'dateSelector' => 'string|nullable',
            'maxConcurrency' => 'nullable|integer|min:1',
            'maxRpm' => 'nullable|integer|min:1',
            'requestDelay' => 'nullable|integer|min:0',
            'discoveryMode' => 'nullable|boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function jobId(): array
    {
        return ['jobId' => 'required|string'];
    }

    /**
     * @return array<string, string>
     */
    public function crawlerTask(): array
    {
        return [
            'taskId' => 'required|string',
            'options' => 'nullable|array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function scrapeResult(): array
    {
        return [
            'jobId' => 'required|string',
            'elementId' => 'required|integer|min:1',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function url(): array
    {
        return ['url' => 'required|string'];
    }
}
