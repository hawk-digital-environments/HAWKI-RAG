<?php

namespace App\Services\Crawler\Storage;

use App\Models\ScrapedPage;

/**
 * Service for categorizing and analyzing scraped pages.
 *
 * This service handles:
 * - Domain/subdomain extraction
 * - Site categorization
 * - Access level prediction
 * - Content analysis
 */
class PageCategorizationService
{
    /**
     * Keywords that suggest public accessibility.
     *
     * @var array
     */
    private const PUBLIC_KEYWORDS = [
        'public', 'news', 'press', 'blog', 'article', 'publication',
        'announcement', 'event', 'calendar', 'about', 'contact'
    ];

    /**
     * Keywords that suggest restricted access.
     *
     * @var array
     */
    private const RESTRICTED_KEYWORDS = [
        'admin', 'private', 'confidential', 'internal', 'restricted',
        'members', 'login', 'auth', 'secure', 'password', 'dashboard',
        'management', 'control-panel', 'backend'
    ];

    /**
     * Keywords that suggest confidential content.
     *
     * @var array
     */
    private const CONFIDENTIAL_KEYWORDS = [
        'confidential', 'secret', 'classified', 'sensitive', 'proprietary',
        'financial', 'personal-data', 'salary', 'contract', 'agreement'
    ];

    /**
     * Categorize a page based on its URL and content.
     *
     * @param string $url Page URL
     * @param array $data Page data
     * @param string|null $crawlerLabel Crawler label
     * @param string|null $jobId Job ID
     * @return array Categorization data
     */
    public function categorize(
        string $url,
        array $data,
        ?string $crawlerLabel = null,
        ?string $jobId = null
    ): array {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '/';

        // Extract domain components
        $domainParts = $this->extractDomainParts($host);

        // Determine site category
        $siteCategory = $this->determineSiteCategory($host, $path, $data);

        // Predict access level
        $accessLevel = $this->predictAccessLevel($url, $path, $data, $host);

        // Extract search text from content
        $searchText = $this->extractSearchText($data);

        // Count images and PDFs
        $imageCount = is_array($data['images'] ?? null) ? count($data['images']) : 0;
        $pdfCount = is_array($data['pdfs'] ?? null) ? count($data['pdfs']) : 0;

        return [
            'site_category' => $siteCategory,
            'domain' => $domainParts['domain'],
            'subdomain' => $domainParts['subdomain'],
            'full_domain' => $host,
            'access_level' => $accessLevel,
            'crawler_label' => $crawlerLabel,
            'crawler_job_id' => $jobId,
            'crawled_at' => now(),
            'image_count' => $imageCount,
            'pdf_count' => $pdfCount,
            'content_length' => strlen($searchText),
            'search_text' => $searchText,
        ];
    }

    /**
     * Extract domain parts from a hostname.
     *
     * @param string $host Hostname
     * @return array ['domain' => string, 'subdomain' => string|null]
     */
    private function extractDomainParts(string $host): array
    {
        $parts = explode('.', $host);
        $count = count($parts);

        if ($count < 2) {
            return ['domain' => $host, 'subdomain' => null];
        }

        // Extract domain (last two parts usually, e.g., hawk.de)
        $domain = implode('.', array_slice($parts, -2));

        // Extract subdomain (everything before domain)
        $subdomain = $count > 2 ? implode('.', array_slice($parts, 0, $count - 2)) : null;

        return [
            'domain' => $domain,
            'subdomain' => $subdomain,
        ];
    }

    /**
     * Determine the site category from hostname and path.
     *
     * Examples:
     * - projekte.g.hawk.de -> projekte_g_hawk
     * - wiki.hawk.de -> wiki_hawk
     *
     * @param string $host Hostname
     * @param string $path URL path
     * @param array $data Page data
     * @return string Site category
     */
    private function determineSiteCategory(string $host, string $path, array $data): string
    {
        // Clean and format hostname for category
        $category = str_replace('.', '_', $host);

        // Remove common TLDs for cleaner category names
        $category = preg_replace('/_(com|de|org|net|edu)$/', '', $category);

        return $category;
    }

