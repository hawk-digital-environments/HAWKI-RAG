<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('datasets')) {
            Schema::create('datasets', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('dataset_id', 191)->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('status', 64)->default('active');
                $table->string('qdrant_collection', 191)->unique();
                $table->string('neo4j_namespace', 191)->unique();
                $table->timestamp('created_at')->useCurrent();

                $table->index('status');
            });
        }

        if (Schema::hasTable('documents') && !Schema::hasColumn('documents', 'dataset_id')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->string('dataset_id', 191)->nullable()->after('external_id')->index();
            });
        }

        if (Schema::hasTable('pipeline_tasks')) {
            DB::table('pipeline_tasks')
                ->whereNull('dataset_id')
                ->orWhere('dataset_id', '')
                ->update(['dataset_id' => 'default']);
        }

        $datasetIds = ['default'];

        if (Schema::hasTable('pipeline_tasks')) {
            foreach (DB::table('pipeline_tasks')->whereNotNull('dataset_id')->distinct()->pluck('dataset_id') as $datasetId) {
                $datasetIds[] = (string) $datasetId;
            }
        }

        if (Schema::hasTable('documents')) {
            foreach (DB::table('documents')->whereNull('dataset_id')->whereNotNull('collection')->get(['id', 'collection']) as $document) {
                DB::table('documents')
                    ->where('id', $document->id)
                    ->update(['dataset_id' => (string) $document->collection]);
            }

            foreach (DB::table('documents')->whereNotNull('dataset_id')->distinct()->pluck('dataset_id') as $datasetId) {
                $datasetIds[] = (string) $datasetId;
            }
        }

        foreach (array_values(array_unique(array_filter($datasetIds))) as $datasetId) {
            $safe = $this->safeName($datasetId);
            DB::table('datasets')->updateOrInsert(
                ['dataset_id' => $datasetId],
                [
                    'name' => Str::headline(str_replace(['_', '-'], ' ', $datasetId)),
                    'description' => null,
                    'status' => 'active',
                    'qdrant_collection' => 'hawki_' . $safe,
                    'neo4j_namespace' => 'hawki_' . $safe,
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('documents') && Schema::hasColumn('documents', 'dataset_id')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->dropIndex(['dataset_id']);
                $table->dropColumn('dataset_id');
            });
        }

        Schema::dropIfExists('datasets');
    }

    private function safeName(string $value): string
    {
        $safe = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?: 'default';
        $safe = trim($safe, '_');

        return $safe !== '' ? $safe : 'default';
    }
};
