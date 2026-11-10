<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah indeks pada tabel health_records untuk meningkatkan performa query.
     *
     * Semua query pada tabel ini memfilter berdasarkan user_id, mengurutkan
     * berdasarkan recorded_at, dan sebagian memfilter berdasarkan warning_status.
     * Tanpa indeks, setiap query akan melakukan full table scan.
     */
    public function up(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            // Indeks tunggal user_id: digunakan oleh semua query ownership
            $table->index('user_id', 'health_records_user_id_index');

            // Indeks komposit (user_id, recorded_at): digunakan oleh dashboard trend
            // dan filter riwayat yang paling sering dipakai
            $table->index(['user_id', 'recorded_at'], 'health_records_user_id_recorded_at_index');

            // Indeks warning_status: digunakan oleh filter GET /api/health-records
            // dan query dashboard latest_warning
            $table->index('warning_status', 'health_records_warning_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            $table->dropIndex('health_records_user_id_index');
            $table->dropIndex('health_records_user_id_recorded_at_index');
            $table->dropIndex('health_records_warning_status_index');
        });
    }
};
