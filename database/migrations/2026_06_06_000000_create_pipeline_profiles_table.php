<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('profile_id', 191)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('start_urls')->nullable();
            $table->text('sitemap_url')->nullable();
            $table->unsignedInteger('max_pages')->default(1);
            $table->json('allowed_file_types')->nullable();
            $table->boolean('graph_enabled')->default(false);
            $table->string('qdrant_collection', 191)->nullable();
            $table->string('neo4j_namespace', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('graph_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_profiles');
    }
};
