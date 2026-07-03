<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorization_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('issuer');
            $table->string('subject');
            $table->string('provider');
            $table->string('external_user_id');
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->json('claims')->nullable();
            $table->timestamps();

            $table->unique(['issuer', 'subject']);
            $table->index(['provider', 'external_user_id']);
        });

        Schema::create('authorization_permission_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('external_user_id')->nullable();
            $table->string('course_id');
            $table->string('role')->nullable();
            $table->string('document_id')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['provider', 'external_user_id']);
            $table->index(['provider', 'course_id']);
            $table->index(['document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_permission_events');
        Schema::dropIfExists('authorization_identities');
    }
};
