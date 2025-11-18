<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScrapedPage extends Model
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
        'title',
        'page_url',
        'page_url_hash',
        'meta_img_url',
        'images',
        'pdfs',
        'date',
        'path',
        'raw_json',
        'site_category',
        'domain',
        'subdomain',
        'full_domain',
        'access_level',
        'crawler_label',
        'crawler_job_id',
        'crawled_at',
        'image_count',
        'pdf_count',
        'content_length',
        'search_text',
    ];

    protected $casts = [
        'images' => 'array',
        'pdfs' => 'array',
        'raw_json' => 'array',
        'crawled_at' => 'datetime',
        'image_count' => 'integer',
        'pdf_count' => 'integer',
        'content_length' => 'integer',
    ];

    /**
     * Boot the model and set up event listeners.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Automatically generate URL hash before saving
        static::saving(function ($page) {
            if ($page->isDirty('page_url') || empty($page->page_url_hash)) {
                $page->page_url_hash = hash('sha256', $page->page_url);
            }
        });
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
     * Scope to filter by crawler label.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $label
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCrawlerLabel($query, string $label)
    {
        return $query->where('crawler_label', $label);
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
