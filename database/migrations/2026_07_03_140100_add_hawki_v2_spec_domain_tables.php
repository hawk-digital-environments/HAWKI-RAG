<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table): void {
                $table->string('id', 191)->primary();
                $table->string('name');
                $table->json('metadata_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('applications')) {
            Schema::create('applications', function (Blueprint $table): void {
                $table->string('id', 191)->primary();
                $table->string('tenant_id', 191);
                $table->string('name');
                $table->text('description')->nullable();
                $table->json('permissions');
                $table->string('token_hash', 191)->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index('tenant_id');
            });
        }

        if (! Schema::hasTable('corpora')) {
            Schema::create('corpora', function (Blueprint $table): void {
                $table->char('id', 64)->primary();
                $table->longText('content')->nullable();
                $table->unsignedInteger('reference_count')->default(0);
                $table->json('metadata_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('groups')) {
            Schema::create('groups', function (Blueprint $table): void {
                $table->string('id', 191)->primary();
                $table->string('tenant_id', 191);
                $table->string('owner_application_id', 191);
                $table->string('name');
                $table->text('description')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index('tenant_id');
                $table->index('owner_application_id');
            });
        }

        if (! Schema::hasTable('group_members')) {
            Schema::create('group_members', function (Blueprint $table): void {
                $table->id();
                $table->string('group_id', 191);
                $table->string('user_identifier');
                $table->timestamps();

                $table->unique(['group_id', 'user_identifier']);
                $table->index('group_id');
            });
        }

        if (Schema::hasTable('datasets')) {
            Schema::table('datasets', function (Blueprint $table): void {
                if (! Schema::hasColumn('datasets', 'tenant_id')) {
                    $table->string('tenant_id', 191)->nullable()->after('dataset_id');
                    $table->index('tenant_id');
                }

                if (! Schema::hasColumn('datasets', 'owner_application_id')) {
                    $table->string('owner_application_id', 191)->nullable()->after('tenant_id');
                    $table->index('owner_application_id');
                }

                if (! Schema::hasColumn('datasets', 'visibility')) {
                    $table->string('visibility', 32)->default('discoverable')->after('status');
                    $table->index('visibility');
                }

                if (! Schema::hasColumn('datasets', 'protected')) {
                    $table->boolean('protected')->default(false)->after('visibility');
                    $table->index('protected');
                }

                if (! Schema::hasColumn('datasets', 'metadata_json')) {
                    $table->json('metadata_json')->nullable()->after('protected');
                }

                if (! Schema::hasColumn('datasets', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable()->after('created_at');
                }
            });
        }

        if (Schema::hasTable('documents')) {
            Schema::table('documents', function (Blueprint $table): void {
                if (! Schema::hasColumn('documents', 'corpus_id')) {
                    $table->char('corpus_id', 64)->nullable()->after('dataset_id');
                    $table->index('corpus_id');
                }
            });
        }

        $now = now();

        DB::table('tenants')->updateOrInsert(
            ['id' => 'default'],
            [
                'name' => 'Default Tenant',
                'metadata_json' => json_encode(['source' => 'rawki-v2-bootstrap'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('applications')->updateOrInsert(
            ['id' => 'rawki-default'],
            [
                'tenant_id' => 'default',
                'name' => 'RAWKI Default',
                'description' => 'Bootstrap application for dataset-backed heaps.',
                'permissions' => json_encode(['reads'], JSON_THROW_ON_ERROR),
                'token_hash' => null,
                'metadata_json' => json_encode(['source' => 'rawki-v2-bootstrap'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        if (Schema::hasTable('datasets')) {
            DB::table('datasets')
                ->whereNull('tenant_id')
                ->update(['tenant_id' => 'default']);

            DB::table('datasets')
                ->whereNull('owner_application_id')
                ->update(['owner_application_id' => 'rawki-default']);

            DB::table('datasets')
                ->whereNull('updated_at')
                ->update(['updated_at' => DB::raw('created_at')]);
        }

        if (Schema::hasTable('documents')) {
            $rows = DB::table('documents')
                ->selectRaw('checksum_sha256, COUNT(*) as total, MIN(created_at) as first_created_at')
                ->whereNotNull('checksum_sha256')
                ->groupBy('checksum_sha256')
                ->get();

            foreach ($rows as $row) {
                DB::table('corpora')->updateOrInsert(
                    ['id' => (string) $row->checksum_sha256],
                    [
                        'content' => null,
                        'reference_count' => (int) $row->total,
                        'metadata_json' => json_encode(['backfilled' => true], JSON_THROW_ON_ERROR),
                        'created_at' => $row->first_created_at ?? $now,
                        'updated_at' => $now,
                    ],
                );
            }

            DB::table('documents')
                ->whereNull('corpus_id')
                ->whereNotNull('checksum_sha256')
                ->update(['corpus_id' => DB::raw('checksum_sha256')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('documents') && Schema::hasColumn('documents', 'corpus_id')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->dropIndex(['corpus_id']);
                $table->dropColumn('corpus_id');
            });
        }

        if (Schema::hasTable('datasets')) {
            Schema::table('datasets', function (Blueprint $table): void {
                if (Schema::hasColumn('datasets', 'tenant_id')) {
                    $table->dropIndex(['tenant_id']);
                    $table->dropColumn('tenant_id');
                }

                if (Schema::hasColumn('datasets', 'owner_application_id')) {
                    $table->dropIndex(['owner_application_id']);
                    $table->dropColumn('owner_application_id');
                }

                if (Schema::hasColumn('datasets', 'visibility')) {
                    $table->dropIndex(['visibility']);
                    $table->dropColumn('visibility');
                }

                if (Schema::hasColumn('datasets', 'protected')) {
                    $table->dropIndex(['protected']);
                    $table->dropColumn('protected');
                }

                if (Schema::hasColumn('datasets', 'metadata_json')) {
                    $table->dropColumn('metadata_json');
                }

                if (Schema::hasColumn('datasets', 'updated_at')) {
                    $table->dropColumn('updated_at');
                }
            });
        }

        Schema::dropIfExists('group_members');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('corpora');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('tenants');
    }
};
