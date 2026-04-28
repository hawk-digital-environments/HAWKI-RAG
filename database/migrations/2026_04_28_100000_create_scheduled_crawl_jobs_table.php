<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_crawl_jobs', function (Blueprint $table) {
            $table->id();
            $table->text('url');
            $table->enum('period', ['per-day', 'per-week', 'per-month']);
            $table->string('job_id')->nullable();
            $table->string('collection')->nullable();
            $table->boolean('graph_enabled')->default(true);
            $table->text('crawled_root');
            $table->unsignedInteger('sitemap_pages')->default(100);
            $table->string('max_pages')->nullable();
            $table->boolean('rescrape_failed')->default(false);
            $table->boolean('skip_images')->default(true);
            $table->json('metadata_json')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamps();

            $table->index(['active', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_crawl_jobs');
    }
};
