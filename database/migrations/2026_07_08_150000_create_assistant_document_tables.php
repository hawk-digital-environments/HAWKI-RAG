<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_documents', function (Blueprint $table): void {
            $table->string('document_id', 191)->primary();
            $table->string('dataset_id', 191);
            $table->string('display_name')->nullable();
            $table->string('source_type', 64)->default('upload');
            $table->text('source_url')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->char('source_checksum_sha256', 64)->nullable();
            $table->boolean('graph_enabled')->default(false);
            $table->string('status', 64)->default('accepted');
            $table->text('last_error')->nullable();
            $table->string('latest_source_id', 191)->nullable();
            $table->string('latest_task_id', 191)->nullable();
            $table->string('latest_job_id', 191)->nullable();
            $table->string('latest_document_version', 191)->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['dataset_id', 'status']);
            $table->index('latest_source_id');
            $table->index('latest_task_id');
            $table->index('latest_job_id');
        });

        Schema::create('managed_document_outputs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('document_id', 191);
            $table->string('bridge_document_id', 191);
            $table->string('qdrant_collection', 191);
            $table->string('neo4j_namespace', 191)->nullable();
            $table->string('source_id', 191)->nullable();
            $table->string('task_id', 191)->nullable();
            $table->string('job_id', 191)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->string('status', 64)->default('indexed');
            $table->boolean('active')->default(true);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('document_id')
                ->references('document_id')
                ->on('managed_documents')
                ->cascadeOnDelete();

            $table->unique(['document_id', 'bridge_document_id'], 'managed_document_outputs_unique');
            $table->index(['document_id', 'active']);
            $table->index('source_id');
            $table->index('task_id');
            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_document_outputs');
        Schema::dropIfExists('managed_documents');
    }
};
