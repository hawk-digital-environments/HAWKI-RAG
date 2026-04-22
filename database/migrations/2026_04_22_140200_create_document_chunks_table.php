<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->integer('chunk_index');
            $table->mediumText('chunk_text');
            $table->integer('token_count')->nullable();
            $table->integer('page_number')->nullable();
            $table->string('section_title')->nullable();
            $table->json('metadata_json')->nullable();
            $table->string('qdrant_point_id')->nullable();
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->unique(['document_id', 'chunk_index'], 'document_chunks_doc_chunk_index_unique');
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};

