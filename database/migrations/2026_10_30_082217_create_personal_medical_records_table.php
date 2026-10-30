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
        Schema::create('personal_medical_records', function (Blueprint $table) {
            $table->id('personal_medical_record_id');
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->date('visit_date');
            $table->string('facility_name', 255);
            $table->text('complaint');
            $table->text('medications')->nullable();
            $table->string('source', 32)->default('USER_REPORTED');
            $table->timestampsTz();

            $table->index(['user_id', 'visit_date']);
            $table->index('visit_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_medical_records');
    }
};
