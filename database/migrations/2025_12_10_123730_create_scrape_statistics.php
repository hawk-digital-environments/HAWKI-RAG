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
        Schema::create('scrape_statistics', function (Blueprint $table) {
            $table->id();
            $table->string('job_id');
            $table->foreign('job_id')
                ->references('job_id')
                ->on('scrape_jobs')
                ->onDelete('cascade');

            $table->integer('sessions')->default(0);
            $table->integer('requests')->default(0);

            $table->integer('total_urls')->default(0);
            $table->integer('target_urls')->default(0);
            $table->integer('completed_urls')->default(0);
            $table->integer('failed_urls')->default(0);
            $table->string('current_url')->nullable();


            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();

            $table->integer('pdfs_downloaded')->default(0);
            $table->integer('images_downloaded')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps(); // this adds created_at and updated_at
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scrape_statistics');
    }
};
