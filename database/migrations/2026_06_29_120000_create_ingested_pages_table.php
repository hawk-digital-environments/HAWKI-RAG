<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ingested_pages')) {
            return;
        }

        Schema::create('ingested_pages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('collection', 191);
            $table->char('source_identity_hash', 64);
            $table->text('source_identity');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->text('source_url')->nullable();
            $table->string('doc_id', 191);
            $table->string('source_document_id', 191)->nullable();
            $table->char('content_hash', 64);
            $table->string('status', 64)->default('completed');
            $table->string('source_id', 191)->nullable();
            $table->string('task_id', 191)->nullable();
            $table->string('job_id', 191)->nullable();
            $table->string('qdrant_collection', 191)->nullable();
            $table->string('neo4j_database', 191)->nullable();
            $table->unsignedInteger('chunks_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_ingested_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['collection', 'source_identity_hash'], 'ingested_pages_collection_identity_unique');
            $table->index(['collection', 'status']);
            $table->index(['collection', 'canonical_url_hash']);
            $table->index(['collection', 'content_hash']);
            $table->index('doc_id');
            $table->index('source_id');
            $table->index('task_id');
            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingested_pages');
    }
};
