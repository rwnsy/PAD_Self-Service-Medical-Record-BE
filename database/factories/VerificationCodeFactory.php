<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Role;
use App\Models\VerificationCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<VerificationCode>
 */
class VerificationCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->for(Role::factory(), 'role'),
            'purpose' => VerificationCode::EmailVerification,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ];
    }
}
