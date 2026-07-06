<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heap_grants', function (Blueprint $table): void {
            $table->id();
            $table->string('heap_id', 191);
            $table->string('group_id', 191);
            $table->timestamps();

            $table->unique(['heap_id', 'group_id']);
            $table->index('group_id');
        });

        Schema::create('document_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('document_id');
            $table->string('group_id', 191);
            $table->timestamps();

            $table->unique(['document_id', 'group_id']);
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_grants');
        Schema::dropIfExists('heap_grants');
    }
};
