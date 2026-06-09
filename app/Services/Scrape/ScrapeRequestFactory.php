<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use App\Services\Scrape\Data\ScrapeJobRequest;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class ScrapeRequestFactory
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Filesystem $files,
    ) {}

    public function fromArray(array $request): ScrapeJobRequest
    {
        $defaults = $this->config->get('scraper.defaults', []);
        $url = $this->normalizeUrl((string) ($request['url'] ?? ''));
        $label = $this->normalizeLabel((string) ($request['label'] ?? ''), $url);

        return new ScrapeJobRequest(
            url: $url,
            label: $label,
            maxPages: (int) ($request['maxPages'] ?? $defaults['max_pages'] ?? 100),
            outputDir: $this->resolveOutputDir($request['outputDir'] ?? null, $label),
            skipImages: $this->boolValue($request['skipImages'] ?? $defaults['skip_images'] ?? false),
            imageExceptions: $this->normalizeImageExceptions($request['imageExceptions'] ?? null),
            dateSelector: $request['dateSelector'] ?? null,
            maxConcurrency: (int) ($request['maxConcurrency'] ?? $defaults['max_concurrency'] ?? 4),
            maxRpm: (int) ($request['maxRpm'] ?? $defaults['max_rpm'] ?? 60),
            requestDelay: isset($request['requestDelay']) ? (int) $request['requestDelay'] : null,
            discoveryMode: $this->boolValue($request['discoveryMode'] ?? $defaults['discovery_mode'] ?? false),
        );
    }

    private function resolveOutputDir(mixed $outputDir, string $label): string
    {
        if (is_string($outputDir) && trim($outputDir) !== '') {
            return trim($outputDir);
        }

        return rtrim((string) $this->config->get('scraper.storage_path'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .Str::slug($label, '-');
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $this->files->exists($url)) {
            return $url;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) {
            return $url;
        }

        return 'https://'.$url;
    }

    private function normalizeLabel(string $label, string $url): string
    {
        $slug = Str::slug(trim($label), '-');
        if ($slug !== '') {
            return $slug;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $fallback = is_string($host) && $host !== '' ? $host : 'pipeline-test';

        return Str::slug($fallback, '-') ?: 'pipeline-test';
    }

    private function normalizeImageExceptions(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return trim($value) !== '' ? trim($value) : null;
        }

        if (is_array($value)) {
            $selectors = array_values(array_filter(
                array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value),
                static fn (string $item): bool => $item !== ''
            ));

            return $selectors === [] ? null : implode(',', $selectors);
        }

        throw new \InvalidArgumentException('Image exceptions must be a string or an array of CSS selectors.');
    }

    private function boolValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
