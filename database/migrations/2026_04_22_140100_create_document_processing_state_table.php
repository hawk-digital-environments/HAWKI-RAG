<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_processing_state', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('document_id');
            $table->enum('stage', ['scrape', 'convert', 'chunk', 'embed', 'graph_extract', 'index_vector', 'index_graph']);
            $table->enum('state', ['pending', 'queued', 'running', 'completed', 'failed', 'skipped'])->default('pending');
            $table->integer('attempt_count')->default(0);
            $table->string('worker_name')->nullable();
            $table->string('queue_name')->nullable();
            $table->string('last_job_id')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('error_context_json')->nullable();
            $table->json('metrics_json')->nullable();
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->unique(['document_id', 'stage'], 'document_processing_state_doc_stage_unique');
            $table->index(['stage', 'state']);
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_processing_state');
    }
};

