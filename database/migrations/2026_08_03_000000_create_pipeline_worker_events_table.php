<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_worker_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('pipeline_job_id')->constrained('pipeline_jobs')->cascadeOnDelete();
            $table->string('event_id', 191)->unique();
            $table->string('job_id', 191);
            $table->string('task_id', 191)->nullable();
            $table->string('source_id', 191);
            $table->string('workflow_id', 255);
            $table->string('run_id', 255);
            $table->string('activity_id', 255);
            $table->unsignedInteger('attempt');
            $table->string('event_type', 64);
            $table->string('producer', 32);
            $table->string('stage', 32);
            $table->string('phase', 120);
            $table->string('status', 32);
            $table->char('payload_hash', 64);
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'stage', 'occurred_at'], 'pipeline_worker_events_job_stage_time_index');
            $table->index(['workflow_id', 'run_id'], 'pipeline_worker_events_workflow_run_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_worker_events');
    }
};
