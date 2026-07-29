<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('scrape_jobs')) {
            Schema::create('scrape_jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('job_id', 191)->unique();
                $table->text('url');
                $table->string('label');
                $table->string('stage', 64)->default('initialized');
                $table->json('request')->nullable();
                $table->timestamps();

                $table->index('stage');
            });
        }

        if (!Schema::hasTable('scrape_statistics')) {
            Schema::create('scrape_statistics', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('job_id', 191)->unique();
                $table->unsignedInteger('sessions')->default(0);
                $table->unsignedInteger('requests')->default(0);
                $table->unsignedInteger('total_urls')->default(0);
                $table->unsignedInteger('target_urls')->default(0);
                $table->unsignedInteger('completed_urls')->default(0);
                $table->unsignedInteger('failed_urls')->default(0);
                $table->text('current_url')->nullable();
                $table->json('errors')->nullable();
                $table->json('warnings')->nullable();
                $table->unsignedInteger('pdfs_downloaded')->default(0);
                $table->unsignedInteger('images_downloaded')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('scraped_elements')) {
            Schema::create('scraped_elements', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('title')->nullable();
                $table->text('page_url');
                $table->text('meta_img_url')->nullable();
                $table->string('page_url_hash', 191)->nullable();
                $table->string('content_hash', 191)->nullable();
                $table->string('language', 16)->nullable();
                $table->json('images')->nullable();
                $table->json('pdfs')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->string('domain')->nullable();
                $table->string('subdomain')->nullable();
                $table->text('canonicalized_path')->nullable();
                $table->string('access_level', 64)->default('internal');
                $table->string('job_id', 191);
                $table->unsignedInteger('image_count')->default(0);
                $table->unsignedInteger('pdf_count')->default(0);
                $table->unsignedInteger('content_length')->nullable();
                $table->json('search_tags')->nullable();
                $table->timestamp('fetch_time')->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->softDeletes();
                $table->timestamps();

                $table->index('job_id');
                $table->index('page_url_hash');
                $table->index(['domain', 'subdomain']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scraped_elements');
        Schema::dropIfExists('scrape_statistics');
        Schema::dropIfExists('scrape_jobs');
    }
};
