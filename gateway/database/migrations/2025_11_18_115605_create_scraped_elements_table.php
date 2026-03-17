<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scraped_elements', function (Blueprint $table) {
            $table->id();

            // Basic page information
            $table->string('uuid')->unique();
            $table->string('title')->nullable();

            $table->string('page_url', 2048); // URL of the scraped page (max 2048 chars)
            $table->text('meta_img_url')->nullable(); // Meta/OG image URL


            $table->string('page_url_hash', 64); // SHA256 hash of URL for indexing
            $table->string('content_hash', 64); // SHA256 hash of URL for indexing

            $table->string('language', 64);

            // Content information
            $table->json('images')->nullable(); // Array of image paths/URLs
            $table->json('pdfs')->nullable(); // Array of PDF paths/URLs
            $table->timestamp('published_at')->nullable(); // Publication/update date from page

            $table->string('domain')->nullable()->index(); // e.g., 'hawk.de'
            $table->string('subdomain')->nullable()->index(); // e.g., 'projekte.g', 'wiki'
            $table->string('canonicalized_path')->nullable()->index(); // e.g., 'projekte.g.hawk.de'

            // Crawler metadata
            $table->string('job_id'); // Job ID that created this entry

            // Content metadata
            $table->integer('image_count')->default(0); // Count of images
            $table->integer('pdf_count')->default(0); // Count of PDFs

            $table->integer('content_length')->nullable(); // Length of text content


            // @todo do we need to keep the search_text?
            // Search and indexing
            $table->json('search_tags')->nullable(); // Processed text for full-text search

            $table->timestamp('fetch_time')->nullable();
            $table->string('http_status')->nullable();

            $table->timestamps();
            $table->softDeletes(); // Soft delete support

            // Indexes for common queries
            $table->index('page_url_hash');
            $table->index('content_hash');

            $table->index(['domain', 'subdomain']);

            // Access control (for future user management)
            $table->enum('access_level', [
                'public',       // Accessible to everyone
                'internal',     // Accessible to authenticated users
                'restricted',   // Accessible to specific groups/roles
                'confidential'  // Highly restricted access
            ])->default('internal')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scraped_elements');
    }
};
