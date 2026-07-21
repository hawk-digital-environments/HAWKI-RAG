<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_deduplication_states', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('scope_key', 191);
            $table->string('document_id', 191);
            $table->char('completed_content_hash', 64)->nullable();
            $table->char('pending_content_hash', 64)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('decision', 32)->nullable();
            $table->string('claim_token', 191)->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->string('completed_source_id', 191)->nullable();
            $table->string('pending_source_id', 191)->nullable();
            $table->string('task_id', 191)->nullable();
            $table->string('job_id', 191)->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Hashes are versions of a logical document, not global aliases.
            // Distinct document IDs may carry different ACLs and deletion lifecycles.
            $table->unique(['scope_key', 'document_id'], 'document_dedup_scope_document_unique');
            $table->index(['scope_key', 'completed_content_hash'], 'document_dedup_scope_hash_index');
            $table->index(['status', 'checked_at'], 'document_dedup_status_checked_index');
            $table->index('lease_expires_at');
            $table->index('completed_source_id');
            $table->index('pending_source_id');
            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_deduplication_states');
    }
};
