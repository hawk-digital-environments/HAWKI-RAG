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
    public const ACCESS_PUBLIC = 'public'; // External Access
    public const ACCESS_INTERNAL = 'internal'; // Studi
    public const ACCESS_RESTRICTED = 'restricted'; // Mitarbeiter
    public const ACCESS_CONFIDENTIAL = 'confidential'; // Admin of HAWKI

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
        'canonicalized_path',

        'access_level',

        'job_id',

        'image_count',
        'pdf_count',

        'content_length',
        'search_tags',

        'fetch_time',
        'http_status'
    ];

    protected $casts = [
        'images' => 'array',
        'pdfs' => 'array',
        'fetch_time' => 'datetime',
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
        return $this->belongsTo(ScrapeProcess::class, 'job_id');
    }
}
