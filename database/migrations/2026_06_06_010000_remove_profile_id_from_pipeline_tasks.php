<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pipeline_tasks') || ! Schema::hasColumn('pipeline_tasks', 'profile_id')) {
            return;
        }

        Schema::table('pipeline_tasks', function (Blueprint $table): void {
            if (Schema::hasIndex('pipeline_tasks', 'pipeline_tasks_status_profile_id_index')) {
                $table->dropIndex('pipeline_tasks_status_profile_id_index');
            }
            $table->dropColumn('profile_id');
            if (! Schema::hasIndex('pipeline_tasks', 'pipeline_tasks_status_index')) {
                $table->index('status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pipeline_tasks') || Schema::hasColumn('pipeline_tasks', 'profile_id')) {
            return;
        }

        Schema::table('pipeline_tasks', function (Blueprint $table): void {
            $table->string('profile_id', 191)->nullable()->after('dataset_id');
            $table->index(['status', 'profile_id']);
        });
    }
};
