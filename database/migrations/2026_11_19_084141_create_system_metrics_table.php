<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('component', 50); // 'application', 'database', 'openmrs'
            $table->string('status', 20); // 'HEALTHY', 'DEGRADED', 'DOWN', 'UNKNOWN'
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('checked_at');
            $table->timestamps();

            $table->index(['component', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_metrics');
    }
};
