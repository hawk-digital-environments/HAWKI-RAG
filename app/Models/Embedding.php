<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Embedding extends Model
{
    protected $connection = 'mysql';
    protected $table = 'embeddings';

    // Specify which fields to load by default
    protected $fillable = [
        'title',
        'content',
        'embedding',
        'meta_img_url',
        'page_url',
        'source_url',
        'source_format',
        'date',
        'tags',
        'intermediate_formatting'
    ];

    // Don't load the embedding data by default to save memory
    protected $hidden = ['embedding'];

    // Automatically converts embedding data between storage and usage formats
    protected $casts = [
        'embedding' => 'array'
    ];

    /**
     * Scope to exclude the large embedding vector field from database queries.
     *
     * This provides significant performance benefits over just using $hidden:
     * - $hidden only excludes fields from JSON/array output (still loads from DB)
     * - This scope prevents the embedding data from being queried entirely
     * - Saves database transfer time, memory usage, and query performance
     *
     * Example impact for 500 records:
     * - With this scope: ~500KB transferred from DB
     * - Without this scope: ~2MB+ transferred from DB
     *
     * Use this when you need embedding metadata but not the vector data itself
     * like the overview page.
     */
    public function scopeWithoutBinary($query)
    {
        return $query->select([
            'id',
            'title',
            'content',
            'meta_img_url',
            'page_url',
            'source_url',
            'source_format',
            'date',
            'tags',
            'intermediate_formatting'
        ]);
    }
}
