<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

#[Singleton]
readonly class PipelineTaskSourceResolver
{
    /**
     * @return list<string>
     */
    public function resolve(array $input): array
    {
        $urls = $this->stringList($input['urls'] ?? []);
        if ($urls === []) {
            $singleUrl = $this->nullableString($input['source_url'] ?? $input['sourceUrl'] ?? null);
            if ($singleUrl !== null) {
                $urls[] = $singleUrl;
            }
        }

        $path = $this->nullableString($input['sitemap_path'] ?? $input['sitemapPath'] ?? null);
        if ($path !== null) {
            $urls = array_merge($urls, $this->urlsFromSitemapText((string) @file_get_contents($path)));
        }

        $sitemapUrl = $this->nullableString($input['sitemap_url'] ?? $input['sitemapUrl'] ?? null);
        if ($sitemapUrl !== null && $urls === []) {
            try {
                $response = Http::timeout(30)->retry(1, 250, throw: false)->get($sitemapUrl);
                if ($response->successful()) {
                    $urls = array_merge($urls, $this->urlsFromSitemapText($response->body()));
                }
            } catch (\Throwable $exception) {
                Log::warning('Unable to load remote sitemap for pipeline task.', [
                    'sitemap_url' => $sitemapUrl,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return array_values(array_unique(array_filter(
            array_map(fn (string $url) => $this->normalizeUrl($url), $urls),
            static fn (?string $url) => $url !== null,
        )));
    }

    /**
     * @return list<string>
     */
    private function urlsFromSitemapText(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $json = json_decode($text, true);
        if (is_array($json)) {
            return $this->urlsFromJson($json);
        }

        if (preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $text, $matches) > 0) {
            return array_map('html_entity_decode', $matches[1]);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function urlsFromJson(array $value): array
    {
        $urls = [];
        array_walk_recursive($value, static function (mixed $item, mixed $key) use (&$urls): void {
            if (is_string($item) && in_array((string) $key, ['url', 'loc', 'source_url', 'sourceUrl'], true)) {
                $urls[] = $item;
            }
        });

        return $urls;
    }

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url) !== 1) {
            $url = 'https://'.ltrim($url, '/');
        }

        return $url;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item) => $this->nullableString($item), $value),
            static fn (?string $item) => $item !== null,
        ));
    }
}
