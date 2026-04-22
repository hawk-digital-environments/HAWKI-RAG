<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // TODO(P3/auth): bind tenant/application/heap foreign keys once canonical table names are finalized.
            $table->uuid('tenant_id')->nullable();
            $table->uuid('application_id')->nullable();
            $table->uuid('heap_id')->nullable();

            $table->string('external_id')->nullable();
            $table->enum('source_type', ['upload', 'scrape', 'api', 'manual']);
            $table->text('source_url')->nullable();
            $table->string('original_filename')->nullable();
            $table->text('storage_path');
            $table->string('mime_type')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->char('checksum_sha256', 64);
            $table->string('language')->nullable();
            $table->string('title')->nullable();
            $table->string('author')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->enum('status', ['created', 'queued', 'processing', 'completed', 'failed', 'archived'])->default('created');
            $table->timestamps();

            $table->index('checksum_sha256');
            $table->index(['heap_id', 'status']);

            // Nullable heap_id provides "unique when present" semantics on MySQL/MariaDB/PostgreSQL.
            // TODO(P3): replace with a true partial unique index if DB strategy changes.
            $table->unique(['heap_id', 'checksum_sha256'], 'documents_heap_checksum_unique');

            if (Schema::hasTable('tenants')) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            }

            if (Schema::hasTable('applications')) {
                $table->foreign('application_id')->references('id')->on('applications')->nullOnDelete();
            }

            if (Schema::hasTable('heaps')) {
                $table->foreign('heap_id')->references('id')->on('heaps')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};

