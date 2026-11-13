<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->index('user_id', 'activity_logs_user_id_index');
            $table->index('created_at', 'activity_logs_created_at_index');
            $table->index('action', 'activity_logs_action_index');
        });

        Schema::table('integration_logs', function (Blueprint $table): void {
            $table->index('user_id', 'integration_logs_user_id_index');
            $table->index('status', 'integration_logs_status_index');
            $table->index('attempted_at', 'integration_logs_attempted_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex('activity_logs_user_id_index');
            $table->dropIndex('activity_logs_created_at_index');
            $table->dropIndex('activity_logs_action_index');
        });

        Schema::table('integration_logs', function (Blueprint $table): void {
            $table->dropIndex('integration_logs_user_id_index');
            $table->dropIndex('integration_logs_status_index');
            $table->dropIndex('integration_logs_attempted_at_index');
        });
    }
};
