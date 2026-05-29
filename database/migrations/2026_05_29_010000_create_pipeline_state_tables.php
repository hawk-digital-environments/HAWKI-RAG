<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('job_id', 191)->unique();
            $table->string('status', 64)->default('pending');
            $table->string('current_stage', 64)->nullable();
            $table->text('dataset_path')->nullable();
            $table->text('source_url')->nullable();
            $table->string('label')->nullable();
            $table->unsignedInteger('total_documents')->default(0);
            $table->unsignedInteger('processed_documents')->default(0);
            $table->unsignedInteger('failed_documents')->default(0);
            $table->unsignedInteger('skipped_documents')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_stage']);
        });

        Schema::create('pipeline_stage_states', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('pipeline_job_id')->constrained('pipeline_jobs')->cascadeOnDelete();
            $table->string('job_id', 191);
            $table->string('stage', 64);
            $table->string('status', 64)->default('pending');
            $table->json('counts')->nullable();
            $table->json('metadata')->nullable();
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('max_retries')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('last_transition_at')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'stage'], 'pipeline_stage_job_stage_unique');
            $table->index(['stage', 'status']);
            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_stage_states');
        Schema::dropIfExists('pipeline_jobs');
    }
};
