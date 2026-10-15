<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_document_deletions', function (Blueprint $table): void {
            $table->string('operation_id', 80)->primary();
            $table->string('document_id', 191)->index();
            $table->string('dataset_id', 191);
            $table->string('purpose', 16);
            $table->char('request_key', 64)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('workflow_id', 191);
            $table->string('run_id', 191)->nullable();
            $table->json('workflow_input');
            $table->json('results')->nullable();
            $table->json('replacement_result')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'purpose', 'request_key'], 'managed_deletion_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_document_deletions');
    }
};
