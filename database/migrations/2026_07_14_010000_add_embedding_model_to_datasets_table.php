<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('datasets') || Schema::hasColumn('datasets', 'embedding_model')) {
            return;
        }

        Schema::table('datasets', function (Blueprint $table): void {
            $table->string('embedding_model', 160)
                ->default('hawki-ollama-embedding')
                ->after('neo4j_namespace');
        });

        DB::table('datasets')
            ->whereNull('embedding_model')
            ->orWhere('embedding_model', '')
            ->update(['embedding_model' => 'hawki-ollama-embedding']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('datasets') || ! Schema::hasColumn('datasets', 'embedding_model')) {
            return;
        }

        Schema::table('datasets', function (Blueprint $table): void {
            $table->dropColumn('embedding_model');
        });
    }
};
