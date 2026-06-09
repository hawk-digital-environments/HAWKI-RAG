<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeTaskProfileNormalizer
{
    public function __construct(
        private ScrapeTaskValueNormalizer $values,
    ) {
    }

    public function profileEntries(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $profiles = $data['profiles'] ?? $data['data']['profiles'] ?? $data;

        return is_array($profiles) && $this->values->isList($profiles) ? $profiles : [];
    }

    public function toUi(mixed $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $id = $this->values->firstScalar([$entry['name'] ?? null]);
        if ($id === null) {
            return null;
        }

        $profile = is_array($entry['profile'] ?? null) ? $entry['profile'] : [];
        $sitemap = is_array($profile['sitemap'] ?? null) ? $profile['sitemap'] : [];
        $hostEntrypoints = [];
        foreach ($this->values->stringList($entry['match_hosts'] ?? []) as $host) {
            if (! str_starts_with($host, '*.')) {
                $hostEntrypoints[] = ['type' => 'host', 'src' => $host];
            }
        }

        $entrypoints = $hostEntrypoints;
        $sitemapBase = $this->values->firstScalar([$sitemap['base_url'] ?? null]);
        if ($sitemapBase !== null) {
            $entrypoints[] = ['type' => 'sitemap', 'src' => $sitemapBase];
        }

        return [
            'id' => $id,
            'name' => $this->values->firstScalar([$profile['name'] ?? null, $id]) ?? $id,
            'containerPath' => $this->values->firstScalar([$entry['containerPath'] ?? null]),
            'entrypoints' => $entrypoints,
            'rescrape_failed' => is_bool($profile['rescrape_failed'] ?? null) ? $profile['rescrape_failed'] : false,
            'max_concurrency' => $this->number($profile, 'max_concurrency', 1),
            'max_rpm' => $this->number($profile, 'max_rpm', 60),
            'skip_images' => is_bool($profile['skip_images'] ?? null) ? $profile['skip_images'] : false,
            'max_images_per_page' => $this->number($profile, 'max_images_per_page', 30),
            'max_pages' => $this->number($profile, 'max_pages', 100),
            'max_link_density' => $this->number($profile, 'max_link_density', 0.4),
            'discovery_mode' => is_bool($profile['discovery_mode'] ?? null) ? $profile['discovery_mode'] : false,
            'raw' => $entry,
        ];
    }

    public function entrypoints(mixed $entrypoints): array
    {
        if (! is_array($entrypoints)) {
            return [];
        }

        $normalized = [];
        foreach ($entrypoints as $entrypoint) {
            if (! is_array($entrypoint)) {
                continue;
            }

            $type = $this->values->firstScalar([$entrypoint['type'] ?? null]);
            $src = $this->values->firstScalar([$entrypoint['src'] ?? null]);
            if ($type === null || $src === null) {
                continue;
            }

            $normalized[] = ['type' => $type, 'src' => $src];
        }

        return $normalized;
    }

    public function firstEntrypoint(array $profile, string $type): ?string
    {
        foreach ($profile['entrypoints'] ?? [] as $entrypoint) {
            if (is_array($entrypoint) && ($entrypoint['type'] ?? null) === $type) {
                return $this->values->firstScalar([$entrypoint['src'] ?? null]);
            }
        }

        return null;
    }

    private function number(array $profile, string $key, int|float $default): int|float
    {
        $value = $profile[$key] ?? null;

        return is_int($value) || is_float($value) ? $value : $default;
    }
}
