<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Izinkan kolom name pada tabel users bernilai null agar registrasi
     * dapat dilakukan tanpa nama; nama diisi melalui layar Complete Profile.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Pulihkan NOT NULL hanya bila tidak ada baris dengan name IS NULL.
            $table->string('name')->nullable(false)->change();
        });
    }
};
