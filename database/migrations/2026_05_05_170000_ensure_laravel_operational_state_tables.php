<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasTable('job_processing_state') && in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE job_processing_state MODIFY job_id VARCHAR(191) NOT NULL');
        }
    }

    public function down(): void
    {
        // Keep this migration non-destructive for databases that already ran it.
    }
};
