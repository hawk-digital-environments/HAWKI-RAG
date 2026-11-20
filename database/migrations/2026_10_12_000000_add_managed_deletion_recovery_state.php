<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_document_deletions', function (Blueprint $table): void {
            $table->json('replacement')->nullable();
            $table->json('attempts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('managed_document_deletions', fn (Blueprint $table) => $table->dropColumn(['replacement', 'attempts']));
    }
};
