<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('phone')->nullable()->change();
            $table->text('nik')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->boolean('email_verification_pending')->default(false);
            $table->string('google_sub')->nullable()->unique();
        });

        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id('verification_code_id');
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('purpose', 32);
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->string('grant_hash')->nullable();
            $table->timestampTz('grant_expires_at')->nullable();
            $table->timestampTz('grant_used_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'purpose', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verification_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_sub']);
            $table->dropColumn(['email_verification_pending', 'google_sub']);
            $table->string('password')->nullable(false)->change();
            $table->string('phone', 20)->nullable()->change();
            $table->string('nik', 16)->nullable()->change();
        });
    }
};
