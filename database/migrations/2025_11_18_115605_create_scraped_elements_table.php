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
            $table->string('title')->nullable()->index();
            $table->string('page_url', 2048); // URL of the scraped page (max 2048 chars)
            $table->string('page_url_hash', 64)->unique(); // SHA256 hash of URL for indexing
            $table->text('meta_img_url')->nullable(); // Meta/OG image URL

            // Content information
            $table->json('images')->nullable(); // Array of image paths/URLs
            $table->json('pdfs')->nullable(); // Array of PDF paths/URLs
            $table->string('date')->nullable(); // Publication/update date from page
            $table->text('path'); // File system path to scraped content

            // Raw data storage
            // @todo remove raw json.

            $table->longText('raw_json')->nullable(); // Complete JSON file content

            // Categorization fields
            $table->string('site_category')->nullable()->index(); // e.g., 'projekte_g_hawk', 'wiki_hawk'
            $table->string('domain')->nullable()->index(); // e.g., 'hawk.de'
            $table->string('subdomain')->nullable()->index(); // e.g., 'projekte.g', 'wiki'
            $table->string('full_domain')->nullable()->index(); // e.g., 'projekte.g.hawk.de'

            // @todo create proper access control tags.
            // Access control (for future user management)
            $table->enum('access_level', [
                'public',       // Accessible to everyone
                'internal',     // Accessible to authenticated users
                'restricted',   // Accessible to specific groups/roles
                'confidential'  // Highly restricted access
            ])->default('internal')->index();

            // Crawler metadata
            $table->string('crawler_label')->nullable()->index(); // Label from crawler job
            $table->string('crawler_job_id')->nullable()->index(); // Job ID that created this entry
            $table->timestamp('crawled_at')->nullable()->index(); // When this was crawled

            // Content metadata
            $table->integer('image_count')->default(0); // Count of images
            $table->integer('pdf_count')->default(0); // Count of PDFs
            $table->integer('content_length')->nullable(); // Length of text content


            // @todo do we need to keep the search_text?
            // Search and indexing
            $table->text('search_text')->nullable(); // Processed text for full-text search
            $table->fullText(['title', 'search_text']); // Full-text index

            $table->timestamps();
            $table->softDeletes(); // Soft delete support

            // Indexes for common queries
            $table->index(['site_category', 'access_level']);
            $table->index(['domain', 'subdomain']);
            $table->index(['crawler_label', 'crawled_at']);


            // @todo Add vectorization tracking columns. or create a new table for vectorization tracking
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scraped_pages');
    }
};
