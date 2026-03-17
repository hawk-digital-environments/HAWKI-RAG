<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_jobs', function (Blueprint $table) {
            $table->id();

            $table->string('url');
            $table->string('label')->nullable();

            // Job ID returned from scraper microservice
            $table->string('job_id')->unique();

            // pending | running | completed | failed
            $table->string('stage')->default('pending');

            // JSON config with crawl settings
            $table->json('request');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_jobs');
    }
};
