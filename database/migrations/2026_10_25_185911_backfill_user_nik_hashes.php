<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        User::query()
            ->whereNotNull('nik')
            ->whereNull('nik_hash')
            ->lazyById(100, 'user_id')
            ->each(function (User $user): void {
                $nik = $user->nik;

                if (! is_string($nik) || $nik === '') {
                    return;
                }

                $nikHash = User::hashNik($nik);
                $alreadyAssigned = User::query()->where('nik_hash', $nikHash)->exists();

                if ($alreadyAssigned) {
                    return;
                }

                $user->forceFill(['nik_hash' => $nikHash])->saveQuietly();
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