    /**
     * Predict the access level for a page.
     *
     * Uses URL patterns, path analysis, and content analysis to determine
     * the appropriate access level.
     *
     * @param string $url Full URL
     * @param string $path URL path
     * @param array $data Page data
     * @param string $host Hostname
     * @return string Access level
     */
    private function predictAccessLevel(string $url, string $path, array $data, string $host): string
    {
        $urlLower = strtolower($url);
        $pathLower = strtolower($path);

        // Extract title and ensure it's a string
        $titleRaw = $data['title'] ?? '';
        $title = strtolower(is_array($titleRaw) ? ($titleRaw[0] ?? '') : $titleRaw);

        // Check for confidential keywords first (highest priority)
        foreach (self::CONFIDENTIAL_KEYWORDS as $keyword) {
            if (
                str_contains($urlLower, $keyword) ||
                str_contains($pathLower, $keyword) ||
                str_contains($title, $keyword)
            ) {
                return ScrapedPage::ACCESS_CONFIDENTIAL;
            }
        }

        // Check for restricted keywords
        foreach (self::RESTRICTED_KEYWORDS as $keyword) {
            if (
                str_contains($urlLower, $keyword) ||
                str_contains($pathLower, $keyword) ||
                str_contains($title, $keyword)
            ) {
                return ScrapedPage::ACCESS_RESTRICTED;
            }
        }

        // Check for public keywords
        foreach (self::PUBLIC_KEYWORDS as $keyword) {
            if (
                str_contains($urlLower, $keyword) ||
                str_contains($pathLower, $keyword) ||
                str_contains($title, $keyword)
            ) {
                return ScrapedPage::ACCESS_PUBLIC;
            }
        }

        // Check specific path patterns
        if (preg_match('/\/(public|news|press|blog|articles?)/', $pathLower)) {
            return ScrapedPage::ACCESS_PUBLIC;
        }

        if (preg_match('/\/(admin|dashboard|private|restricted)/', $pathLower)) {
            return ScrapedPage::ACCESS_RESTRICTED;
        }

        // Check for authentication requirements in URL
        if (str_contains($urlLower, 'login') || str_contains($urlLower, 'auth')) {
            return ScrapedPage::ACCESS_RESTRICTED;
        }

        // Default to internal access for hawk.de domains
        if (str_contains($host, 'hawk.de')) {
            return ScrapedPage::ACCESS_INTERNAL;
        }

        // Default to internal for all other cases
        return ScrapedPage::ACCESS_INTERNAL;
    }

    /**
     * Extract searchable text from page data.
     *
     * Combines title and other text content for full-text search.
     *
     * @param array $data Page data
     * @return string Searchable text
     */
    private function extractSearchText(array $data): string
    {
        $parts = [];

        // Extract title and ensure it's a string
        $titleRaw = $data['title'] ?? null;
        $title = is_array($titleRaw) ? ($titleRaw[0] ?? '') : $titleRaw;

        if (!empty($title)) {
            $parts[] = $title;
        }

        // Note: If you have text content in your JSON, add it here
        // For example: if (!empty($data['content'])) { $parts[] = $data['content']; }

        return implode(' ', $parts);
    }

    /**
     * Get site category statistics.
     *
     * Returns counts of pages per site category.
     *
     * @return array
     */
    public function getSiteCategoryStatistics(): array
    {
        return ScrapedPage::query()
            ->selectRaw('site_category, COUNT(*) as count')
            ->groupBy('site_category')
            ->orderByDesc('count')
            ->get()
            ->pluck('count', 'site_category')
            ->toArray();
    }

    /**
     * Get access level statistics.
     *
     * Returns counts of pages per access level.
     *
     * @return array
     */
    public function getAccessLevelStatistics(): array
    {
        return ScrapedPage::query()
            ->selectRaw('access_level, COUNT(*) as count')
            ->groupBy('access_level')
            ->orderByDesc('count')
            ->get()
            ->pluck('count', 'access_level')
            ->toArray();
    }

    /**
     * Recategorize an existing page.
     *
     * Useful for updating categorization logic on existing data.
     *
     * @param ScrapedPage $page
     * @return bool
     */
    public function recategorize(ScrapedPage $page): bool
    {
        $categorization = $this->categorize(
            $page->page_url,
            $page->raw_json ?? [],
            $page->crawler_label,
            $page->crawler_job_id
        );

        return $page->update($categorization);
    }
}
