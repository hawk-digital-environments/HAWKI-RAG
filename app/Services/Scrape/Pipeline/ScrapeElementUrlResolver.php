<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeElementUrlResolver
{
    /**
     * @return array{subdomain: string, domain: string, full_domain: string}
     */
    public function parts(?string $url): array
    {
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return [
                'subdomain' => '',
                'domain' => '',
                'full_domain' => '',
            ];
        }

        $parts = explode('.', $host);
        $partCount = count($parts);

        if ($partCount >= 2) {
            $subdomain = implode('.', array_slice($parts, 0, $partCount - 2));
            $domain = implode('.', array_slice($parts, $partCount - 2));
        } else {
            $subdomain = '';
            $domain = $host;
        }

        return [
            'subdomain' => $subdomain,
            'domain' => $domain,
            'full_domain' => $host,
        ];
    }

    public function title(?string $url): string
    {
        if (! $url) {
            return 'Untitled document';
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path !== '') {
            return basename($path);
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'Untitled document';
    }
}
