<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ingestion_sources')) {
            Schema::create('ingestion_sources', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('source_id', 191)->unique();
                $table->text('source_url');
                $table->string('task_id', 191)->nullable()->index();
                $table->string('dataset_id', 191)->nullable()->index();
                $table->timestamp('last_scraped_at')->nullable();
                $table->string('etag', 191)->nullable();
                $table->string('last_modified', 191)->nullable();
                $table->string('content_hash', 191)->nullable()->index();
                $table->string('document_version', 191)->nullable();
                $table->string('temporal_workflow_id', 191)->nullable()->index();
                $table->string('temporal_schedule_id', 191)->nullable()->index();
                $table->string('index_status', 64)->default('pending')->index();
                $table->string('refresh_cadence', 32)->nullable();
                $table->text('raw_storage_path')->nullable();
                $table->text('markdown_storage_path')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->timestamps();

                $table->index(['dataset_id', 'index_status']);
            });
        }

        Schema::table('pipeline_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('pipeline_jobs', 'source_id')) {
                $table->string('source_id', 191)->nullable()->after('task_id')->index();
            }
            if (! Schema::hasColumn('pipeline_jobs', 'temporal_workflow_id')) {
                $table->string('temporal_workflow_id', 191)->nullable()->after('content_hash')->index();
            }
            if (! Schema::hasColumn('pipeline_jobs', 'temporal_run_id')) {
                $table->string('temporal_run_id', 191)->nullable()->after('temporal_workflow_id');
            }
            if (! Schema::hasColumn('pipeline_jobs', 'temporal_schedule_id')) {
                $table->string('temporal_schedule_id', 191)->nullable()->after('temporal_run_id')->index();
            }
            if (! Schema::hasColumn('pipeline_jobs', 'index_status')) {
                $table->string('index_status', 64)->nullable()->after('temporal_schedule_id')->index();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('pipeline_jobs')) {
            Schema::table('pipeline_jobs', function (Blueprint $table): void {
                foreach (['source_id', 'temporal_workflow_id', 'temporal_schedule_id', 'index_status'] as $column) {
                    if (Schema::hasColumn('pipeline_jobs', $column)) {
                        $table->dropIndex([$column]);
                    }
                }

                foreach (['source_id', 'temporal_workflow_id', 'temporal_run_id', 'temporal_schedule_id', 'index_status'] as $column) {
                    if (Schema::hasColumn('pipeline_jobs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('ingestion_sources');
    }
};
