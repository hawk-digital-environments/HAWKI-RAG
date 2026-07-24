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
        $this->reconcilePipelineJobs();
        $this->reconcilePipelineTasks();
        $this->normalizeLegacyStatuses();
        $this->dropRedundantIndexes();
    }

    public function down(): void
    {
        // Forward-only reconciliation: removing columns or restoring obsolete
        // statuses would make data written by the current application unreadable.
    }

    private function reconcilePipelineJobs(): void
    {
        if (! Schema::hasTable('pipeline_jobs')) {
            return;
        }

        $missing = [];
        foreach ([
            'task_id',
            'source_id',
            'parent_job_id',
            'job_type',
            'local_path',
            'content_hash',
            'error_message',
            'finished_at',
            'temporal_workflow_id',
            'temporal_run_id',
            'temporal_schedule_id',
            'index_status',
        ] as $column) {
            if (! Schema::hasColumn('pipeline_jobs', $column)) {
                $missing[$column] = true;
            }
        }

        if ($missing !== []) {
            Schema::table('pipeline_jobs', function (Blueprint $table) use ($missing): void {
                if (isset($missing['task_id'])) {
                    $table->string('task_id', 191)->nullable()->after('job_id');
                }
                if (isset($missing['source_id'])) {
                    $table->string('source_id', 191)->nullable()->after('task_id');
                }
                if (isset($missing['parent_job_id'])) {
                    $table->string('parent_job_id', 191)->nullable()->after('source_id');
                }
                if (isset($missing['job_type'])) {
                    $table->string('job_type', 64)->nullable()->after('parent_job_id');
                }
                if (isset($missing['local_path'])) {
                    $table->text('local_path')->nullable()->after('source_url');
                }
                if (isset($missing['content_hash'])) {
                    $table->string('content_hash', 191)->nullable()->after('local_path');
                }
                if (isset($missing['error_message'])) {
                    $table->text('error_message')->nullable()->after('status');
                }
                if (isset($missing['finished_at'])) {
                    $table->timestamp('finished_at')->nullable()->after('completed_at');
                }
                if (isset($missing['temporal_workflow_id'])) {
                    $table->string('temporal_workflow_id', 191)->nullable()->after('content_hash');
                }
                if (isset($missing['temporal_run_id'])) {
                    $table->string('temporal_run_id', 191)->nullable()->after('temporal_workflow_id');
                }
                if (isset($missing['temporal_schedule_id'])) {
                    $table->string('temporal_schedule_id', 191)->nullable()->after('temporal_run_id');
                }
                if (isset($missing['index_status'])) {
                    $table->string('index_status', 64)->nullable()->after('temporal_schedule_id');
                }
            });
        }

        foreach ([
            ['task_id'],
            ['source_id'],
            ['parent_job_id'],
            ['job_type'],
            ['content_hash'],
            ['temporal_workflow_id'],
            ['temporal_schedule_id'],
            ['index_status'],
        ] as $columns) {
            $this->ensureIndex('pipeline_jobs', $columns);
        }
    }

    private function reconcilePipelineTasks(): void
    {
        if (! Schema::hasTable('pipeline_tasks')) {
            return;
        }

        if (Schema::hasColumn('pipeline_tasks', 'profile_id')) {
            foreach (Schema::getIndexes('pipeline_tasks') as $index) {
                if (
                    ! $index['primary']
                    && in_array('profile_id', $index['columns'], true)
                    && Schema::hasIndex('pipeline_tasks', $index['name'])
                ) {
                    Schema::table('pipeline_tasks', function (Blueprint $table) use ($index): void {
                        $table->dropIndex($index['name']);
                    });
                }
            }

            Schema::table('pipeline_tasks', function (Blueprint $table): void {
                $table->dropColumn('profile_id');
            });
        }

        if (Schema::hasColumn('pipeline_tasks', 'status')) {
            $this->ensureIndex('pipeline_tasks', ['status']);
        }
    }

    private function normalizeLegacyStatuses(): void
    {
        if (Schema::hasTable('pipeline_jobs') && Schema::hasColumn('pipeline_jobs', 'status')) {
            DB::table('pipeline_jobs')
                ->where('status', 'pending')
                ->update(['status' => 'queued']);

            DB::table('pipeline_jobs')
                ->whereIn('status', ['partial', 'cancel_requested', 'cancelled'])
                ->update(['status' => 'failed']);
        }

        if (Schema::hasTable('pipeline_tasks') && Schema::hasColumn('pipeline_tasks', 'status')) {
            DB::table('pipeline_tasks')
                ->whereIn('status', ['cancel_requested', 'cancelled', 'paused'])
                ->update(['status' => 'failed']);
        }
    }

    private function dropRedundantIndexes(): void
    {
        if (
            Schema::hasTable('pipeline_stage_states')
            && Schema::hasIndex('pipeline_stage_states', ['job_id'])
            && Schema::hasIndex('pipeline_stage_states', ['job_id', 'stage'], 'unique')
        ) {
            $this->dropIndexByColumns('pipeline_stage_states', ['job_id']);
        }

        if (
            Schema::hasTable('ingestion_sources')
            && Schema::hasIndex('ingestion_sources', ['dataset_id'])
            && Schema::hasIndex('ingestion_sources', ['dataset_id', 'index_status'])
        ) {
            $this->dropIndexByColumns('ingestion_sources', ['dataset_id']);
        }
    }

    /**
     * @param  non-empty-list<string>  $columns
     */
    private function ensureIndex(string $table, array $columns): void
    {
        if (Schema::hasIndex($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->index($columns);
        });
    }

    /**
     * @param  non-empty-list<string>  $columns
     */
    private function dropIndexByColumns(string $table, array $columns): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] !== $columns || $index['primary']) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->dropIndex($index['name']);
            });

            return;
        }
    }
};
