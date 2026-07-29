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
        if (! Schema::hasTable('datasets') || Schema::hasColumn('datasets', 'embedding_provider')) {
            return;
        }

        Schema::table('datasets', function (Blueprint $table): void {
            $table->string('embedding_provider', 80)
                ->default('ollama')
                ->after('neo4j_namespace');
        });

        // Existing HAWKI aliases were indexed through the former mandatory
        // LiteLLM boundary. Preserve that provider instead of guessing at query time.
        DB::table('datasets')
            ->where('embedding_model', 'like', 'hawki-%')
            ->update(['embedding_provider' => 'litellm']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('datasets') || ! Schema::hasColumn('datasets', 'embedding_provider')) {
            return;
        }

        Schema::table('datasets', function (Blueprint $table): void {
            $table->dropColumn('embedding_provider');
        });
    }
};
