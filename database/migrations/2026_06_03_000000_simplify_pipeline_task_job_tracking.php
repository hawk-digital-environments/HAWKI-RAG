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
        if (Schema::hasTable('pipeline_jobs')) {
            Schema::table('pipeline_jobs', function (Blueprint $table): void {
                if (! Schema::hasColumn('pipeline_jobs', 'error_message')) {
                    $table->text('error_message')->nullable()->after('status');
                }
            });

            DB::table('pipeline_jobs')
                ->where('status', 'pending')
                ->update(['status' => 'queued']);

            DB::table('pipeline_jobs')
                ->whereIn('status', ['partial', 'cancel_requested', 'cancelled'])
                ->update(['status' => 'failed']);
        }

        if (Schema::hasTable('pipeline_tasks')) {
            DB::table('pipeline_tasks')
                ->whereIn('status', ['cancel_requested', 'cancelled', 'paused'])
                ->update(['status' => 'failed']);
        }
    }

    public function down(): void
    {
        // Keep simplified status values and error_message because the base
        // pipeline_jobs migration now owns that column for fresh installs.
    }
};
