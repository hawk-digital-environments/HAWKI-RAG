<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_events', function (Blueprint $table): void {
            $table->id();
            $table->string('task_id');
            $table->string('job_id');
            $table->string('event_type');
            $table->string('source')->nullable();
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['task_id', 'created_at']);
            $table->index(['task_id', 'event_type']);
            $table->index(['task_id', 'job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_events');
    }
};
