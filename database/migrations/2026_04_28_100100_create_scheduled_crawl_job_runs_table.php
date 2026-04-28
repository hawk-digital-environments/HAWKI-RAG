<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_crawl_job_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_crawl_job_id')->constrained('scheduled_crawl_jobs')->cascadeOnDelete();
            $table->string('job_id');
            $table->text('url');
            $table->enum('period', ['per-day', 'per-week', 'per-month']);
            $table->text('crawled_root');
            $table->string('collection')->nullable();
            $table->boolean('graph_enabled')->default(true);
            $table->string('pipeline_mode');
            $table->text('scraper_command');
            $table->text('ingest_command')->nullable();
            $table->string('status')->default('pending');
            $table->integer('exit_code')->nullable();
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_crawl_job_runs');
    }
};
