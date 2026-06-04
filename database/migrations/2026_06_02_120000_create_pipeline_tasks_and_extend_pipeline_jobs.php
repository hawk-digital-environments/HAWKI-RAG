<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pipeline_tasks')) {
            Schema::create('pipeline_tasks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('task_id', 191)->unique();
                $table->string('dataset_id', 191)->nullable();
                $table->string('status', 64)->default('pending');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('counters')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('dataset_id');
            });
        }

        Schema::table('pipeline_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('pipeline_jobs', 'task_id')) {
                $table->string('task_id', 191)->nullable()->after('job_id')->index();
            }
            if (!Schema::hasColumn('pipeline_jobs', 'parent_job_id')) {
                $table->string('parent_job_id', 191)->nullable()->after('task_id')->index();
            }
            if (!Schema::hasColumn('pipeline_jobs', 'job_type')) {
                $table->string('job_type', 64)->nullable()->after('parent_job_id')->index();
            }
            if (!Schema::hasColumn('pipeline_jobs', 'local_path')) {
                $table->text('local_path')->nullable()->after('source_url');
            }
            if (!Schema::hasColumn('pipeline_jobs', 'content_hash')) {
                $table->string('content_hash', 191)->nullable()->after('local_path')->index();
            }
            if (!Schema::hasColumn('pipeline_jobs', 'error_message')) {
                $table->text('error_message')->nullable()->after('status');
            }
            if (!Schema::hasColumn('pipeline_jobs', 'finished_at')) {
                $table->timestamp('finished_at')->nullable()->after('completed_at');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('pipeline_jobs')) {
            Schema::table('pipeline_jobs', function (Blueprint $table) {
                foreach (['task_id', 'parent_job_id', 'job_type'] as $column) {
                    if (Schema::hasColumn('pipeline_jobs', $column)) {
                        $table->dropIndex([$column]);
                    }
                }

                foreach (['task_id', 'parent_job_id', 'job_type'] as $column) {
                    if (Schema::hasColumn('pipeline_jobs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('pipeline_tasks');
    }
};
