<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_processing_state', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('job_id', 191);
            $table->string('stage', 64);
            $table->string('source', 128);
            $table->text('input_path')->nullable();
            $table->text('output_path')->nullable();
            $table->string('input_checksum')->nullable();
            $table->string('status', 64);
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->timestamp('first_received_at')->nullable();
            $table->timestamp('last_received_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->string('trace_id')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'stage'], 'job_processing_state_job_stage_unique');
            $table->index(['stage', 'status']);
            $table->index('input_checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_processing_state');
    }
};
