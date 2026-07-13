<?php

use App\Services\Document\Values\ManagedDocumentId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->migrateAssistantTablesToManagedTables();
        $this->normalizeRuntimeMetadata();
    }

    public function down(): void
    {
        $this->migrateManagedTablesToAssistantTables();
        $this->denormalizeRuntimeMetadata();
    }

    private function migrateAssistantTablesToManagedTables(): void
    {
        if (! Schema::hasTable('assistant_documents')) {
            return;
        }

        $this->ensureManagedDocumentTablesExist();

        DB::table('assistant_documents')
            ->orderBy('assistant_document_id')
            ->chunk(200, function ($rows): void {
                $payload = [];

                foreach ($rows as $row) {
                    $payload[] = [
                        'document_id' => $row->assistant_document_id,
                        'dataset_id' => $row->dataset_id,
                        'display_name' => $row->display_name,
                        'source_type' => $row->source_type,
                        'source_url' => $row->source_url,
                        'source_updated_at' => $row->source_updated_at,
                        'source_checksum_sha256' => $row->source_checksum_sha256,
                        'graph_enabled' => $row->graph_enabled,
                        'status' => $row->status,
                        'last_error' => $row->last_error,
                        'latest_source_id' => $row->latest_source_id,
                        'latest_task_id' => $row->latest_task_id,
                        'latest_job_id' => $row->latest_job_id,
                        'latest_document_version' => $row->latest_document_version,
                        'indexed_at' => $row->indexed_at,
                        'deleted_at' => $row->deleted_at,
                        'metadata_json' => $row->metadata_json,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                }

                DB::table('managed_documents')->upsert(
                    $payload,
                    ['document_id'],
                    [
                        'dataset_id',
                        'display_name',
                        'source_type',
                        'source_url',
                        'source_updated_at',
                        'source_checksum_sha256',
                        'graph_enabled',
                        'status',
                        'last_error',
                        'latest_source_id',
                        'latest_task_id',
                        'latest_job_id',
                        'latest_document_version',
                        'indexed_at',
                        'deleted_at',
                        'metadata_json',
                        'created_at',
                        'updated_at',
                    ],
                );
            });

        if (Schema::hasTable('assistant_document_outputs')) {
            DB::table('assistant_document_outputs')
                ->orderBy('id')
                ->chunk(200, function ($rows): void {
                    $payload = [];

                    foreach ($rows as $row) {
                        $payload[] = [
                            'id' => $row->id,
                            'document_id' => $row->assistant_document_id,
                            'bridge_document_id' => $row->bridge_document_id,
                            'qdrant_collection' => $row->qdrant_collection,
                            'neo4j_namespace' => $row->neo4j_namespace,
                            'source_id' => $row->source_id,
                            'task_id' => $row->task_id,
                            'job_id' => $row->job_id,
                            'content_hash' => $row->content_hash,
                            'chunk_count' => $row->chunk_count,
                            'status' => $row->status,
                            'active' => $row->active,
                            'indexed_at' => $row->indexed_at,
                            'deleted_at' => $row->deleted_at,
                            'metadata_json' => $row->metadata_json,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ];
                    }

                    DB::table('managed_document_outputs')->upsert(
                        $payload,
                        ['id'],
                        [
                            'document_id',
                            'bridge_document_id',
                            'qdrant_collection',
                            'neo4j_namespace',
                            'source_id',
                            'task_id',
                            'job_id',
                            'content_hash',
                            'chunk_count',
                            'status',
                            'active',
                            'indexed_at',
                            'deleted_at',
                            'metadata_json',
                            'created_at',
                            'updated_at',
                        ],
                    );
                });
        }

        Schema::dropIfExists('assistant_document_outputs');
        Schema::dropIfExists('assistant_documents');
    }

    private function migrateManagedTablesToAssistantTables(): void
    {
        if (! Schema::hasTable('managed_documents')) {
            return;
        }

        $this->ensureAssistantDocumentTablesExist();

        DB::table('managed_documents')
            ->orderBy('document_id')
            ->chunk(200, function ($rows): void {
                $payload = [];

                foreach ($rows as $row) {
                    $payload[] = [
                        'assistant_document_id' => $row->document_id,
                        'dataset_id' => $row->dataset_id,
                        'display_name' => $row->display_name,
                        'source_type' => $row->source_type,
                        'source_url' => $row->source_url,
                        'source_updated_at' => $row->source_updated_at,
                        'source_checksum_sha256' => $row->source_checksum_sha256,
                        'graph_enabled' => $row->graph_enabled,
                        'status' => $row->status,
                        'last_error' => $row->last_error,
                        'latest_source_id' => $row->latest_source_id,
                        'latest_task_id' => $row->latest_task_id,
                        'latest_job_id' => $row->latest_job_id,
                        'latest_document_version' => $row->latest_document_version,
                        'indexed_at' => $row->indexed_at,
                        'deleted_at' => $row->deleted_at,
                        'metadata_json' => $row->metadata_json,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                }

                DB::table('assistant_documents')->upsert(
                    $payload,
                    ['assistant_document_id'],
                    [
                        'dataset_id',
                        'display_name',
                        'source_type',
                        'source_url',
                        'source_updated_at',
                        'source_checksum_sha256',
                        'graph_enabled',
                        'status',
                        'last_error',
                        'latest_source_id',
                        'latest_task_id',
                        'latest_job_id',
                        'latest_document_version',
                        'indexed_at',
                        'deleted_at',
                        'metadata_json',
                        'created_at',
                        'updated_at',
                    ],
                );
            });

        if (Schema::hasTable('managed_document_outputs')) {
            DB::table('managed_document_outputs')
                ->orderBy('id')
                ->chunk(200, function ($rows): void {
                    $payload = [];

                    foreach ($rows as $row) {
                        $payload[] = [
                            'id' => $row->id,
                            'assistant_document_id' => $row->document_id,
                            'bridge_document_id' => $row->bridge_document_id,
                            'qdrant_collection' => $row->qdrant_collection,
                            'neo4j_namespace' => $row->neo4j_namespace,
                            'source_id' => $row->source_id,
                            'task_id' => $row->task_id,
                            'job_id' => $row->job_id,
                            'content_hash' => $row->content_hash,
                            'chunk_count' => $row->chunk_count,
                            'status' => $row->status,
                            'active' => $row->active,
                            'indexed_at' => $row->indexed_at,
                            'deleted_at' => $row->deleted_at,
                            'metadata_json' => $row->metadata_json,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ];
                    }

                    DB::table('assistant_document_outputs')->upsert(
                        $payload,
                        ['id'],
                        [
                            'assistant_document_id',
                            'bridge_document_id',
                            'qdrant_collection',
                            'neo4j_namespace',
                            'source_id',
                            'task_id',
                            'job_id',
                            'content_hash',
                            'chunk_count',
                            'status',
                            'active',
                            'indexed_at',
                            'deleted_at',
                            'metadata_json',
                            'created_at',
                            'updated_at',
                        ],
                    );
                });
        }

        Schema::dropIfExists('managed_document_outputs');
        Schema::dropIfExists('managed_documents');
    }

    private function normalizeRuntimeMetadata(): void
    {
        $this->rewriteJsonColumn('pipeline_tasks', 'id', 'metadata', fn (array $metadata): array => $this->normalizeWorkflowMetadata($metadata));
        $this->rewriteJsonColumn('pipeline_jobs', 'id', 'metadata', fn (array $metadata): array => $this->normalizeWorkflowMetadata($metadata));
        $this->rewriteJsonColumn('ingestion_sources', 'id', 'metadata', fn (array $metadata): array => $this->normalizeWorkflowMetadata($metadata));
        $this->rewriteJsonColumn('documents', 'id', 'metadata_json', fn (array $metadata): array => $this->normalizeIndexedDocumentMetadata($metadata));
    }

    private function denormalizeRuntimeMetadata(): void
    {
        $this->rewriteJsonColumn('pipeline_tasks', 'id', 'metadata', fn (array $metadata): array => $this->denormalizeWorkflowMetadata($metadata));
        $this->rewriteJsonColumn('pipeline_jobs', 'id', 'metadata', fn (array $metadata): array => $this->denormalizeWorkflowMetadata($metadata));
        $this->rewriteJsonColumn('ingestion_sources', 'id', 'metadata', fn (array $metadata): array => $this->denormalizeWorkflowMetadata($metadata));
        $this->rewriteJsonColumn('documents', 'id', 'metadata_json', fn (array $metadata): array => $this->denormalizeIndexedDocumentMetadata($metadata));
    }

    private function ensureManagedDocumentTablesExist(): void
    {
        if (! Schema::hasTable('managed_documents')) {
            Schema::create('managed_documents', function (Blueprint $table): void {
                $table->string('document_id', 191)->primary();
                $table->string('dataset_id', 191);
                $table->string('display_name')->nullable();
                $table->string('source_type', 64)->default('upload');
                $table->text('source_url')->nullable();
                $table->timestamp('source_updated_at')->nullable();
                $table->char('source_checksum_sha256', 64)->nullable();
                $table->boolean('graph_enabled')->default(false);
                $table->string('status', 64)->default('accepted');
                $table->text('last_error')->nullable();
                $table->string('latest_source_id', 191)->nullable();
                $table->string('latest_task_id', 191)->nullable();
                $table->string('latest_job_id', 191)->nullable();
                $table->string('latest_document_version', 191)->nullable();
                $table->timestamp('indexed_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index(['dataset_id', 'status']);
                $table->index('latest_source_id');
                $table->index('latest_task_id');
                $table->index('latest_job_id');
            });
        }

        if (! Schema::hasTable('managed_document_outputs')) {
            Schema::create('managed_document_outputs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('document_id', 191);
                $table->string('bridge_document_id', 191);
                $table->string('qdrant_collection', 191);
                $table->string('neo4j_namespace', 191)->nullable();
                $table->string('source_id', 191)->nullable();
                $table->string('task_id', 191)->nullable();
                $table->string('job_id', 191)->nullable();
                $table->char('content_hash', 64)->nullable();
                $table->unsignedInteger('chunk_count')->default(0);
                $table->string('status', 64)->default('indexed');
                $table->boolean('active')->default(true);
                $table->timestamp('indexed_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->foreign('document_id')
                    ->references('document_id')
                    ->on('managed_documents')
                    ->cascadeOnDelete();

                $table->unique(['document_id', 'bridge_document_id'], 'managed_document_outputs_unique');
                $table->index(['document_id', 'active']);
                $table->index('source_id');
                $table->index('task_id');
                $table->index('job_id');
            });
        }
    }

    private function ensureAssistantDocumentTablesExist(): void
    {
        if (! Schema::hasTable('assistant_documents')) {
            Schema::create('assistant_documents', function (Blueprint $table): void {
                $table->string('assistant_document_id', 191)->primary();
                $table->string('dataset_id', 191);
                $table->string('display_name')->nullable();
                $table->string('source_type', 64)->default('upload');
                $table->text('source_url')->nullable();
                $table->timestamp('source_updated_at')->nullable();
                $table->char('source_checksum_sha256', 64)->nullable();
                $table->boolean('graph_enabled')->default(false);
                $table->string('status', 64)->default('accepted');
                $table->text('last_error')->nullable();
                $table->string('latest_source_id', 191)->nullable();
                $table->string('latest_task_id', 191)->nullable();
                $table->string('latest_job_id', 191)->nullable();
                $table->string('latest_document_version', 191)->nullable();
                $table->timestamp('indexed_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index(['dataset_id', 'status']);
                $table->index('latest_source_id');
                $table->index('latest_task_id');
                $table->index('latest_job_id');
            });
        }

        if (! Schema::hasTable('assistant_document_outputs')) {
            Schema::create('assistant_document_outputs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('assistant_document_id', 191);
                $table->string('bridge_document_id', 191);
                $table->string('qdrant_collection', 191);
                $table->string('neo4j_namespace', 191)->nullable();
                $table->string('source_id', 191)->nullable();
                $table->string('task_id', 191)->nullable();
                $table->string('job_id', 191)->nullable();
                $table->char('content_hash', 64)->nullable();
                $table->unsignedInteger('chunk_count')->default(0);
                $table->string('status', 64)->default('indexed');
                $table->boolean('active')->default(true);
                $table->timestamp('indexed_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->foreign('assistant_document_id')
                    ->references('assistant_document_id')
                    ->on('assistant_documents')
                    ->cascadeOnDelete();

                $table->unique(['assistant_document_id', 'bridge_document_id'], 'assistant_document_outputs_unique');
                $table->index(['assistant_document_id', 'active']);
                $table->index('source_id');
                $table->index('task_id');
                $table->index('job_id');
            });
        }
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    private function rewriteJsonColumn(string $table, string $keyColumn, string $jsonColumn, callable $mutator): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $jsonColumn)) {
            return;
        }

        DB::table($table)
            ->select([$keyColumn, $jsonColumn])
            ->orderBy($keyColumn)
            ->chunk(200, function ($rows) use ($table, $keyColumn, $jsonColumn, $mutator): void {
                foreach ($rows as $row) {
                    $metadata = $this->decodeJsonColumn($row->{$jsonColumn});
                    $normalized = $mutator($metadata);

                    if ($normalized === $metadata) {
                        continue;
                    }

                    DB::table($table)
                        ->where($keyColumn, $row->{$keyColumn})
                        ->update([$jsonColumn => json_encode($normalized, JSON_THROW_ON_ERROR)]);
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonColumn(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function normalizeWorkflowMetadata(array $metadata): array
    {
        $managedDocumentId = $this->managedDocumentIdValue(
            $metadata['managed_document_id'] ?? $metadata['assistant_document_id'] ?? $metadata['document_id'] ?? null,
        );

        if ($managedDocumentId !== null) {
            $metadata['managed_document_id'] = $managedDocumentId;
        }

        if (($metadata['document_id'] ?? null) === $managedDocumentId) {
            unset($metadata['document_id']);
        }

        unset($metadata['assistant_document_id']);

        $request = is_array($metadata['request'] ?? null) ? $metadata['request'] : [];
        $requestMetadata = is_array($request['metadata'] ?? null) ? $request['metadata'] : [];
        $requestManagedDocumentId = $this->managedDocumentIdValue(
            $requestMetadata['managed_document_id'] ?? $requestMetadata['assistant_document_id'] ?? $requestMetadata['document_id'] ?? null,
        );

        if ($requestManagedDocumentId !== null) {
            $requestMetadata['managed_document_id'] = $requestManagedDocumentId;
        }

        if (($requestMetadata['document_id'] ?? null) === $requestManagedDocumentId) {
            unset($requestMetadata['document_id']);
        }

        unset($requestMetadata['assistant_document_id']);

        if ($request !== [] || $requestMetadata !== []) {
            $request['metadata'] = $requestMetadata;
            $metadata['request'] = $request;
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function normalizeIndexedDocumentMetadata(array $metadata): array
    {
        $managedDocumentId = $this->managedDocumentIdValue(
            $metadata['managed_document_id'] ?? $metadata['assistant_document_id'] ?? null,
        );

        if ($managedDocumentId === null) {
            unset($metadata['assistant_document_id']);

            return $metadata;
        }

        $metadata['managed_document_id'] = $managedDocumentId;
        unset($metadata['assistant_document_id']);

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function denormalizeWorkflowMetadata(array $metadata): array
    {
        $managedDocumentId = $this->managedDocumentIdValue($metadata['managed_document_id'] ?? null);
        if ($managedDocumentId !== null) {
            $metadata['document_id'] = $managedDocumentId;
            $metadata['assistant_document_id'] = $managedDocumentId;
        }

        unset($metadata['managed_document_id']);

        $request = is_array($metadata['request'] ?? null) ? $metadata['request'] : [];
        $requestMetadata = is_array($request['metadata'] ?? null) ? $request['metadata'] : [];
        $requestManagedDocumentId = $this->managedDocumentIdValue($requestMetadata['managed_document_id'] ?? null);

        if ($requestManagedDocumentId !== null) {
            $requestMetadata['document_id'] = $requestManagedDocumentId;
            $requestMetadata['assistant_document_id'] = $requestManagedDocumentId;
        }

        unset($requestMetadata['managed_document_id']);

        if ($request !== [] || $requestMetadata !== []) {
            $request['metadata'] = $requestMetadata;
            $metadata['request'] = $request;
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function denormalizeIndexedDocumentMetadata(array $metadata): array
    {
        $managedDocumentId = $this->managedDocumentIdValue($metadata['managed_document_id'] ?? null);
        if ($managedDocumentId !== null) {
            $metadata['assistant_document_id'] = $managedDocumentId;
        }

        unset($metadata['managed_document_id']);

        return $metadata;
    }

    private function managedDocumentIdValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return ManagedDocumentId::looksLike($normalized) ? $normalized : null;
    }
};
