<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('heap_grants', function (Blueprint $table): void {
            $table->string('group_id', 191)->nullable()->change();
            $table->string('user_identifier', 255)->nullable()->after('group_id');
            $table->uuid('internal_user_id')->nullable()->after('user_identifier');
            $table->unique(['heap_id', 'user_identifier'], 'heap_grants_heap_user_identifier_unique');
            $table->index('internal_user_id', 'heap_grants_internal_user_id_index');
        });

        Schema::table('document_grants', function (Blueprint $table): void {
            $table->string('group_id', 191)->nullable()->change();
            $table->string('user_identifier', 255)->nullable()->after('group_id');
            $table->uuid('internal_user_id')->nullable()->after('user_identifier');
            $table->unique(['document_id', 'user_identifier'], 'document_grants_document_user_identifier_unique');
            $table->index('internal_user_id', 'document_grants_internal_user_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('document_grants', function (Blueprint $table): void {
            $table->dropUnique('document_grants_document_user_identifier_unique');
            $table->dropIndex('document_grants_internal_user_id_index');
            $table->dropColumn(['user_identifier', 'internal_user_id']);
            $table->string('group_id', 191)->nullable(false)->change();
        });

        Schema::table('heap_grants', function (Blueprint $table): void {
            $table->dropUnique('heap_grants_heap_user_identifier_unique');
            $table->dropIndex('heap_grants_internal_user_id_index');
            $table->dropColumn(['user_identifier', 'internal_user_id']);
            $table->string('group_id', 191)->nullable(false)->change();
        });
    }
};
