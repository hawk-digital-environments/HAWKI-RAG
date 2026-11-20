<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_ingestion_artifacts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('pipeline_job_id')->constrained('pipeline_jobs')->cascadeOnDelete();
            $table->foreignId('pipeline_worker_event_id')->unique()->constrained('pipeline_worker_events')->cascadeOnDelete();
            $table->string('job_id', 191);
            $table->string('task_id', 191)->nullable();
            $table->string('source_id', 191);
            $table->string('dataset_id', 191);
            $table->string('workflow_id', 255);
            $table->string('run_id', 255);
            $table->jsonb('summary');
            $table->jsonb('graph_preview')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['dataset_id', 'occurred_at'], 'rag_ingestion_artifacts_dataset_time_index');
            $table->index(['job_id', 'occurred_at'], 'rag_ingestion_artifacts_job_time_index');
        });

        Schema::create('rag_graph_failures', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('rag_ingestion_artifact_id')->constrained('rag_ingestion_artifacts')->cascadeOnDelete();
            $table->string('job_id', 191);
            $table->string('source_id', 191);
            $table->string('dataset_id', 191);
            $table->string('document_id', 191)->nullable();
            $table->string('error_code', 120);
            $table->text('message');
            $table->jsonb('context');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['dataset_id', 'occurred_at'], 'rag_graph_failures_dataset_time_index');
            $table->index(['job_id', 'occurred_at'], 'rag_graph_failures_job_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_graph_failures');
        Schema::dropIfExists('rag_ingestion_artifacts');
    }
};
