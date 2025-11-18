<?php

namespace App\Services\Crawler\Validation;

use Illuminate\Support\Str;

/**
 * Service for filtering and managing forbidden hosts.
 *
 * This service handles all logic related to host filtering, including
 * loading forbidden host patterns from configuration files, checking
 * URLs against patterns, and filtering URL lists. It separates the
 * host filtering concern from the command layer.
 */
class HostFilterService
{
    /**
     * Hardcoded hosts that should always be skipped.
     *
     * @var array
     */
    private const SKIP_HOSTS = [
        'publikationsserver.hawk.de',
    ];

    /**
     * Loaded forbidden host patterns from configuration file.
     *
     * @var array
     */
    private array $forbiddenHostPatterns = [];

    /**
     * Whether patterns have been loaded from the configuration file.
     *
     * @var bool
     */
    private bool $patternsLoaded = false;

    /**
     * Load forbidden host patterns from the configuration file.
     *
     * Reads the forbidden-hosts.txt file from storage and parses it.
     * Each line can contain a host pattern (supports wildcards).
     * Lines starting with # are treated as comments and ignored.
     * This method is idempotent and only loads patterns once.
     *
     * @return void
     */
    public function loadForbiddenHosts(): void
    {
        if ($this->patternsLoaded) {
            return;
        }

        $path = base_path('storage/forbidden-hosts.txt');

        // Return empty if file doesn't exist
        if (!file_exists($path)) {
            $this->forbiddenHostPatterns = [];
            $this->patternsLoaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Parse file: trim lines, filter out comments and empty lines, convert to lowercase
        $this->forbiddenHostPatterns = collect($lines)
            ->map(fn($line) => trim($line))
            ->filter(fn($line) => $line !== '' && !str_starts_with($line, '#'))
            ->map(fn($line) => Str::lower($line))
            ->values()
            ->all();

        $this->patternsLoaded = true;
    }

    /**
     * Check if a URL's host is in the forbidden hosts list.
     *
     * Checks against both the hardcoded SKIP_HOSTS constant and patterns
     * loaded from the forbidden-hosts.txt file. Supports wildcard patterns
     * using Laravel's Str::is() method. Automatically loads patterns if
     * not already loaded.
     *
     * @param string $url URL to check
     * @return bool True if the host is forbidden, false otherwise
     */
    public function isHostForbidden(string $url): bool
    {
        // Ensure patterns are loaded
        if (!$this->patternsLoaded) {
            $this->loadForbiddenHosts();
        }

        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        // If unable to extract host, allow it
        if (!$host) {
            return false;
        }

        // Check against hardcoded skip list
        if (in_array($host, self::SKIP_HOSTS, true)) {
            return true;
        }

        // Check against loaded patterns (supports wildcards)
        foreach ($this->forbiddenHostPatterns as $pattern) {
            if (Str::is($pattern, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter out URLs that have forbidden hosts.
     *
     * Used when processing local sitemap files to remove URLs from
     * blacklisted domains before crawling begins. Returns a new array
     * with forbidden URLs removed.
     *
     * @param array $urls Array of URLs to filter
     * @return array Filtered array with forbidden URLs removed
     */
    public function filterForbiddenHosts(array $urls): array
    {
        return collect($urls)
            ->reject(fn($url) => $this->isHostForbidden($url))
            ->values()
            ->toArray();
    }

    /**
     * Get statistics about filtered URLs.
     *
     * Returns information about how many URLs were filtered and which
     * hosts were blocked. Useful for reporting and debugging.
     *
     * @param array $originalUrls Original URL list
     * @param array $filteredUrls Filtered URL list
     * @return array Statistics array with counts and details
     */
    public function getFilterStatistics(array $originalUrls, array $filteredUrls): array
    {
        $filteredCount = count($originalUrls) - count($filteredUrls);
        $filteredUrls = array_diff($originalUrls, $filteredUrls);

        // Extract hosts that were filtered
        $blockedHosts = collect($filteredUrls)
            ->map(fn($url) => parse_url($url, PHP_URL_HOST))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        return [
            'totalOriginal' => count($originalUrls),
            'totalFiltered' => count($filteredUrls),
            'filteredCount' => $filteredCount,
            'blockedHosts' => $blockedHosts,
        ];
    }

    /**
     * Get all loaded forbidden host patterns.
     *
     * Returns both hardcoded and file-based patterns.
     * Automatically loads patterns if not already loaded.
     *
     * @return array Array of forbidden host patterns
     */
    public function getForbiddenPatterns(): array
    {
        if (!$this->patternsLoaded) {
            $this->loadForbiddenHosts();
        }

        return array_merge(self::SKIP_HOSTS, $this->forbiddenHostPatterns);
    }

    /**
     * Check if the configuration file exists.
     *
     * @return bool
     */
    public function hasConfigurationFile(): bool
    {
        return file_exists(base_path('storage/forbidden-hosts.txt'));
    }

    /**
     * Get the count of loaded patterns.
     *
     * @return int
     */
    public function getPatternCount(): int
    {
        if (!$this->patternsLoaded) {
            $this->loadForbiddenHosts();
        }

        return count(self::SKIP_HOSTS) + count($this->forbiddenHostPatterns);
    }
}
