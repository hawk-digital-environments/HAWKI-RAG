<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('embeddings', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255)->nullable(false);
            $table->text('content')->nullable(false);
            $table->longText('embedding')->nullable(false);
            $table->string('meta_img_url', 512)->nullable(false);
            $table->string('page_url', 512)->nullable(false);
            $table->string('source_url', 512)->nullable(false);
            $table->string('source_format', 32)->nullable(false);
            $table->string('date', 64)->nullable();
            $table->text('tags')->nullable();
            $table->text('intermediate_formatting')->nullable();
            $table->timestamps();
        });

        // Enable table compression to reduce storage size for large text/vector data
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement('ALTER TABLE embeddings ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('embeddings');
    }
}; 