<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dataset_grants')) {
            return;
        }

        Schema::create('dataset_grants', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('dataset_id', 191);
            $table->string('principal_type', 64);
            $table->string('principal_id', 191);
            $table->string('permission', 64);
            $table->timestamps();

            $table->foreign('dataset_id')
                ->references('dataset_id')
                ->on('datasets')
                ->cascadeOnDelete();
            $table->unique(
                ['dataset_id', 'principal_type', 'principal_id', 'permission'],
                'dataset_grants_scope_unique',
            );
            $table->index(
                ['principal_type', 'principal_id', 'permission'],
                'dataset_grants_principal_permission_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_grants');
    }
};
