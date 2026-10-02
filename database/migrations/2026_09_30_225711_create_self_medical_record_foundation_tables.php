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
        Schema::create('roles', function (Blueprint $table) {
            $table->id('role_id');
            $table->string('role_name')->unique();
            $table->timestampsTz();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('id', 'user_id');
            $table->foreignId('role_id')->constrained('roles', 'role_id');
            $table->string('phone', 20)->nullable();
            $table->string('nik', 16)->nullable();
        });

        Schema::create('health_records', function (Blueprint $table) {
            $table->id('health_record_id');
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->decimal('height', 5, 2)->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->unsignedSmallInteger('systolic')->nullable();
            $table->unsignedSmallInteger('diastolic')->nullable();
            $table->decimal('bmi', 5, 2)->nullable();
            $table->string('warning_status')->nullable();
            $table->text('warning_message')->nullable();
            $table->timestampTz('recorded_at');
            $table->timestampsTz();
        });

        Schema::create('photos', function (Blueprint $table) {
            $table->id('photo_id');
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('photo_type');
            $table->string('file_url');
            $table->timestampTz('uploaded_at');
            $table->string('status')->default('PENDING');
            $table->timestampsTz();
        });

        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id('log_id');
            $table->foreignId('user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('operation');
            $table->string('status');
            $table->jsonb('request_data')->nullable();
            $table->jsonb('response_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('attempted_at');
            $table->timestampsTz();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id('activity_id');
            $table->foreignId('user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('action');
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('openmrs_configs', function (Blueprint $table) {
            $table->id('config_id');
            $table->string('endpoint_url');
            $table->text('credential');
            $table->boolean('is_active')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('openmrs_configs');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('photos');
        Schema::dropIfExists('health_records');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn(['role_id', 'phone', 'nik']);
            $table->renameColumn('user_id', 'id');
        });

        Schema::dropIfExists('roles');
    }
};
