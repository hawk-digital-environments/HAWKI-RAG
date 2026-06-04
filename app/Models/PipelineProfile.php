<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PipelineProfile extends Model
{
    protected $fillable = [
        'profile_id',
        'name',
        'description',
        'start_urls',
        'sitemap_url',
        'max_pages',
        'allowed_file_types',
        'graph_enabled',
        'qdrant_collection',
        'neo4j_namespace',
        'metadata',
    ];

    protected $casts = [
        'start_urls' => 'array',
        'max_pages' => 'integer',
        'allowed_file_types' => 'array',
        'graph_enabled' => 'boolean',
        'metadata' => 'array',
    ];
}
