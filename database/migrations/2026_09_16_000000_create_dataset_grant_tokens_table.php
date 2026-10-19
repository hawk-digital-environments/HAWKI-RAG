<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dataset_grant_tokens')) {
            return;
        }

        Schema::create('dataset_grant_tokens', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('dataset_id', 191);
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->foreign('dataset_id')
                ->references('dataset_id')
                ->on('datasets')
                ->cascadeOnDelete();
            $table->unique('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_grant_tokens');
    }
};
