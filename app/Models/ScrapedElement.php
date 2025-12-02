<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScrapedElement extends Model
{
    use SoftDeletes;

    /**
     * Access level constants
     */
    public const ACCESS_PUBLIC = 'public';
    public const ACCESS_INTERNAL = 'internal';
    public const ACCESS_RESTRICTED = 'restricted';
    public const ACCESS_CONFIDENTIAL = 'confidential';

    protected $fillable = [
        'uuid',
        'title',

        'page_url',
        'meta_img_url',

        'page_url_hash',
        'content_hash',

        'language',

        'images',
        'pdfs',
        'published_at',

        'domain',
        'subdomain',

        'access_level',

        'scrape_job_id',

        'image_count',
        'pdf_count',

        'content_length',
        'search_tags',
    ];

    protected $casts = [
        'images' => 'array',
        'pdfs' => 'array',
        'scraped_at' => 'datetime',
        'image_count' => 'integer',
        'pdf_count' => 'integer',
        'content_length' => 'integer',
        'search_tags' => 'array',
    ];



    /* ----------------------------------
      | Relationships
      ---------------------------------- */

    public function process(): BelongsTo
    {
        return $this->belongsTo(ScrapeProcess::class, 'scrape_job_id');
    }

    /**
     * Get all available access levels.
     *
     * @return array
     */
    public static function getAccessLevels(): array
    {
        return [
            self::ACCESS_PUBLIC,
            self::ACCESS_INTERNAL,
            self::ACCESS_RESTRICTED,
            self::ACCESS_CONFIDENTIAL,
        ];
    }

    /**
     * Scope to filter by access level.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|array $level
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAccessLevel($query, $level)
    {
        if (is_array($level)) {
            return $query->whereIn('access_level', $level);
        }

        return $query->where('access_level', $level);
    }

    /**
     * Scope to filter by site category.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $category
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('site_category', $category);
    }

    /**
     * Scope to filter by domain.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $domain
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    /**
     * Scope to filter by subdomain.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $subdomain
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSubdomain($query, string $subdomain)
    {
        return $query->where('subdomain', $subdomain);
    }

    /**
     * Scope to filter by scraper label.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $label
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopescraperLabel($query, string $label)
    {
        return $query->where('scraper_label', $label);
    }

    /**
     * Scope to search in title and content.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $searchTerm
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch($query, string $searchTerm)
    {
        return $query->whereFullText(['title', 'search_text'], $searchTerm);
    }

    /**
     * Check if page is publicly accessible.
     *
     * @return bool
     */
    public function isPublic(): bool
    {
        return $this->access_level === self::ACCESS_PUBLIC;
    }

    /**
     * Check if page requires internal access.
     *
     * @return bool
     */
    public function isInternal(): bool
    {
        return $this->access_level === self::ACCESS_INTERNAL;
    }

    /**
     * Check if page is restricted.
     *
     * @return bool
     */
    public function isRestricted(): bool
    {
        return $this->access_level === self::ACCESS_RESTRICTED;
    }

    /**
     * Check if page is confidential.
     *
     * @return bool
     */
    public function isConfidential(): bool
    {
        return $this->access_level === self::ACCESS_CONFIDENTIAL;
    }

    /**
     * Get the site display name.
     *
     * @return string
     */
    public function getSiteDisplayName(): string
    {
        if ($this->site_category) {
            return str_replace('_', '.', $this->site_category);
        }

        return $this->full_domain ?? $this->domain ?? 'Unknown';
    }

    /**
     * Get image count.
     *
     * @return int
     */
    public function getImageCountAttribute(): int
    {
        return $this->attributes['image_count'] ?? (is_array($this->images) ? count($this->images) : 0);
    }

    /**
     * Get PDF count.
     *
     * @return int
     */
    public function getPdfCountAttribute(): int
    {
        return $this->attributes['pdf_count'] ?? (is_array($this->pdfs) ? count($this->pdfs) : 0);
    }
}
