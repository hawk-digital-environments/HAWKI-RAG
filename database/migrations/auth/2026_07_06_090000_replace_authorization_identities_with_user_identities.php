<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('authorization_identities') || Schema::hasTable('user_identities')) {
            return;
        }

        Schema::create('user_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('issuer');
            $table->string('subject');
            $table->string('provider');
            $table->string('external_user_id');
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->json('claims')->nullable();
            $table->string('tenant_id', 191)->nullable();
            $table->string('application_id', 191)->nullable();
            $table->uuid('internal_user_id')->nullable();
            $table->timestamps();

            $table->unique(['issuer', 'subject']);
            $table->unique(['tenant_id', 'provider', 'external_user_id'], 'user_identities_tenant_provider_external_unique');
            $table->index('tenant_id');
            $table->index('application_id');
            $table->index('internal_user_id');
        });

        $rows = DB::table('authorization_identities')->get();
        foreach ($rows as $row) {
            DB::table('user_identities')->insert([
                'id' => $row->id,
                'user_id' => $row->user_id,
                'issuer' => $row->issuer,
                'subject' => $row->subject,
                'provider' => $row->provider,
                'external_user_id' => $row->external_user_id,
                'email' => $row->email,
                'username' => $row->username,
                'claims' => $row->claims,
                'tenant_id' => $row->tenant_id ?? null,
                'application_id' => $row->application_id ?? null,
                'internal_user_id' => $row->internal_user_id ?? null,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('authorization_identities');
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_identities') || Schema::hasTable('authorization_identities')) {
            return;
        }

        Schema::create('authorization_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('issuer');
            $table->string('subject');
            $table->string('provider');
            $table->string('external_user_id');
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->json('claims')->nullable();
            $table->string('tenant_id', 191)->nullable();
            $table->string('application_id', 191)->nullable();
            $table->uuid('internal_user_id')->nullable();
            $table->timestamps();

            $table->unique(['issuer', 'subject']);
            $table->index(['provider', 'external_user_id']);
            $table->index('tenant_id');
            $table->index('application_id');
            $table->index('internal_user_id');
        });

        $rows = DB::table('user_identities')->get();
        foreach ($rows as $row) {
            DB::table('authorization_identities')->insert([
                'id' => $row->id,
                'user_id' => $row->user_id,
                'issuer' => $row->issuer,
                'subject' => $row->subject,
                'provider' => $row->provider,
                'external_user_id' => $row->external_user_id,
                'email' => $row->email,
                'username' => $row->username,
                'claims' => $row->claims,
                'tenant_id' => $row->tenant_id ?? null,
                'application_id' => $row->application_id ?? null,
                'internal_user_id' => $row->internal_user_id ?? null,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('user_identities');
    }
};
