<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_processing_state')) {
            DB::statement('ALTER TABLE job_processing_state MODIFY job_id VARCHAR(191) NOT NULL');
        }

        if (! Schema::hasTable('rabbitmq_queue_state')) {
            Schema::create('rabbitmq_queue_state', function (Blueprint $table) {
                $table->string('queue_name', 191)->primary();
                $table->integer('messages_ready')->default(0);
                $table->integer('messages_unacknowledged')->default(0);
                $table->integer('messages_total')->default(0);
                $table->integer('consumers')->default(0);
                $table->string('state', 64)->nullable();
                $table->timestamp('sampled_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('worker_job_tracking')) {
            Schema::create('worker_job_tracking', function (Blueprint $table) {
                $table->string('job_id', 191)->primary();
                $table->string('status')->index();
                $table->integer('retry_count')->default(0);
                $table->integer('max_retries')->default(0);
                $table->string('error_type')->nullable();
                $table->text('error_message')->nullable();
                $table->string('processing_stage')->nullable();
                $table->longText('last_payload_json')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // These tables are operational state shared by older migrations; keep down() non-destructive.
    }
};
