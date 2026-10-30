<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom bmi_category ke tabel health_records.
     *
     * Kolom ini menyimpan kategori BMI yang dihitung dari nilai full-precision
     * sebelum pembulatan, sehingga menghindari kesalahan klasifikasi di nilai
     * batas ambang (misalnya: BMI 24.995 yang rounded menjadi 25.00 tetap
     * dikategorikan IDEAL, bukan BERAT_BERLEBIH).
     *
     * Record lama (bmi_category = null) akan tetap dapat dibaca; accessor
     * bmi_message mengembalikan null untuk bmi_category yang null.
     */
    public function up(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            $table->string('bmi_category', 24)->nullable()->after('bmi');
        });
    }

    public function down(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            $table->dropColumn('bmi_category');
        });
    }
};
