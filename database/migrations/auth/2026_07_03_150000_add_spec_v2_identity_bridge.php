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
        if (! Schema::hasTable('internal_users')) {
            Schema::create('internal_users', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('tenant_id', 191);
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index('tenant_id');
            });
        }

        if (Schema::hasTable('authorization_identities')) {
            Schema::table('authorization_identities', function (Blueprint $table): void {
                if (! Schema::hasColumn('authorization_identities', 'tenant_id')) {
                    $table->string('tenant_id', 191)->nullable()->after('claims');
                    $table->index('tenant_id');
                }

                if (! Schema::hasColumn('authorization_identities', 'application_id')) {
                    $table->string('application_id', 191)->nullable()->after('tenant_id');
                    $table->index('application_id');
                }

                if (! Schema::hasColumn('authorization_identities', 'internal_user_id')) {
                    $table->uuid('internal_user_id')->nullable()->after('application_id');
                    $table->index('internal_user_id');
                }
            });
        }

        if (Schema::hasTable('group_members')) {
            Schema::table('group_members', function (Blueprint $table): void {
                if (! Schema::hasColumn('group_members', 'internal_user_id')) {
                    $table->uuid('internal_user_id')->nullable()->after('user_identifier');
                    $table->index('internal_user_id');
                }
            });
        }

        if (Schema::hasTable('authorization_identities')) {
            $now = now();

            DB::table('authorization_identities')
                ->whereNull('tenant_id')
                ->update(['tenant_id' => 'default']);

            DB::table('authorization_identities')
                ->whereNull('application_id')
                ->update(['application_id' => 'rawki-default']);

            $identities = DB::table('authorization_identities')
                ->select('id', 'tenant_id')
                ->whereNull('internal_user_id')
                ->get();

            foreach ($identities as $identity) {
                $internalUserId = (string) Str::uuid();

                DB::table('internal_users')->insert([
                    'id' => $internalUserId,
                    'tenant_id' => $identity->tenant_id ?? 'default',
                    'metadata_json' => json_encode([
                        'source' => 'authorization-backfill',
                        'authorization_identity_id' => $identity->id,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('authorization_identities')
                    ->where('id', $identity->id)
                    ->update(['internal_user_id' => $internalUserId]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('group_members') && Schema::hasColumn('group_members', 'internal_user_id')) {
            Schema::table('group_members', function (Blueprint $table): void {
                $table->dropIndex(['internal_user_id']);
                $table->dropColumn('internal_user_id');
            });
        }

        if (Schema::hasTable('authorization_identities')) {
            Schema::table('authorization_identities', function (Blueprint $table): void {
                if (Schema::hasColumn('authorization_identities', 'tenant_id')) {
                    $table->dropIndex(['tenant_id']);
                    $table->dropColumn('tenant_id');
                }

                if (Schema::hasColumn('authorization_identities', 'application_id')) {
                    $table->dropIndex(['application_id']);
                    $table->dropColumn('application_id');
                }

                if (Schema::hasColumn('authorization_identities', 'internal_user_id')) {
                    $table->dropIndex(['internal_user_id']);
                    $table->dropColumn('internal_user_id');
                }
            });
        }

        Schema::dropIfExists('internal_users');
    }
};
