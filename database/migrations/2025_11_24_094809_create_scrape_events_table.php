<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('scrape_job_id')
                ->constrained('scrape_jobs')
                ->onDelete('cascade');

            $table->string('event');   // e.g., "url_scraped", "url_completed", "job_completed"
            $table->json('data');      // payload from scraper

            $table->timestamps();      // created_at is your event timestamp
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_events');
    }
};
